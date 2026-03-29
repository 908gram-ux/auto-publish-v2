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
