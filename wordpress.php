<?php
/**
 * WordPress REST API 클라이언트
 * ✅ 표준 WordPress: /wp-json/wp/v2/...
 * ✅ LightCMS 호환: api.php?route=... (api_path 설정 시)
 */

require_once __DIR__ . '/config.php';

class WordPressAPI {
    private $base;
    private $auth;
    private $authToken;
    private $siteName;
    private $queryMode = false;

    public function __construct($siteConfig) {
        $siteUrl = rtrim($siteConfig['site_url'], '/');
        
        // api_path 설정 시 직접 API 호출 모드 (Cloudways 등 Nginx 호스팅 대응)
        if (!empty($siteConfig['api_path'])) {
            $this->base = $siteUrl . '/' . ltrim($siteConfig['api_path'], '/');
            $this->queryMode = true;
        } else {
            $this->base = $siteUrl . '/wp-json/wp/v2';
            $this->queryMode = false;
        }
        
        $this->authToken = base64_encode($siteConfig['username'] . ':' . $siteConfig['app_password']);
        $this->auth = 'Basic ' . $this->authToken;
        $this->siteName = $siteConfig['name'] ?? 'default';
    }

    /**
     * URL 빌드 (표준 WP 경로 / 쿼리 모드 자동 분기)
     * 예: /posts → wp-json/wp/v2/posts 또는 api.php?route=posts
     * 예: /tags?search=AI → wp-json/wp/v2/tags?search=AI 또는 api.php?route=tags&search=AI
     */
    private function buildUrl($ep) {
        if (!$this->queryMode) {
            return $this->base . $ep;
        }
        // 쿼리 모드: /endpoint?param=val → api.php?route=endpoint&param=val
        $ep = ltrim($ep, '/');
        $qPos = strpos($ep, '?');
        if ($qPos !== false) {
            $path = substr($ep, 0, $qPos);
            $qs = substr($ep, $qPos + 1);
            $url = $this->base . '?route=' . urlencode($path) . '&' . $qs;
        } else {
            $url = $this->base . '?route=' . urlencode($ep);
        }
        // ★ 닷홈 등 Authorization 헤더 차단 호스팅 대응: URL 토큰 폴백
        $url .= '&_token=' . urlencode($this->authToken);
        return $url;
    }

    private function req($ep, $method = 'GET', $data = null, $hdrs = []) {
        $url = $this->buildUrl($ep);
        $ch = curl_init($url);
        $h = ['Authorization: ' . $this->auth];
        if ($method !== 'GET' && !$hdrs) $h[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>array_merge($h,$hdrs), CURLOPT_TIMEOUT=>60]);
        if ($data !== null && $method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $res = json_decode($r, true);
        if ($code >= 400) { write_log("[{$this->siteName}] WP {$code}: " . ($res['message'] ?? mb_substr($r, 0, 200))); return null; }
        return $res;
    }

    public function uploadImage($path, $alt = '') {
        if (!file_exists($path)) {
            write_log("[{$this->siteName}] ❌ 이미지 파일 없음: {$path}");
            return null;
        }
        $fileSize = filesize($path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        write_log("[{$this->siteName}] 📷 이미지 업로드: " . basename($path) . " ({$mimeType}, " . round($fileSize/1024) . "KB)");
        
        $url = $this->buildUrl('/media');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>120,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $this->auth, 'Content-Type: ' . $mimeType, 'Content-Disposition: attachment; filename="' . basename($path) . '"'],
            CURLOPT_POSTFIELDS => file_get_contents($path)]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        
        if ($curlErr) {
            write_log("[{$this->siteName}] ❌ 이미지 curl 에러: {$curlErr}");
            return null;
        }
        if ($code >= 400) {
            write_log("[{$this->siteName}] ❌ 이미지 업로드 실패 (HTTP {$code}): " . mb_substr($r, 0, 200));
            return null;
        }
        
        $res = json_decode($r, true);
        if (!$res || !isset($res['id']) || $res['id'] <= 0) {
            write_log("[{$this->siteName}] ❌ 이미지 응답 파싱 실패: " . mb_substr($r, 0, 300));
            return null;
        }
        
        write_log("[{$this->siteName}] ✅ 이미지 업로드: ID={$res['id']} URL=" . ($res['source_url'] ?? ''));
        
        if ($alt && $res['id']) $this->req('/media/' . $res['id'], 'POST', ['alt_text' => $alt]);
        return ['id' => $res['id'], 'url' => $res['source_url'] ?? ''];
    }

    public function getOrCreateTag($name) {
        $ex = $this->req('/tags?' . http_build_query(['search'=>$name, 'per_page'=>5]));
        if ($ex) foreach ($ex as $t) if (mb_strtolower($t['name']) === mb_strtolower($name)) return $t['id'];
        $n = $this->req('/tags', 'POST', ['name' => $name]);
        return $n['id'] ?? null;
    }

    public function resolveTagIds($names) {
        $ids = [];
        foreach ($names as $n) {
            $n = trim($n); if (!$n) continue;
            $id = $this->getOrCreateTag($n);
            if ($id) $ids[] = $id;
            usleep(200000);
        }
        return $ids;
    }

    public function replaceImages($content, $paths) {
        foreach ($paths as $p) {
            if (!file_exists($p)) continue;
            $u = $this->uploadImage($p);
            if ($u && $u['url']) $content = str_replace($p, $u['url'], $content);
        }
        return $content;
    }

    /**
     * 카테고리 ID 조회 (이름으로)
     */
    public function getCategoryId($name) {
        if (!$name) return 0;
        $ex = $this->req('/categories?' . http_build_query(['search'=>$name, 'per_page'=>5]));
        if ($ex) foreach ($ex as $c) if (mb_strtolower($c['name']) === mb_strtolower($name)) return $c['id'];
        // 없으면 생성
        $n = $this->req('/categories', 'POST', ['name' => $name]);
        return $n['id'] ?? 0;
    }

    /**
     * 카테고리 목록 가져오기
     */
    public function getCategories() {
        return $this->req('/categories?' . http_build_query(['per_page' => 100])) ?? [];
    }

    /**
     * ★ 중복 체크: 같은 제목의 글이 최근에 이미 발행되었는지 확인
     * @return array|null 중복이면 기존 글 정보, 없으면 null
     */
    public function checkDuplicate($title) {
        if (!$title) return null;
        $normNew = preg_replace('/\s+/', ' ', trim($title));
        
        try {
            // 방법 1: search 파라미터로 검색 (표준 WP)
            $results = $this->req('/posts?' . http_build_query([
                'search' => mb_substr($title, 0, 50),
                'per_page' => 5,
                'orderby' => 'date',
                'order' => 'desc',
                'status' => 'publish',
                '_fields' => 'id,title,date,link,slug'
            ]));
            
            // 방법 2: search가 안 먹히면 (자체 CMS 등) 최근 글 30개에서 직접 비교
            if (!$results || !is_array($results) || empty($results)) {
                $results = $this->req('/posts?' . http_build_query([
                    'per_page' => 30,
                    'orderby' => 'date',
                    'order' => 'desc',
                    '_fields' => 'id,title,date,link,slug'
                ]));
            }
            
            if (!$results || !is_array($results)) return null;
            
            foreach ($results as $p) {
                $existTitle = is_array($p['title'] ?? null) 
                    ? ($p['title']['rendered'] ?? '') 
                    : ($p['title'] ?? '');
                $existTitle = strip_tags($existTitle);
                $normExist = preg_replace('/\s+/', ' ', trim(html_entity_decode($existTitle)));
                
                if (mb_strtolower($normExist) === mb_strtolower($normNew)) {
                    $postDate = strtotime($p['date'] ?? '');
                    if ($postDate && (time() - $postDate) < 86400) {
                        write_log("[{$this->siteName}] 🔍 중복 발견: [{$p['id']}] {$existTitle}");
                        return [
                            'id' => $p['id'],
                            'link' => $p['link'] ?? ($p['url'] ?? ''),
                            'slug' => $p['slug'] ?? '',
                            '_duplicate' => true,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            write_log("[{$this->siteName}] 중복 체크 오류 (무시): " . $e->getMessage());
        }
        return null;
    }

    public function createPost($data) {
        // ★ FIX: 발행 전 중복 체크 — 같은 제목의 글이 최근 24시간 내 이미 있으면 스킵
        $dupCheck = $this->checkDuplicate($data['title']);
        if ($dupCheck) {
            write_log("[{$this->siteName}] ⚠️ 중복 감지! 이미 발행된 글 → 스킵: [{$dupCheck['id']}] {$data['title']}");
            // 중복이지만 발행 "성공"으로 처리 (이미 올라간 글의 정보 반환)
            return $dupCheck;
        }

        $post = [
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => 'publish',
            'excerpt' => $data['excerpt'] ?? '',
            'tags' => $data['tags'] ?? [],
            'featured_media' => $data['featured_media'] ?? 0,
        ];
        // 영문 slug
        if (!empty($data['slug'])) {
            $post['slug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['slug']));
        }
        // 카테고리 설정 (미지정 시 첫 번째 카테고리 자동 배정)
        if (!empty($data['categories'])) {
            $post['categories'] = $data['categories'];
        } else {
            // 카테고리 미지정 → 사이트의 첫 번째 카테고리로 자동 배정
            $fallbackCats = $this->getCategories();
            if (!empty($fallbackCats)) {
                // "미분류(Uncategorized)" 외 카테고리가 있으면 우선 사용
                $bestCat = null;
                foreach ($fallbackCats as $fc) {
                    $fcName = mb_strtolower($fc['name'] ?? '');
                    if ($fcName !== 'uncategorized' && $fcName !== '미분류') {
                        $bestCat = $fc['id'];
                        break;
                    }
                }
                // 없으면 첫 번째 카테고리 (미분류라도 배정)
                if (!$bestCat) $bestCat = $fallbackCats[0]['id'] ?? 1;
                $post['categories'] = [$bestCat];
                write_log("[{$this->siteName}] 📂 카테고리 미지정 → 자동 배정: ID={$bestCat}");
            } else {
                $post['categories'] = [1]; // 최후 폴백: WordPress 기본 카테고리
                write_log("[{$this->siteName}] 📂 카테고리 미지정 → 기본값(1) 배정");
            }
        }
        // Yoast SEO + RankMath 메타 (focus_keyphrase + meta_description)
        $meta = [];
        if (!empty($data['meta_description'])) {
            $meta['_yoast_wpseo_metadesc'] = $data['meta_description'];
            $meta['rank_math_description'] = $data['meta_description'];
        }
        if (!empty($data['focus_keyphrase'])) {
            $meta['_yoast_wpseo_focuskw'] = $data['focus_keyphrase'];
            $meta['rank_math_focus_keyword'] = $data['focus_keyphrase'];
        }
        if (!empty($meta)) {
            $post['meta'] = $meta;
        }

        $r = $this->req('/posts', 'POST', $post);
        if ($r && isset($r['id'])) {
            $slug = $r['slug'] ?? '';
            write_log("[{$this->siteName}] ✅ 포스트 발행: [{$r['id']}] {$data['title']} (slug: {$slug})");
            return $r;
        }
        return null;
    }

    public function test() {
        $r = $this->req('/users/me');
        if ($r && isset($r['id'])) {
            write_log("[{$this->siteName}] WP 연결 OK: {$r['name']} (역할: " . ($r['roles'][0] ?? 'unknown') . ")");
            return true;
        }
        return false;
    }

    /**
     * 게시글 목록 조회 (최신순)
     */
    public function getPosts($perPage = 30, $page = 1) {
        return $this->req('/posts?' . http_build_query([
            'per_page' => $perPage, 'page' => $page,
            'orderby' => 'date', 'order' => 'desc',
            '_fields' => 'id,title,date,link,featured_media,status'
        ])) ?? [];
    }

    /**
     * 특정 게시글 조회
     */
    public function getPost($postId) {
        return $this->req('/posts/' . intval($postId));
    }

    /**
     * 게시글 수정 (content, featured_media 등)
     */
    public function updatePost($postId, $data) {
        $r = $this->req('/posts/' . intval($postId), 'POST', $data);
        if ($r && isset($r['id'])) {
            write_log("[{$this->siteName}] ✅ 게시글 수정: [{$r['id']}] " . ($r['title']['rendered'] ?? ''));
            return $r;
        }
        return null;
    }
}
