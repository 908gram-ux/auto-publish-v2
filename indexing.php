<?php
/**
 * 검색엔진 인덱싱(수집) 상태 체크
 * - Google: site: 검색
 * - Naver: 네이버 검색 API
 * - Bing: site: 검색 (scraping)
 * - Daum: site: 검색 (scraping)
 */

require_once __DIR__ . '/config.php';

class IndexingChecker {

    /**
     * 특정 URL의 검색엔진 인덱싱 상태 확인
     * @param string $url 확인할 URL
     * @param string $siteUrl 사이트 기본 URL
     * @return array ['google'=>bool|null, 'naver'=>bool|null, 'bing'=>bool|null, 'daum'=>bool|null, 'checked_at'=>string]
     */
    public static function checkUrl($url, $siteUrl = '') {
        $results = [
            'google' => null,
            'naver' => null,
            'bing' => null,
            'daum' => null,
            'checked_at' => date('Y-m-d H:i:s'),
        ];

        // Google: Custom Search API로 site: 검색
        $results['google'] = self::checkGoogle($url);

        // Naver: 검색 API로 URL 포함 검색
        $results['naver'] = self::checkNaver($url);

        // Bing: IndexNow 응답 기반 (실제 인덱싱은 확인 불가, 핑 성공 여부로 대체)
        $results['bing'] = self::checkBing($url);

        // Daum: 다음 검색으로 확인
        $results['daum'] = self::checkDaum($url);

        return $results;
    }

    /**
     * 여러 URL 일괄 체크
     */
    public static function checkMultiple($urls, $siteUrl = '') {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = self::checkUrl($url, $siteUrl);
            usleep(500000); // 0.5초 딜레이
        }
        return $results;
    }

    /**
     * Google 인덱싱 확인 (Custom Search API)
     */
    private static function checkGoogle($url) {
        $apiKey = getKey('google.search_api_key');
        $cx = getKey('google.search_cx');
        if (!$apiKey || !$cx) return null;

        // URL에서 경로 추출하여 검색
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $searchQuery = "site:{$host} " . ($parsed['path'] ?? '');

        $apiUrl = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key' => $apiKey,
            'cx' => $cx,
            'q' => $searchQuery,
            'num' => 3,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        $totalResults = intval($data['searchInformation']['totalResults'] ?? 0);
        
        // 검색 결과에 해당 URL이 포함되어 있는지 확인
        if ($totalResults > 0 && !empty($data['items'])) {
            foreach ($data['items'] as $item) {
                if (self::urlMatch($url, $item['link'] ?? '')) {
                    return true;
                }
            }
        }
        return $totalResults > 0; // 정확한 URL 매칭은 아니지만 site: 결과가 있으면 인덱싱된 것으로 간주
    }

    /**
     * Naver 인덱싱 확인 (검색 API)
     */
    private static function checkNaver($url) {
        $clientId = getKey('naver.client_id');
        $clientSecret = getKey('naver.client_secret');
        if (!$clientId || !$clientSecret) return null;

        // URL에서 도메인 추출 후 site: 검색
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        
        // slug 추출 (마지막 경로 세그먼트)
        $slug = trim(basename($path), '/');
        $searchQuery = "site:{$host} {$slug}";

        $apiUrl = 'https://openapi.naver.com/v1/search/webkr.json?' . http_build_query([
            'query' => $searchQuery,
            'display' => 5,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Naver-Client-Id: ' . $clientId,
                'X-Naver-Client-Secret: ' . $clientSecret,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        $total = intval($data['total'] ?? 0);

        if ($total > 0 && !empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $itemUrl = $item['link'] ?? '';
                if (self::urlMatch($url, $itemUrl)) {
                    return true;
                }
            }
        }
        return $total > 0;
    }

    /**
     * Bing 인덱싱 확인 (간접 - 핑 기록 기반)
     * 실제 Bing API 접근 없이 IndexNow 핑 결과로 대체
     */
    private static function checkBing($url) {
        // Bing은 무료 API로 인덱싱 확인이 어려움
        // IndexNow 핑 성공 여부를 기록해서 참고용으로 제공
        return null; // null = 확인 불가
    }

    /**
     * Daum 인덱싱 확인 (간접)
     */
    private static function checkDaum($url) {
        // Daum은 공개 API 없음, XML-RPC 핑 성공 여부로 대체
        return null; // null = 확인 불가
    }

    /**
     * URL 매칭 (프로토콜, trailing slash 무시)
     */
    private static function urlMatch($url1, $url2) {
        $normalize = function($u) {
            $u = preg_replace('#^https?://#', '', $u);
            $u = rtrim($u, '/');
            return strtolower($u);
        };
        return $normalize($url1) === $normalize($url2);
    }

    /**
     * 핑 결과를 Job 데이터에 저장
     */
    public static function savePingResults($jobId, $postIdx, $pingResults) {
        $jobs = loadJobs();
        foreach ($jobs as &$j) {
            if ($j['id'] === $jobId && isset($j['posts'][$postIdx])) {
                $j['posts'][$postIdx]['ping_results'] = $pingResults;
                $j['posts'][$postIdx]['ping_at'] = date('Y-m-d H:i:s');
                saveJobs($jobs);
                return true;
            }
        }
        return false;
    }

    /**
     * 인덱싱 상태를 Job 데이터에 저장
     */
    public static function saveIndexStatus($jobId, $postIdx, $indexStatus) {
        $jobs = loadJobs();
        foreach ($jobs as &$j) {
            if ($j['id'] === $jobId && isset($j['posts'][$postIdx])) {
                $j['posts'][$postIdx]['index_status'] = $indexStatus;
                saveJobs($jobs);
                return true;
            }
        }
        return false;
    }

    /**
     * 전체 사이트 인덱싱 현황 요약
     */
    public static function getSummary() {
        $jobs = loadJobs();
        $summary = [
            'total_posts' => 0,
            'with_ping' => 0,
            'indexed' => ['google' => 0, 'naver' => 0, 'bing' => 0, 'daum' => 0],
            'not_indexed' => ['google' => 0, 'naver' => 0, 'bing' => 0, 'daum' => 0],
            'unknown' => ['google' => 0, 'naver' => 0, 'bing' => 0, 'daum' => 0],
            'last_check' => null,
        ];

        foreach ($jobs as $j) {
            foreach ($j['posts'] ?? [] as $p) {
                if (($p['status'] ?? '') !== 'done' || empty($p['post_id'])) continue;
                $summary['total_posts']++;

                if (!empty($p['ping_results']) || !empty($p['ping_at'])) {
                    $summary['with_ping']++;
                }

                $idx = $p['index_status'] ?? [];
                foreach (['google', 'naver', 'bing', 'daum'] as $engine) {
                    $val = $idx[$engine] ?? null;
                    if ($val === true) $summary['indexed'][$engine]++;
                    elseif ($val === false) $summary['not_indexed'][$engine]++;
                    else $summary['unknown'][$engine]++;
                }

                if (!empty($idx['checked_at'])) {
                    if (!$summary['last_check'] || $idx['checked_at'] > $summary['last_check']) {
                        $summary['last_check'] = $idx['checked_at'];
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * 발행된 글 목록 + 인덱싱 상태
     */
    public static function getPostsList() {
        $jobs = loadJobs();
        $sites = getSites();
        $posts = [];

        foreach ($jobs as $j) {
            foreach ($j['posts'] ?? [] as $pi => $p) {
                if (($p['status'] ?? '') !== 'done' || empty($p['post_id'])) continue;
                $sIdx = intval($p['site_idx'] ?? 0);
                $siteUrl = $sites[$sIdx]['site_url'] ?? '';
                $postUrl = $p['url'] ?? '';
                if (!$postUrl && $siteUrl && !empty($p['post_id'])) {
                    $postUrl = rtrim($siteUrl, '/') . '/?p=' . $p['post_id'];
                }

                $posts[] = [
                    'job_id' => $j['id'],
                    'post_idx' => $pi,
                    'keyword' => $p['keyword'] ?? '',
                    'title' => $p['title'] ?? $p['keyword'] ?? '',
                    'post_id' => $p['post_id'] ?? '',
                    'url' => $postUrl,
                    'site' => $sites[$sIdx]['name'] ?? "사이트#{$sIdx}",
                    'site_idx' => $sIdx,
                    'ai' => $p['ai_used'] ?? $p['ai_mode'] ?? '',
                    'created' => $j['created_at'] ?? '',
                    'ping_results' => $p['ping_results'] ?? [],
                    'ping_at' => $p['ping_at'] ?? '',
                    'index_status' => $p['index_status'] ?? [],
                ];
            }
        }

        return array_reverse($posts); // 최신순
    }
}
