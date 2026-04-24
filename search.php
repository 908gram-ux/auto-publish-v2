<?php
/**
 * 웹 검색 (Naver + 본문 크롤링)
 */

require_once __DIR__ . '/config.php';

// Naver 웹 검색 + 본문 크롤링
// ═════════════════════════════════════════

class WebSearcher {

    public function __construct() {
        // Naver 검색만 사용
    }

    /**
     * 네이버 통합 검색 (본문 크롤링 포함)
     */
    public function searchAll($keyword) {
        $results = [];

        // 네이버 검색 (블로그7 + 뉴스5 + 웹5 = 17건) — enabled 체크
        $naverOn = getKey('naver.enabled', true);
        if ($naverOn !== false && $naverOn !== 0) {
            $naverResults = $this->searchNaver($keyword);
            $results = array_merge($results, $naverResults);
        } else {
            write_log("네이버 검색 비활성화 → 스킵");
        }

        // 상위 5개 본문 크롤링 (URL 있는 것만)
        $crawled = 0;
        foreach ($results as &$item) {
            if ($crawled >= 5) break;
            if (empty($item['url'])) continue;
            $body = $this->crawlContent($item['url']);
            if ($body) {
                $item['body'] = $body;
                $crawled++;
            }
            usleep(300000);
        }
        unset($item);

        $total = count($results);
        $withBody = count(array_filter($results, fn($r) => !empty($r['body'])));
        write_log("웹 검색: {$keyword} → {$total}건 (본문 크롤링: {$withBody}건)");
        return $results;
    }

    /**
     * 네이버 API 검색 (블로그7+뉴스5+웹5=17건)
     */
    private function searchNaver($keyword) {
        $clientId = getKey('naver.client_id');
        $clientSecret = getKey('naver.client_secret');
        if (!$clientId || !$clientSecret) return [];

        $results = [];
        foreach (['blog.json' => 7, 'news.json' => 5, 'webkr.json' => 5] as $ep => $cnt) {
            $url = "https://openapi.naver.com/v1/search/{$ep}?" . http_build_query(['query'=>$keyword,'display'=>$cnt,'sort'=>'sim']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'X-Naver-Client-Id: ' . $clientId,
                    'X-Naver-Client-Secret: ' . $clientSecret,
                ],
            ]);
            $resp = curl_exec($ch); curl_close($ch);
            $data = json_decode($resp, true);
            if (!empty($data['items'])) {
                $source = str_replace('.json', '', $ep);
                foreach ($data['items'] as $item) {
                    $results[] = [
                        'title' => html_entity_decode(strip_tags($item['title']??''), ENT_QUOTES, 'UTF-8'),
                        'description' => html_entity_decode(strip_tags($item['description']??''), ENT_QUOTES, 'UTF-8'),
                        'url' => $item['link'] ?? $item['originallink'] ?? '',
                        'source' => 'naver_' . $source,
                    ];
                }
            }
        }
        write_log("Naver 검색: {$keyword} → " . count($results) . "건");
        return $results;
    }

    /**
     * URL에서 본문 텍스트 크롤링 (500자 제한)
     */
    private function crawlContent($url) {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return '';

        if (strpos($url, 'blog.naver.com') !== false) {
            $url = str_replace('blog.naver.com', 'm.blog.naver.com', $url);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$html) return '';

        $html = preg_replace('#<(script|style|nav|header|footer|aside|iframe)[^>]*>.*?</\1>#si', '', $html);
        $html = preg_replace('#<(meta|link|input|button|form)[^>]*/?>#si', '', $html);

        $body = '';
        if (preg_match('#<(article|main|div[^>]*class="[^"]*(?:content|entry|post|article|body|text)[^"]*")[^>]*>(.*?)</\1>#si', $html, $m)) {
            $body = $m[2];
        }
        if (!$body) {
            preg_match_all('#<p[^>]*>(.*?)</p>#si', $html, $pMatches);
            if (!empty($pMatches[1])) $body = implode(' ', $pMatches[1]);
        }
        if (!$body) $body = $html;

        $text = strip_tags($body);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        if (mb_strlen($text) < 50) return '';
        return mb_substr($text, 0, 500);
    }

    public function test() {
        return !empty($this->searchNaver("테스트"));
    }

    /**
     * ★ v6: 네이버 이미지 수집 (뉴스 제외)
     * 네이버 이미지 검색 API + 크롤링 페이지에서 이미지 URL 수집
     * @param string $keyword 검색어
     * @param int $count 수집할 이미지 수 (2~5)
     * @return array ['url'=>이미지URL, 'source'=>출처] 배열
     */
    public function searchNaverImages($keyword, $count = 5) {
        $clientId = getKey('naver.client_id');
        $clientSecret = getKey('naver.client_secret');
        if (!$clientId || !$clientSecret) {
            write_log("⚠️ 네이버 API 키 없음 → 이미지 수집 불가");
            return [];
        }

        $collected = [];
        $currentYear = date('Y');

        // 뉴스 도메인 필터 목록
        $newsDomains = [
            'news.naver.com','n.news.naver.com','news.joins.com','news.chosun.com',
            'news.donga.com','news.hankyung.com','news.mt.co.kr','news.sbs.co.kr',
            'news.kbs.co.kr','news.mbc.co.kr','news.jtbc.co.kr','newsis.com',
            'yonhapnews.co.kr','yna.co.kr','edaily.co.kr','mk.co.kr','sedaily.com',
            'hani.co.kr','khan.co.kr','ohmynews.com','nocutnews.co.kr',
            'imgnews.naver.net','mimgnews.naver.net',
        ];

        // ★ v7: 뉴스 제목 키워드 (이런 단어가 제목에 있으면 뉴스 이미지로 판단)
        $newsTitleKeywords = ['뉴스', '속보', '기사', '보도', '취재', '기자', '언론', '신문', 
            '방송', 'MBC', 'KBS', 'SBS', 'JTBC', 'YTN', '연합뉴스', '한겨레', '조선일보', 
            '중앙일보', '동아일보', '매일경제', '한국경제'];

        // ★ v7: 블로그 이미지 CDN 도메인 (이 도메인의 이미지만 허용)
        $blogImageDomains = [
            'blogpfx.naver.net', 'postfiles.naver.net', 'mblogthumb-phinf.pstatic.net',
            'blog.kakaocdn.net', 'img1.daumcdn.net', 'k.kakaocdn.net',
            't1.daumcdn.net', 'blog.naver.com', 'm.blog.naver.com',
            'phinf.pstatic.net', 'storep-phinf.pstatic.net',
        ];

        // ═══ 1차: 네이버 블로그 검색 → 블로그 글에서 이미지 직접 추출 ═══
        // (이미지 검색 API 대신 블로그 검색을 먼저 — 블로그 이미지가 주제와 훨씬 맞음)
        write_log("🖼️ 네이버 블로그에서 이미지 수집 중: {$keyword}");
        $blogImages = $this->extractBlogImages($keyword, $count + 2, $newsDomains, $newsTitleKeywords);
        $collected = array_merge($collected, $blogImages);

        // ═══ 2차: 부족하면 이미지 검색 API (블로그 CDN 이미지만 필터) ═══
        if (count($collected) < $count) {
            $needed = $count - count($collected);
            write_log("🖼️ 블로그 이미지 부족({$needed}개 더 필요) → 이미지 API 보충");

            $url = "https://openapi.naver.com/v1/search/image?" . http_build_query([
                'query' => $keyword . ' ' . $currentYear,
                'display' => min(30, $needed * 6),
                'sort' => 'date',
                'filter' => 'large',
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'X-Naver-Client-Id: ' . $clientId,
                    'X-Naver-Client-Secret: ' . $clientSecret,
                ],
            ]);
            $resp = curl_exec($ch); curl_close($ch);
            $data = json_decode($resp, true);

            foreach ($data['items'] ?? [] as $item) {
                if (count($collected) >= $count) break;
                $imgUrl = $item['link'] ?? '';
                if (!$imgUrl) continue;

                // ★ 블로그 CDN 이미지만 허용
                $isBlogImg = false;
                foreach ($blogImageDomains as $bd) {
                    if (stripos($imgUrl, $bd) !== false) { $isBlogImg = true; break; }
                }
                if (!$isBlogImg) continue;

                // 뉴스 제목 필터
                $title = strip_tags($item['title'] ?? '');
                $isNewsy = false;
                foreach ($newsTitleKeywords as $nk) {
                    if (mb_stripos($title, $nk) !== false) { $isNewsy = true; break; }
                }
                if ($isNewsy) continue;

                // 크기 필터
                $w = intval($item['sizewidth'] ?? 0);
                $h = intval($item['sizeheight'] ?? 0);
                if ($w > 0 && $w < 300) continue;
                if ($h > 0 && $h < 200) continue;

                // 중복 URL 체크
                $isDup = false;
                foreach ($collected as $c) {
                    if ($c['url'] === $imgUrl) { $isDup = true; break; }
                }
                if ($isDup) continue;

                $collected[] = [
                    'url' => $imgUrl,
                    'source' => 'naver_image_api_blog',
                    'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                    'width' => $w,
                    'height' => $h,
                ];
            }
        }

        $collected = array_slice($collected, 0, $count);
        write_log("🖼️ 네이버 이미지 수집 완료: {$keyword} → " . count($collected) . "개 (블로그 우선)");
        return $collected;
    }

    /**
     * ★ v7: 네이버 블로그 검색 → 블로그 글에서 직접 이미지 추출
     * 이미지 검색 API 대신 블로그 글을 방문해서 이미지를 가져오므로
     * 블로그 글 내용과 이미지가 실제로 매칭됨
     */
    private function extractBlogImages($keyword, $needed, $newsDomains, $newsTitleKeywords) {
        $clientId = getKey('naver.client_id');
        $clientSecret = getKey('naver.client_secret');
        if (!$clientId || !$clientSecret) return [];

        $collected = [];

        // 네이버 블로그 검색 (최신순)
        $url = "https://openapi.naver.com/v1/search/blog.json?" . http_build_query([
            'query' => $keyword,
            'display' => 10,
            'sort' => 'date', // 최신순
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'X-Naver-Client-Id: ' . $clientId,
                'X-Naver-Client-Secret: ' . $clientSecret,
            ],
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = json_decode($resp, true);

        foreach ($data['items'] ?? [] as $item) {
            if (count($collected) >= $needed) break;

            $pageUrl = $item['link'] ?? '';
            $title = strip_tags($item['title'] ?? '');
            if (!$pageUrl) continue;

            // 뉴스 도메인 건너뛰기
            $isNews = false;
            foreach ($newsDomains as $nd) {
                if (stripos($pageUrl, $nd) !== false) { $isNews = true; break; }
            }
            if ($isNews) continue;

            // 뉴스 제목 키워드 건너뛰기
            foreach ($newsTitleKeywords as $nk) {
                if (mb_stripos($title, $nk) !== false) { $isNews = true; break; }
            }
            if ($isNews) continue;

            // 블로그 페이지에서 이미지 추출
            $imgs = $this->scrapeImagesFromPage($pageUrl);
            foreach ($imgs as $imgUrl) {
                if (count($collected) >= $needed) break;

                // 중복 URL 체크
                $isDup = false;
                foreach ($collected as $c) {
                    if ($c['url'] === $imgUrl) { $isDup = true; break; }
                }
                if (!$isDup) {
                    $collected[] = [
                        'url' => $imgUrl,
                        'source' => 'naver_blog_crawl',
                        'title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                        'width' => 0,  // 크롤링이라 크기 모름
                        'height' => 0,
                    ];
                }
            }
            usleep(300000); // 크롤링 간 0.3초 대기
        }

        return $collected;
    }

    /**
     * ★ v6: 블로그/웹 검색 결과 페이지에서 이미지 URL 추출 (뉴스 제외)
     */
    private function extractImagesFromSearch($keyword, $needed, $newsDomains) {
        $clientId = getKey('naver.client_id');
        $clientSecret = getKey('naver.client_secret');
        if (!$clientId || !$clientSecret) return [];

        $collected = [];

        // 블로그 검색 결과에서 이미지 추출 (뉴스는 건너뜀)
        foreach (['blog.json' => 5, 'webkr.json' => 3] as $ep => $cnt) {
            if (count($collected) >= $needed) break;

            $url = "https://openapi.naver.com/v1/search/{$ep}?" . http_build_query([
                'query' => $keyword, 'display' => $cnt, 'sort' => 'sim',
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'X-Naver-Client-Id: ' . $clientId,
                    'X-Naver-Client-Secret: ' . $clientSecret,
                ],
            ]);
            $resp = curl_exec($ch); curl_close($ch);
            $data = json_decode($resp, true);

            foreach ($data['items'] ?? [] as $item) {
                if (count($collected) >= $needed) break;
                $pageUrl = $item['link'] ?? $item['originallink'] ?? '';
                if (!$pageUrl) continue;

                // 뉴스 도메인 건너뛰기
                $isNews = false;
                foreach ($newsDomains as $nd) {
                    if (stripos($pageUrl, $nd) !== false) { $isNews = true; break; }
                }
                if ($isNews) continue;

                // 페이지에서 이미지 추출
                $imgs = $this->scrapeImagesFromPage($pageUrl);
                foreach ($imgs as $imgUrl) {
                    if (count($collected) >= $needed) break;
                    // 중복 URL 체크
                    $isDup = false;
                    foreach ($collected as $c) {
                        if ($c['url'] === $imgUrl) { $isDup = true; break; }
                    }
                    if (!$isDup) {
                        $collected[] = ['url' => $imgUrl, 'source' => 'naver_crawl', 'title' => ''];
                    }
                }
                usleep(300000); // 크롤링 간 0.3초 대기
            }
        }

        return $collected;
    }

    /**
     * ★ v6: 개별 페이지에서 이미지 URL 스크래핑
     */
    private function scrapeImagesFromPage($url) {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return [];

        // 네이버 블로그는 모바일 버전이 크롤링 용이
        if (strpos($url, 'blog.naver.com') !== false) {
            $url = str_replace('blog.naver.com', 'm.blog.naver.com', $url);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$html) return [];

        $images = [];

        // <img> 태그에서 src 추출
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*/i', $html, $matches);
        foreach ($matches[1] ?? [] as $imgSrc) {
            // 상대 URL → 절대 URL
            if (strpos($imgSrc, '//') === 0) $imgSrc = 'https:' . $imgSrc;
            elseif (strpos($imgSrc, '/') === 0) {
                $parsed = parse_url($url);
                $imgSrc = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $imgSrc;
            }

            // 필터: 유효한 이미지 URL만
            if (!preg_match('/\.(jpg|jpeg|png|webp|gif)/i', $imgSrc)) continue;
            // 아이콘/로고/버튼 등 제외
            if (preg_match('/(icon|logo|button|banner|ad|sprite|emoji|avatar|profile|thumb_s)/i', $imgSrc)) continue;
            // 너무 작은 이미지 파라미터 감지
            if (preg_match('/[?&](w|width)=(\d+)/i', $imgSrc, $wm) && intval($wm[2]) < 200) continue;
            // data: URL 제외
            if (strpos($imgSrc, 'data:') === 0) continue;

            // 네이버 블로그 이미지: 고화질 버전으로 변환
            if (strpos($imgSrc, 'blogpfx.naver.net') !== false || strpos($imgSrc, 'postfiles.naver.net') !== false) {
                $imgSrc = preg_replace('/\?type=.*$/', '?type=w966', $imgSrc);
            }

            $images[] = $imgSrc;
            if (count($images) >= 3) break; // 페이지당 최대 3개
        }

        return $images;
    }

    /** Naver 테스트 결과 반환 */
    public function testDetailed() {
        $results = [];

        // Naver 테스트
        $naverResults = $this->searchNaver("테스트");
        $results['naver'] = [
            'ok' => !empty($naverResults),
            'msg' => !empty($naverResults) ? count($naverResults) . '건' : '실패',
        ];

        return $results;
    }
}
