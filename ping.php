<?php
/**
 * 검색엔진 자동 핑 (IndexNow + Google + Daum)
 */

require_once __DIR__ . '/config.php';

class SearchEnginePing {

    /**
     * 발행된 URL을 모든 검색엔진에 핑
     */
    public static function pingAll($postUrl, $siteUrl) {
        $results = [];

        // 1) IndexNow (네이버 + 빙 동시 지원)
        $results['indexnow'] = self::indexNow($postUrl, $siteUrl);

        // 2) Google 핑 (sitemap ping - 간단하지만 효과적)
        $results['google'] = self::googlePing($siteUrl);

        // 3) 다음 웹마스터 XML-RPC 핑
        $results['daum'] = self::xmlRpcPing($postUrl, $siteUrl);

        $ok = count(array_filter($results));
        write_log("🔔 검색엔진 핑 완료: " . implode(', ', array_map(fn($k,$v)=>$k.($v?'✅':'❌'), array_keys($results), $results)) . " ({$ok}/" . count($results) . ")");

        return $results;
    }

    /**
     * IndexNow - 네이버, 빙에 동시 알림
     * 네이버는 IndexNow 프로토콜 지원 (2023~)
     */
    private static function indexNow($postUrl, $siteUrl) {
        $keys = loadApiKeys();
        $indexNowKey = $keys['indexnow_key'] ?? '';

        // IndexNow 키가 없으면 자동 생성
        if (!$indexNowKey) {
            $indexNowKey = bin2hex(random_bytes(16));
            $keys['indexnow_key'] = $indexNowKey;
            saveApiKeys($keys);
            write_log("IndexNow 키 자동 생성: {$indexNowKey}");
            write_log("⚠️ 사이트 루트에 {$indexNowKey}.txt 파일을 생성하세요! (내용: {$indexNowKey})");
        }

        // 네이버/빙 동시 핑 (하나만 보내면 다른 엔진에도 전파됨)
        $endpoints = [
            'bing' => 'https://www.bing.com/indexnow',
            'naver' => 'https://searchadvisor.naver.com/indexnow',
        ];

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $success = false;

        foreach ($endpoints as $name => $endpoint) {
            $pingUrl = $endpoint . '?' . http_build_query([
                'url' => $postUrl,
                'key' => $indexNowKey,
                'keyLocation' => rtrim($siteUrl, '/') . "/{$indexNowKey}.txt",
            ]);

            $ch = curl_init($pingUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                write_log("IndexNow({$name}): HTTP {$code} OK");
                $success = true;
                break; // 하나 성공하면 나머지 엔진에도 자동 전파
            } else {
                write_log("IndexNow({$name}): HTTP {$code}");
            }
        }

        return $success;
    }

    /**
     * Google Sitemap Ping
     */
    private static function googlePing($siteUrl) {
        $sitemapUrl = rtrim($siteUrl, '/') . '/sitemap.xml';
        $pingUrl = 'https://www.google.com/ping?sitemap=' . urlencode($sitemapUrl);

        $ch = curl_init($pingUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($code >= 200 && $code < 300);
        write_log("Google Ping: HTTP {$code}" . ($ok ? ' OK' : ''));
        return $ok;
    }

    /**
     * XML-RPC Ping (다음 등)
     */
    private static function xmlRpcPing($postUrl, $siteUrl) {
        $host = parse_url($siteUrl, PHP_URL_HOST);
        $siteName = $host;
        $xml = <<<XML
<?xml version="1.0"?>
<methodCall>
  <methodName>weblogUpdates.ping</methodName>
  <params>
    <param><value>{$siteName}</value></param>
    <param><value>{$siteUrl}</value></param>
    <param><value>{$postUrl}</value></param>
  </params>
</methodCall>
XML;

        // 다음 XML-RPC 핑
        $pingServers = [
            'http://ping.feedburner.com',
            'http://rpc.pingomatic.com',
        ];

        $success = false;
        foreach ($pingServers as $server) {
            $ch = curl_init($server);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
                CURLOPT_POSTFIELDS => $xml,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) { $success = true; break; }
        }

        write_log("XML-RPC Ping: " . ($success ? 'OK' : 'FAIL'));
        return $success;
    }
}
