<?php
/**
 * 설정 파일 - api_keys.json에서 API 키를 읽어옴
 */
date_default_timezone_set('Asia/Seoul');

// PHP 7.x 호환 폴리필
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return strpos($haystack, $needle) !== false;
    }
}

define('API_KEYS_FILE', __DIR__ . '/api_keys.json');
define('JOBS_FILE', __DIR__ . '/jobs.json');
define('KEYWORDS_FILE', __DIR__ . '/keywords.txt');
define('LOG_FILE', __DIR__ . '/publish_log.txt');
define('COUNTER_FILE', __DIR__ . '/api_counter.txt');
define('FONT_PATH', __DIR__ . '/fonts/NanumGothicBold.ttf');
define('IMAGE_SAVE_DIR', __DIR__ . '/tmp_images/');
define('LOCAL_IMAGE_DIR', __DIR__ . '/local_images/');
define('MIN_CONTENT_LENGTH', 1500);

// PHP CLI 경로 자동 감지 (Cloudways / 닷홈 / 기타 환경 대응)
$_phpCli = '';
$_phpPaths = [
    '/usr/local/bin/php',                    // Cloudways
    '/usr/bin/php',                          // 일반 Linux
    '/opt/remi/php84/root/usr/bin/php',      // 닷홈 (PHP 8.4)
    '/opt/remi/php83/root/usr/bin/php',      // 닷홈 (PHP 8.3)
    PHP_BINARY,                              // 현재 실행 중인 PHP
];
foreach ($_phpPaths as $_p) {
    if ($_p && @file_exists($_p)) { $_phpCli = $_p; break; }
}
if (!$_phpCli) $_phpCli = 'php'; // 최후 폴백 (PATH에서 찾기)
define('PHP_CLI', $_phpCli);

if (!is_dir(IMAGE_SAVE_DIR)) mkdir(IMAGE_SAVE_DIR, 0755, true);
if (!is_dir(LOCAL_IMAGE_DIR)) mkdir(LOCAL_IMAGE_DIR, 0755, true);

// ── 작업(Job) 관리 ──
function loadJobs() {
    if (!file_exists(JOBS_FILE)) return [];
    return json_decode(file_get_contents(JOBS_FILE), true) ?: [];
}
function saveJobs($jobs) {
    file_put_contents(JOBS_FILE, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function getJob($jobId) {
    $jobs = loadJobs();
    foreach ($jobs as &$j) { if ($j['id'] === $jobId) return $j; }
    return null;
}
function updateJob($jobId, $data) {
    $jobs = loadJobs();
    foreach ($jobs as &$j) {
        if ($j['id'] === $jobId) { $j = array_merge($j, $data); saveJobs($jobs); return true; }
    }
    return false;
}
function updateJobPost($jobId, $postIdx, $postData) {
    $jobs = loadJobs();
    foreach ($jobs as &$j) {
        if ($j['id'] === $jobId && isset($j['posts'][$postIdx])) {
            $j['posts'][$postIdx] = array_merge($j['posts'][$postIdx], $postData);
            saveJobs($jobs);
            return true;
        }
    }
    return false;
}

function loadApiKeys() {
    if (!file_exists(API_KEYS_FILE)) return [];
    return json_decode(file_get_contents(API_KEYS_FILE), true) ?: [];
}

function saveApiKeys($data) {
    file_put_contents(API_KEYS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function getKey($path, $default = '') {
    $keys = loadApiKeys();
    foreach (explode('.', $path) as $p) {
        if (!isset($keys[$p])) return $default;
        $keys = $keys[$p];
    }
    return $keys;
}

function write_log($msg) {
    $entry = "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    file_put_contents(LOG_FILE, $entry, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $entry;
}

/**
 * AI 일일 카운터 체크 & 증가
 */
function checkAiDailyLimit($aiName) {
    $keys = loadApiKeys();
    $today = date('Y-m-d');

    // 날짜 리셋
    if (($keys[$aiName]['last_reset'] ?? '') !== $today) {
        $keys[$aiName]['today_count'] = 0;
        $keys[$aiName]['last_reset'] = $today;
        saveApiKeys($keys);
    }

    $limit = intval($keys[$aiName]['daily_limit'] ?? 100);
    $count = intval($keys[$aiName]['today_count'] ?? 0);
    return $count < $limit;
}

function incrementAiDailyCount($aiName) {
    $keys = loadApiKeys();
    $keys[$aiName]['today_count'] = intval($keys[$aiName]['today_count'] ?? 0) + 1;
    saveApiKeys($keys);
}

/**
 * AI 비용 추적 (토큰 사용량 → 예상 비용 기록)
 */
function trackAiCost($aiName, $inputTokens, $outputTokens) {
    // 모델별 가격 ($/1M tokens) [input, output]
    $pricing = [
        'claude' => ['claude-haiku-4-5-20251001'=>[1,5],'claude-sonnet-4-5-20250929'=>[3,15],'claude-opus-4-6'=>[15,75]],
        'grok' => ['grok-3-mini-fast'=>[0.3,0.5],'grok-3-mini'=>[0.3,0.5],'grok-3'=>[3,15]],
        'chatgpt' => ['gpt-4o-mini'=>[0.15,0.6],'gpt-4o'=>[2.5,10],'gpt-4-turbo'=>[10,30]],
        'gemini' => ['gemini-2.5-flash'=>[0.15,0.6],'gemini-2.5-flash-lite'=>[0.075,0.3],'gemini-2.5-pro'=>[1.25,10]],
    ];
    $keys = loadApiKeys();
    $model = $keys[$aiName]['model'] ?? '';
    $rates = $pricing[$aiName][$model] ?? [1, 5]; // 기본값

    $costUsd = ($inputTokens * $rates[0] / 1000000) + ($outputTokens * $rates[1] / 1000000);

    // 일일 비용 누적
    $today = date('Y-m-d');
    if (!isset($keys['spending'])) $keys['spending'] = [];
    if (!isset($keys['spending'][$today])) $keys['spending'][$today] = ['total_usd' => 0, 'by_ai' => [], 'tokens' => ['input' => 0, 'output' => 0]];

    $keys['spending'][$today]['total_usd'] = round(($keys['spending'][$today]['total_usd'] ?? 0) + $costUsd, 6);
    $keys['spending'][$today]['by_ai'][$aiName] = round(($keys['spending'][$today]['by_ai'][$aiName] ?? 0) + $costUsd, 6);
    $keys['spending'][$today]['tokens']['input'] += $inputTokens;
    $keys['spending'][$today]['tokens']['output'] += $outputTokens;

    // 총 누적
    $keys['spending']['total_usd'] = round(($keys['spending']['total_usd'] ?? 0) + $costUsd, 6);

    // 최근 30일만 유지
    $dates = array_filter(array_keys($keys['spending']), fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $k));
    rsort($dates);
    foreach (array_slice($dates, 30) as $old) unset($keys['spending'][$old]);

    saveApiKeys($keys);
    return $costUsd;
}

/**
 * 사이트 목록 가져오기
 */
function getSites() {
    $keys = loadApiKeys();
    return $keys['sites'] ?? [];
}
