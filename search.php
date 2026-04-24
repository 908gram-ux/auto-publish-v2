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

        // ★ v7: 현재 연도를 검색어에 추가하여 최신 이미지 우선 확보
        $currentYear = date('Y');
        $searchKeyword = $keyword . ' ' . $currentYear;

        // ── 1차: 네이버 이미지 검색 API (연도 포함 검색) ──
        $url = "https://openapi.naver.com/v1/search/image?" . http_build_query([
            'query' => $searchKeyword,
            'display' => min(30, $count * 5), // 필터링 후 충분한 수 확보 (더 넉넉하게)
            'sort' => 'date', // ★ v7: 최신순 정렬 (sim→date)
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

        // ★ v7: 연도 검색 결과가 부족하면 기본 검색어로 재시도
        if (empty($data['items']) || count($data['items'] ?? []) < 3) {
            write_log("네이버 이미지: '{$searchKeyword}' 결과 부족 → '{$keyword}'로 재검색");
            $url2 = "https://openapi.naver.com/v1/search/image?" . http_build_query([
                'query' => $keyword,
                'display' => min(30, $count * 5),
                'sort' => 'sim',
                'filter' => 'large',
            ]);
            $ch = curl_init($url2);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'X-Naver-Client-Id: ' . $clientId,
                    'X-Naver-Client-Secret: ' . $clientSecret,
                ],
            ]);
            $resp = curl_exec($ch); curl_close($ch);
            $data = json_decode($resp, true);
        }

        // 뉴스 도메인 필터 목록
        $newsDomains = [
            'news.naver.com','n.news.naver.com','news.joins.com','news.chosun.com',
            'news.donga.com','news.hankyung.com','news.mt.co.kr','news.sbs.co.kr',
            'news.kbs.co.kr','news.mbc.co.kr','news.jtbc.co.kr','newsis.com',
            'yonhapnews.co.kr','yna.co.kr','edaily.co.kr','mk.co.kr','sedaily.com',
            'hani.co.kr','khan.co.kr','ohmynews.com','nocutnews.co.kr',
            'imgnews.naver.net','mimgnews.naver.net', // 네이버 뉴스 이미지 CDN
        ];

        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $imgUrl = $item['link'] ?? '';
                $sourceUrl = $item['sizeheight'] ?? ''; // 출처 페이지
                if (!$imgUrl) continue;

                // ★ 뉴스 도메인 필터링
                $isNews = false;
                foreach ($newsDomains as $nd) {
                    if (stripos($imgUrl, $nd) !== false) { $isNews = true; break; }
                }
                if ($isNews) continue;

                // ★ v7: 저작권/브랜드/공공기관 이미지 필터링
                $title = $item['title'] ?? '';
                $titleLower = mb_strtolower(strip_tags($title));
                $urlLower = strtolower($imgUrl);

                // 정부/공공기관 도메인 (인포그래픽, 정책 홍보물)
                $govDomains = ['go.kr', 'gov.kr', 'or.kr', 'ac.kr', 'moel.go.kr', 'mohw.go.kr', 
                    'mois.go.kr', 'korea.kr', 'bokjiro.go.kr', 'nps.or.kr', 'nhis.or.kr'];
                $isGov = false;
                foreach ($govDomains as $gd) {
                    if (stripos($imgUrl, $gd) !== false) { $isGov = true; break; }
                }
                if ($isGov) continue;

                // 기업/브랜드 공식 사이트 이미지 (로고, 워터마크 가능성)
                $brandPatterns = [
                    'samsung.com', 'hyundai.com', 'lge.co.kr', 'sk.com', 'kt.com',
                    'coupang.com', 'kakao.com', 'naver.com/corp', 'toss.im',
                    'shinhan.com', 'kbstar.com', 'wooribank.com', 'hanabank.com',
                    '/logo', '/brand', '/ci/', '/watermark', '/official',
                    'shutterstock.com', 'gettyimages', 'istockphoto', 'adobe.stock',
                ];
                $isBrand = false;
                foreach ($brandPatterns as $bp) {
                    if (stripos($urlLower, $bp) !== false) { $isBrand = true; break; }
                }
                if ($isBrand) continue;

                // 제목에 특정 연도가 포함된 경우 → 현재 연도 아니면 스킵 (오래된 자료 방지)
                if (preg_match('/20(1\d|2[0-4])년/', $title) && !str_contains($title, $currentYear . '년')) {
                    continue; // 2010~2024년 자료이고 올해 자료가 아니면 스킵
                }

                // 너무 작은 이미지 제외 (썸네일 등)
                $w = intval($item['sizewidth'] ?? 0);
                $h = intval($item['sizeheight'] ?? 0);
                if ($w > 0 && $w < 300) continue;
                if ($h > 0 && $h < 200) continue;

                $collected[] = [
                    'url' => $imgUrl,
                    'source' => 'naver_image_api',
                    'title' => html_entity_decode(strip_tags($item['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'width' => $w,   // ★ v7: 16:9 선택용
                    'height' => $h,  // ★ v7: 16:9 선택용
                ];

                if (count($collected) >= $count) break;
            }
        }

        // ── 2차: 부족하면 블로그/웹 크롤링 페이지에서 이미지 추출 ──
        if (count($collected) < $count) {
            $extraNeeded = $count - count($collected);
            $crawlImages = $this->extractImagesFromSearch($keyword, $extraNeeded, $newsDomains);
            $collected = array_merge($collected, $crawlImages);
        }

        $collected = array_slice($collected, 0, $count);
        write_log("🖼️ 네이버 이미지 수집: {$keyword} → " . count($collected) . "개 (뉴스 제외)");
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
