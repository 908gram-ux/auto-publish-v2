<?php
/**
 * ============================================
 * 워드프레스 자동 블로그 발행 시스템 v2
 * ============================================
 * ● 다중 워드프레스 사이트 지원
 * ● AI별 일일 호출 한도
 * ● SEO 최적화 (H2/H3/H4, 내부링크, 외부링크, 테이블, 리스트)
 * ● 카테고리 자동 지정
 * ● 글 간격 랜덤 딜레이
 * ● WebP 이미지 최적화
 *
 *   php auto_publish.php              → 전체 사이트 순차 발행
 *   php auto_publish.php --site=0     → 특정 사이트만 (인덱스)
 *   php auto_publish.php --test       → 연결 테스트
 *   php auto_publish.php --dry-run    → 글 생성만 (발행 안 함)
 *   php auto_publish.php --status     → AI 일일 사용량 확인
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes.php';

// logs 디렉토리 자동 생성
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) { mkdir($logsDir, 0755, true); }

// ── 타임아웃 자동 이어하기 ──
// GitHub Actions 최대 240분 중 210분(3시간30분)이 지나면 남은 작업을 새 워크플로우로 넘김
$GLOBALS['_startTime'] = time();
define('GA_TIME_LIMIT_SEC', 210 * 60); // 3시간 30분

/**
 * GitHub Actions 타임아웃 임박 여부 체크
 */
function isTimeRunningOut(): bool {
    if (!getenv('GITHUB_ACTIONS')) return false; // 서버 직접 실행 시에는 무제한
    return (time() - $GLOBALS['_startTime']) >= GA_TIME_LIMIT_SEC;
}

/**
 * 남은 pending 글들을 새 GitHub Actions 워크플로우로 자동 재시작
 * GitHub API를 직접 호출하여 repository_dispatch 이벤트 전송
 */
function triggerContinuation(string $jobId): bool {
    $cbUrl = getenv('CALLBACK_URL');
    $cbToken = getenv('CALLBACK_TOKEN');

    // 방법 1: 서버 콜백을 통한 재시작
    if ($cbUrl && $cbToken) {
        $payload = [
            'type' => 'continue_job',
            'token' => $cbToken,
            'job_id' => $jobId,
        ];
        $ch = curl_init($cbUrl . '?action=gh_callback');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            write_log("🔄 자동 이어하기 요청 성공 (서버 콜백) → 새 워크플로우가 나머지를 처리합니다");
            return true;
        }
        write_log("⚠️ 서버 콜백 실패 (HTTP {$code}) → GitHub API 직접 시도");
    }

    // 방법 2: GitHub API 직접 호출
    $ghToken = getenv('GITHUB_TOKEN');
    $ghRepo = getenv('GITHUB_REPOSITORY');
    if (!$ghToken || !$ghRepo) {
        write_log("⚠️ GITHUB_TOKEN 또는 GITHUB_REPOSITORY 없음 → 자동 이어하기 불가");
        return false;
    }

    // 현재 Job 데이터를 다시 읽어서 base64 인코딩
    $job = getJob($jobId);
    if (!$job) {
        write_log("⚠️ Job 데이터 없음 → 자동 이어하기 불가");
        return false;
    }
    $jobDataB64 = base64_encode(json_encode($job, JSON_UNESCAPED_UNICODE));

    $dispatchPayload = [
        'event_type' => 'continue-job',
        'client_payload' => [
            'job_id' => $jobId,
            'job_data' => $jobDataB64,
            'callback_url' => $cbUrl ?: '',
            'callback_token' => $cbToken ?: '',
        ],
    ];

    $ch = curl_init("https://api.github.com/repos/{$ghRepo}/dispatches");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/vnd.github+json',
            "Authorization: Bearer {$ghToken}",
            'User-Agent: auto-publish-bot',
        ],
        CURLOPT_POSTFIELDS => json_encode($dispatchPayload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // GitHub dispatch는 성공 시 204 반환
    if ($code === 204 || $code === 200) {
        write_log("🔄 자동 이어하기 요청 성공 (GitHub API) → 새 워크플로우가 나머지를 처리합니다");
        return true;
    } else {
        write_log("⚠️ GitHub API 실패 (HTTP {$code}): {$resp}");
        return false;
    }
}

// ── GitHub Actions 콜백 함수 ──
function ghCallback($type, $data = []) {
    $cbUrl = getenv('CALLBACK_URL');
    $cbToken = getenv('CALLBACK_TOKEN');
    if (!$cbUrl || !$cbToken) return;
    
    $payload = array_merge(['type' => $type, 'token' => $cbToken], $data);
    $ch = curl_init($cbUrl . '?action=gh_callback');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        write_log("⚠️ 콜백 실패 (HTTP {$code}): {$type}");
    }
}

/**
 * 상세 진행 로그를 서버로 전달 (GitHub Actions 실행 시)
 * 서버의 gh_callback → post_progress 타입으로 logs/job_*.log에 기록됨
 */
function ghProgress($jobId, $message) {
    ghCallback('post_progress', ['job_id' => $jobId, 'message' => $message]);
}

/**
 * ★ v7: Job 중지 상태 체크
 * 매 글 처리 전에 호출하여, 관리자가 중지 버튼을 누른 경우 즉시 종료
 * - 로컬 실행: jobs.json에서 직접 상태 확인
 * - GitHub Actions: 콜백 URL로 서버에 상태 질의
 */
function isJobStopped(string $jobId): bool {
    // 방법 1: 로컬 jobs.json 직접 확인 (서버 로컬 실행 시)
    if (!getenv('GITHUB_ACTIONS')) {
        $job = getJob($jobId);
        return $job && in_array($job['status'] ?? '', ['stopped', 'failed']);
    }

    // 방법 2: GitHub Actions 환경 → 서버에 콜백으로 상태 질의
    $cbUrl = getenv('CALLBACK_URL');
    $cbToken = getenv('CALLBACK_TOKEN');
    if (!$cbUrl || !$cbToken) return false;

    $ch = curl_init($cbUrl . '?action=gh_callback');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'type' => 'check_stop',
            'token' => $cbToken,
            'job_id' => $jobId,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($resp, true);
        if (!empty($data['stopped'])) {
            return true;
        }
    }
    return false;
}

// ═════════════════════════════════════════
// 메인 실행
// ═════════════════════════════════════════

$opts = getopt('', ['test', 'dry-run', 'help', 'status', 'site:', 'job:', 'ai:', 'img:', 'cat:']);
$single_kw = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : null;

if (isset($opts['help'])) {
    echo "php auto_publish.php              전체 사이트 발행\n";
    echo "php auto_publish.php --site=0     특정 사이트만\n";
    echo "php auto_publish.php --job=ID     작업 실행\n";
    echo "php auto_publish.php --test       연결 테스트\n";
    echo "php auto_publish.php --dry-run    생성만\n";
    echo "php auto_publish.php --status     AI 사용량 확인\n";
    exit(0);
}

$ai = new AIRouter();
$searcher = new WebSearcher();
$image = new ImageGenerator();
$sites = getSites();

// ─── AI 사용량 확인 ───
if (isset($opts['status'])) {
    echo "\n=== AI 일일 사용량 (" . date('Y-m-d') . ") ===\n\n";
    $status = $ai->getDailyStatus();
    foreach ($status as $name => $s) {
        $pct = $s['limit'] > 0 ? round($s['used'] / $s['limit'] * 100) : 0;
        $bar = str_repeat('█', (int)($pct / 5)) . str_repeat('░', 20 - (int)($pct / 5));
        echo "  {$name}: [{$bar}] {$s['used']}/{$s['limit']} ({$pct}%)\n";
    }
    echo "\n";
    exit(0);
}

// ─── 테스트 ───
if (isset($opts['test'])) {
    echo "\n=== API 연결 테스트 ===\n\n";
    $ai_results = $ai->testAll();
    foreach ($ai_results as $name => $r) {
        $icon = $r['status']==='ok' ? '✅' : ($r['status']==='skip' ? '⏭️' : '❌');
        echo "  {$icon} {$name}: {$r['msg']}\n";
    }
    echo "\n";
    $searchDetail = $searcher->testDetailed();
    foreach ($searchDetail as $eng => $r) {
        $icon = (!empty($r['skip'])) ? '⏭️' : ($r['ok'] ? '✅' : '❌');
        $label = $eng === 'naver' ? 'Naver 검색' : 'Google 검색';
        echo "  {$icon} {$label}: {$r['msg']}\n";
    }

    echo "\n--- 워드프레스 사이트 ---\n";
    foreach ($sites as $i => $site) {
        if (empty($site['enabled'])) { echo "  ⏭️ [{$i}] {$site['name']} (비활성)\n"; continue; }
        $wp = new WordPressAPI($site);
        echo "  " . ($wp->test() ? '✅' : '❌') . " [{$i}] {$site['name']} ({$site['site_url']})\n";
    }

    $info = $ai->getRotationInfo();
    echo "\n  AI 순서: " . implode(' → ', $info['order']) . "\n  다음: {$info['next']}\n\n";
    exit(0);
}

// ─── 작업(Job) 모드 ───
if (isset($opts['job'])) {
    $jobId = $opts['job'];
    $job = getJob($jobId);
    if (!$job) { write_log("작업 없음: {$jobId}"); exit(1); }

    $dry = isset($opts['dry-run']);
    $sites = getSites();

    // ★ Job에 포함된 커스텀 프롬프트를 글로벌 변수로 전달 (GitHub Actions에서도 적용되도록)
    if (!empty($job['custom_prompt'])) {
        $GLOBALS['_custom_blog_prompt'] = $job['custom_prompt'];
        write_log("📝 Job에 커스텀 블로그 프롬프트 포함됨 (" . mb_strlen($job['custom_prompt']) . "자)");
    }

    write_log("══════ 작업 시작: {$job['name']} ══════");
    ghProgress($jobId, "══════ 작업 시작: {$job['name']} ══════");
    write_log("글 수: " . count($job['posts'] ?? []) . "개 | 사이트: 행별 지정");

    // WP 인스턴스 캐시 (사이트별)
    $wpCache = [];
    $catCache = [];

    updateJob($jobId, ['status' => 'running']);

    $pendingPosts = [];
    foreach ($job['posts'] as $pi => $pp) {
        if ($pp['status'] === 'pending') $pendingPosts[] = $pi;
    }

    if (empty($pendingPosts)) {
        write_log("대기 중인 글이 없습니다");
        updateJob($jobId, ['status' => 'done']);
        exit(0);
    }

    write_log("대기 글: " . count($pendingPosts) . "개 | 간격: {$job['interval_min']}~{$job['interval_max']}분");

    // 스케줄 시작 시간 대기
    $schedStart = strtotime($job['schedule_start'] ?? '');
    if ($schedStart && $schedStart > time() + 10) {
        $waitSec = $schedStart - time();
        write_log("⏰ 예약 시간까지 " . round($waitSec / 60, 1) . "분 대기...");
        sleep($waitSec);
    }

    $ok = 0; $fail = 0;

    foreach ($pendingPosts as $idx => $postIdx) {
        // ★ v7: 중지 상태 체크 — 관리자가 중지 버튼 누르면 즉시 중단
        if (isJobStopped($jobId)) {
            $remaining = count($pendingPosts) - $idx;
            write_log("🛑 작업 중지 감지! 남은 {$remaining}개 글 중단");
            ghProgress($jobId, "🛑 작업 중지됨 — 남은 {$remaining}개 글 중단");
            // 남은 pending 글들을 failed 처리
            for ($si = $idx; $si < count($pendingPosts); $si++) {
                updateJobPost($jobId, $pendingPosts[$si], ['status' => 'failed', 'error' => '사용자 강제 중지 (원격)']);
            }
            updateJob($jobId, ['status' => 'stopped']);
            write_log("══════ 작업 중지: 성공 {$ok} / 실패 {$fail} / 중단 {$remaining} ══════");
            ghCallback('job_done', ['job_id' => $jobId, 'ok' => $ok, 'fail' => $fail, 'stopped' => true]);
            exit(0);
        }

        // ★ 타임아웃 체크: 3시간 30분 지나면 남은 글을 새 워크플로우로 넘김
        if (isTimeRunningOut()) {
            $remaining = count($pendingPosts) - $idx;
            $elapsed = round((time() - $GLOBALS['_startTime']) / 60);
            write_log("⏰ GitHub Actions 타임아웃 임박 ({$elapsed}분 경과) → 남은 {$remaining}개 글 자동 이어하기");
            ghProgress($jobId, "⏰ 타임아웃 임박 → 남은 {$remaining}개 자동 이어하기 시작");

            // 현재 상태 저장 (pending인 것들은 그대로 유지됨)
            updateJob($jobId, ['status' => 'continuing']);

            // 새 워크플로우 트리거
            if (triggerContinuation($jobId)) {
                write_log("══════ 이번 실행 중간 결과: 성공 {$ok} / 실패 {$fail} | 남은 {$remaining}개는 다음 실행에서 계속 ══════");
                ghCallback('job_continuing', ['job_id' => $jobId, 'ok' => $ok, 'fail' => $fail, 'remaining' => $remaining]);
            } else {
                // 자동 이어하기 실패 시 draft로 변경 (수동으로 다시 실행 가능)
                updateJob($jobId, ['status' => 'draft']);
                write_log("⚠️ 자동 이어하기 실패 → 남은 {$remaining}개는 수동으로 재실행하세요");
            }
            exit(0);
        }

        $pp = $job['posts'][$postIdx];
        $kw = $pp['keyword'];
        $customTitle = $pp['title'];

        // 행별 사이트
        $siteIdx = intval($pp['site_idx'] ?? 0);
        $site = $sites[$siteIdx] ?? ($sites[0] ?? null);
        if (!$site) {
            updateJobPost($jobId, $postIdx, ['status' => 'failed', 'error' => "사이트 없음 (인덱스:{$siteIdx})"]);
            ghCallback('post_failed', ['job_id' => $jobId, 'post_idx' => $postIdx, 'error' => "사이트 없음"]);
            $fail++; continue;
        }

        // WP 인스턴스 캐시
        if (!isset($wpCache[$siteIdx])) {
            $wpCache[$siteIdx] = new WordPressAPI($site);
            $catIds = [];
            if (!empty($site['category_id'])) $catIds[] = intval($site['category_id']);
            elseif (!empty($site['category_name'])) { $cid = $wpCache[$siteIdx]->getCategoryId($site['category_name']); if ($cid) $catIds[] = $cid; }
            $catCache[$siteIdx] = $catIds;
        }
        $wp = $wpCache[$siteIdx];
        $internalLinks = $site['internal_links'] ?? [];

        // 카테고리: 행별 설정 우선, 없으면 사이트 기본값
        if (!empty($pp['category_id'])) {
            $categoryIds = [intval($pp['category_id'])];
        } else {
            $categoryIds = $catCache[$siteIdx];
        }

        // 행별 설정 (없으면 작업 기본값)
        $postAiMode = $pp['ai_mode'] ?? $job['ai_mode'] ?? 'random';
        $postImgSrc = $pp['image_source'] ?? $job['image_source'] ?? 'random';
        $postImgCnt = intval($pp['image_count'] ?? $job['image_count'] ?? 1);
        $contentMin = intval($pp['content_min'] ?? $job['content_min'] ?? 2500);
        $contentMax = intval($pp['content_max'] ?? $job['content_max'] ?? 4000);
        if ($idx > 0) {
            // 행별 간격 우선, 없으면 작업 기본 간격
            $dMin = intval($pp['delay_min'] ?? $job['interval_min'] ?? 0);
            $dMax = intval($pp['delay_max'] ?? $job['interval_max'] ?? 1);
            if ($dMax < $dMin) $dMax = $dMin;
            if ($dMin > 0 || $dMax > 0) {
                $delay = rand(max(0, $dMin) * 60, max(1, $dMax) * 60);
                write_log("다음 글까지 " . round($delay / 60, 1) . "분 대기...");
                sleep($delay);
            }
        }

        write_log("──── [{$idx}/{" . count($pendingPosts) . "}] {$site['name']} | 키워드: {$kw} ────");
        updateJobPost($jobId, $postIdx, ['status' => 'running']);
        ghCallback('post_running', ['job_id' => $jobId, 'post_idx' => $postIdx, 'keyword' => $kw]);

        try {
            // 1. Naver 검색
            write_log("[1/7] Naver 검색");
            ghProgress($jobId, "[1/7] Naver 검색: {$kw}");
            $ndata = $searcher->searchAll($kw); sleep(1);

            // 2. AI 글 생성 (행별 설정 적용)
            write_log("[2/7] AI 글 생성 (AI:{$postAiMode} 이미지:{$postImgSrc} ×{$postImgCnt} 글자:{$contentMin}~{$contentMax})");
            ghProgress($jobId, "[2/7] AI 글 생성 중... (AI:{$postAiMode}, 글자:{$contentMin}~{$contentMax})");

            // AI 프로바이더 선택
            if ($postAiMode === 'random') {
                $post = $ai->generateBlogPost($kw, $ndata, $internalLinks, true, $contentMin, $contentMax, $postImgCnt);
            } elseif (in_array($postAiMode, ['claude','gemini','chatgpt','grok'])) {
                $post = $ai->generateWithProvider($kw, $postAiMode, $ndata, $internalLinks, $contentMin, $contentMax, $postImgCnt);
            } else {
                $post = $ai->generateBlogPost($kw, $ndata, $internalLinks, false, $contentMin, $contentMax, $postImgCnt);
            }

            if (!$post) {
                updateJobPost($jobId, $postIdx, ['status' => 'failed', 'error' => 'AI 생성 실패']);
                ghCallback('post_failed', ['job_id' => $jobId, 'post_idx' => $postIdx, 'error' => 'AI 생성 실패']);
                $fail++; continue;
            }

            // 커스텀 제목 적용 (키워드가 맨 앞)
            if ($customTitle && $customTitle !== $kw) {
                $post['title'] = $customTitle;
            }

            // focus_keyphrase 강제 설정
            $post['focus_keyphrase'] = $kw;

            $len = mb_strlen(strip_tags($post['content_html']));
            $_aiModel = getKey($post['_provider'] . '.model', '');
            $_imgModel = getKey('gemini.image_model', '');
            write_log("본문: {$len}자 | AI: {$post['_provider']}({$_aiModel}) | 이미지: {$_imgModel} | 제목: {$post['title']}");
            ghProgress($jobId, "📝 AI 생성 완료: {$len}자 | {$post['_provider']}({$_aiModel}) | {$post['title']}");

            // ★ v6: 재생성 없음 — AI가 만든 그대로 발행 (시간·토큰 절약)
            // 500자 미만만 실패 처리, 나머지는 짧아도 발행
            if ($len < $contentMin) {
                write_log("ℹ️ 본문 짧음: {$len}자 (설정 최소: {$contentMin}자) → 그대로 발행");
            }

            if ($len < 500) {
                updateJobPost($jobId, $postIdx, ['status' => 'failed', 'error' => "본문 너무 짧음 ({$len}자)"]);
                ghCallback('post_failed', ['job_id' => $jobId, 'post_idx' => $postIdx, 'error' => "본문 짧음({$len}자)"]);
                $fail++; continue;
            }

            // 이미지 '사용안함' 처리
            if ($postImgSrc === 'none') {
                write_log("[3/7] 이미지 사용안함 → 건너뜀");
                write_log("[4/7] 본문이미지 건너뜀");
                $thumb = null;
                $imgs = [];
                // 본문 내 [IMAGE:...] 태그 제거
                $post['content_html'] = preg_replace('/\[IMAGE:[^\]]*\]/', '', $post['content_html']);
                goto skipImages;
            }

            // 이미지 '로컬 폴더' 처리
            if ($postImgSrc === 'local') {
                write_log("[3/7] 로컬 이미지 썸네일");
                $thumb = $image->getLocalThumbnail($kw);
                if (!$thumb) {
                    write_log("⚠️ 로컬 썸네일 없음 → 그라데이션 생성");
                    $thumb = $image->createThumbnail($post['title'], $kw);
                }

                write_log("[4/7] 로컬 이미지 본문 삽입");
                $proc = $image->processLocalImages($post['content_html'], $kw, $postImgCnt);
                $post['content_html'] = $proc['content'] ?? $post['content_html'];
                $imgs = $proc['images'] ?? [];
                goto skipImages;
            }

            // 3. 썸네일
            $imgSearches = $post['image_searches'] ?? [];
            $thumbSearch = !empty($imgSearches) ? $imgSearches[0] : $kw;

            // 이미지 소스 선택 (행별)
            $origPriority = loadApiKeys()['image_source']['priority'] ?? ['pixabay','pexels','gemini','gradient'];
            if ($postImgSrc !== 'random') {
                $keys = loadApiKeys();
                // 선택된 소스 → 나머지 API → gradient 순서
                $allImgSources = ['pixabay','pexels','gemini','dalle'];
                $ordered = [$postImgSrc];
                foreach ($allImgSources as $s) { if ($s !== $postImgSrc) $ordered[] = $s; }
                $ordered[] = 'gradient';
                $keys['image_source']['priority'] = $ordered;
                saveApiKeys($keys);
            }

            write_log("[3/7] 썸네일");
            ghProgress($jobId, "[3/7] 썸네일 생성 중...");
            $thumbPrompt = $post['thumbnail_prompt'] ?? '';
            $thumb = $image->createThumbnail($post['title'], $thumbSearch, $thumbPrompt);

            // 4. 본문 이미지 (이미지 개수 제한)
            write_log("[4/7] 본문이미지 (설정: {$postImgCnt}개)");
            ghProgress($jobId, "[4/7] 본문 이미지 삽입 중... (최대 {$postImgCnt}개)");
            // ★ FIX: AI가 생성한 [IMAGE:...] 태그를 postImgCnt 만큼만 유지
            if ($postImgCnt >= 0) {
                $imgTagCount = 0;
                $post['content_html'] = preg_replace_callback('/\[IMAGE:[^\]]*\]/', function($m) use ($postImgCnt, &$imgTagCount) {
                    $imgTagCount++;
                    return ($imgTagCount <= $postImgCnt) ? $m[0] : '';
                }, $post['content_html']);
                if ($imgTagCount > $postImgCnt) {
                    write_log("⚠️ 이미지 태그 {$imgTagCount}개 → {$postImgCnt}개로 제한");
                }
                $imgSearches = array_slice($imgSearches, 0, $postImgCnt);
            }
            $proc = $image->processImages($post['content_html'], $imgSearches);
            $post['content_html'] = $proc['content'] ?? $proc['html'] ?? $post['content_html'];
            $imgs = $proc['images'] ?? $proc['files'] ?? [];

            // 이미지 소스 복원
            if ($postImgSrc !== 'random') {
                $keys = loadApiKeys();
                $keys['image_source']['priority'] = $origPriority;
                saveApiKeys($keys);
            }

            skipImages:

            if ($dry) {
                write_log("[DRY-RUN] 완료: {$post['title']}");
                updateJobPost($jobId, $postIdx, ['status' => 'done', 'post_id' => null]);
                $ok++; continue;
            }

            // 5. WP 업로드
            write_log("[5/7] WP 업로드 ({$site['name']})");
            ghProgress($jobId, "[5/7] WP 업로드 중... ({$site['name']})");
            $tu = $thumb ? $wp->uploadImage($thumb, $post['title']) : null;

            $contentHtml = $post['content_html'];
            $firstBodyUpload = null; // ← 본문 첫 이미지 업로드 결과 저장
            foreach ($imgs ?: [] as $img) {
                $localPath = is_array($img) ? ($img['local'] ?? '') : $img;
                $altText = is_array($img) ? ($img['alt'] ?? $post['title']) : $post['title'];
                if (!$localPath || !file_exists($localPath)) continue;
                $up = $wp->uploadImage($localPath, $altText);
                if ($up) {
                    if (!$firstBodyUpload) $firstBodyUpload = $up; // 첫 번째 본문 이미지 기억
                    $contentHtml = str_replace(
                        'src="' . $localPath . '"',
                        'src="' . $up['url'] . '"',
                        $contentHtml
                    );
                }
            }

            // ━━━ Fallback: 썸네일 실패 시 본문 첫 이미지를 대표이미지로 ━━━
            if (!$tu && $firstBodyUpload) {
                $tu = $firstBodyUpload;
                write_log("⚠️ 썸네일 실패 → 본문 첫 이미지를 대표이미지로 사용 (ID: {$tu['id']})");
            }

            // 6. 발행
            write_log("[6/7] 발행 → {$site['name']}");
            ghProgress($jobId, "[6/7] 발행 중... → {$site['name']}");

            // 본문 끝 여백 추가 (관련글/추천글 위 간격 확보)
            $contentHtml .= "\n\n<!-- wp:spacer {\"height\":\"60px\"} -->\n<div style=\"height:60px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->";
            $tagIds = !empty($post['tags']) ? $wp->resolveTagIds(array_slice($post['tags'], 0, 15)) : [];
            $result = $wp->createPost([
                'title' => $post['title'],
                'content' => $contentHtml,
                'tags' => $tagIds,
                'excerpt' => $post['excerpt'] ?? '',
                'slug' => $post['slug'] ?? '',
                'focus_keyphrase' => $post['focus_keyphrase'] ?? $kw,
                'meta_description' => $post['meta_description'] ?? '',
                'categories' => $categoryIds,
                'featured_media' => $tu['id'] ?? 0,
            ]);

            if ($result) {
                // ★ FIX: 중복 감지된 글은 핑 스킵
                $isDuplicate = !empty($result['_duplicate']);
                
                // 7. 검색엔진 핑 (중복이 아닌 경우만)
                $postLink = $result['link'] ?? '';
                $pingResults = [];
                if ($postLink && !$isDuplicate) {
                    write_log("[7/7] 검색엔진 핑");
                    ghProgress($jobId, "[7/7] 검색엔진 핑 전송 중...");
                    $pingResults = SearchEnginePing::pingAll($postLink, $site['site_url']);
                } elseif ($isDuplicate) {
                    write_log("[7/7] 중복 글 → 핑 스킵");
                    ghProgress($jobId, "[7/7] 중복 감지 → 핑 스킵");
                }

                $postId = $result['id'] ?? '';
                $postUrl = $postLink ?: rtrim($site['site_url'], '/') . '/?p=' . $postId;
                write_log("✅ 발행 완료: {$post['title']}");
                ghProgress($jobId, "✅ 발행 완료: {$post['title']}");
                write_log("🔗 {$postUrl}");

                $aiUsedDetail = ($post['_provider'] ?? '') . '(' . ($_aiModel ?: '?') . ')';
                updateJobPost($jobId, $postIdx, [
                    'status' => 'done',
                    'post_id' => $postId,
                    'url' => $postUrl,
                    'ai_used' => $aiUsedDetail,
                    'ping_results' => $pingResults,
                    'ping_at' => date('Y-m-d H:i:s'),
                ]);
                ghCallback('post_done', [
                    'job_id' => $jobId, 'post_idx' => $postIdx,
                    'post_id' => $postId, 'url' => $postUrl,
                    'title' => $post['title'] ?? '', 'ai_used' => $aiUsedDetail,
                ]);
                $ok++;
            } else {
                updateJobPost($jobId, $postIdx, ['status' => 'failed', 'error' => 'WP 발행 실패']);
                ghCallback('post_failed', ['job_id' => $jobId, 'post_idx' => $postIdx, 'error' => 'WP 발행 실패']);
                $fail++;
            }

            // 임시파일 정리
            foreach (array_merge([$thumb ?: ''], $imgs ?: []) as $f) {
                $fp = is_array($f) ? ($f['local'] ?? '') : $f;
                if ($fp && file_exists($fp)) @unlink($fp);
            }

        } catch (Exception $e) {
            write_log("❌ " . $e->getMessage());
            updateJobPost($jobId, $postIdx, ['status' => 'failed', 'error' => $e->getMessage()]);
            ghCallback('post_failed', ['job_id' => $jobId, 'post_idx' => $postIdx, 'error' => $e->getMessage()]);
            $fail++;
        }
    }

    // 작업 상태 업데이트
    $finalJob = getJob($jobId);
    $allDone = empty(array_filter($finalJob['posts'] ?? [], fn($p) => $p['status'] === 'pending'));
    updateJob($jobId, ['status' => $allDone ? 'done' : 'draft']);

    // ★ FIX: 재발행 락 파일 정리
    $retryLockFile = __DIR__ . '/logs/retry_' . $jobId . '.lock';
    if (file_exists($retryLockFile)) @unlink($retryLockFile);

    write_log("══════ 작업 완료: 성공 {$ok} / 실패 {$fail} ══════");
    ghProgress($jobId, "══════ 작업 완료: 성공 {$ok} / 실패 {$fail} ══════");
    ghCallback('job_done', ['job_id' => $jobId, 'ok' => $ok, 'fail' => $fail]);
    exit(0);
}

// ─── 발행 ───
write_log("══════ 자동 발행 시작 ══════");

$info = $ai->getRotationInfo();
write_log("AI 순서: " . implode(' → ', $info['order']) . " | 다음: {$info['next']}");

// 사이트 필터
$targetSite = isset($opts['site']) ? intval($opts['site']) : null;
$dry = isset($opts['dry-run']);

$totalOk = 0; $totalFail = 0;

foreach ($sites as $siteIdx => $site) {
    // 특정 사이트만
    if ($targetSite !== null && $siteIdx !== $targetSite) continue;

    // 비활성
    if (empty($site['enabled'])) {
        write_log("[{$site['name']}] 비활성 → 스킵");
        continue;
    }

    write_log("━━━━ 사이트: {$site['name']} ({$site['site_url']}) ━━━━");

    $wp = new WordPressAPI($site);
    $postsPerRun = intval($site['posts_per_run'] ?? 1);
    $delayMin = intval($site['delay_min'] ?? 3);
    $delayMax = intval($site['delay_max'] ?? 10);
    $internalLinks = $site['internal_links'] ?? [];

    // 카테고리 ID 확인
    $categoryIds = [];
    // CLI --cat 옵션이 있으면 우선 사용
    $cliCat = intval($opts['cat'] ?? 0);
    if ($cliCat > 0) {
        $categoryIds[] = $cliCat;
    } elseif (!empty($site['category_id'])) {
        $categoryIds[] = intval($site['category_id']);
    } elseif (!empty($site['category_name'])) {
        $catId = $wp->getCategoryId($site['category_name']);
        if ($catId) $categoryIds[] = $catId;
    }

    // 키워드 로드
    $kwFile = __DIR__ . '/' . ($site['keywords_file'] ?? 'keywords.txt');
    if (!file_exists($kwFile)) {
        // 기본 키워드 파일 폴백
        $kwFile = KEYWORDS_FILE;
    }
    if (!file_exists($kwFile)) {
        write_log("[{$site['name']}] 키워드 파일 없음: {$kwFile}");
        continue;
    }

    if ($single_kw) {
        $keywords = [$single_kw];
    } else {
        $lines = file($kwFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $keywords = array_values(array_filter(array_map('trim', $lines), fn($l) => $l && !str_starts_with($l, '#')));
        if (empty($keywords)) { write_log("[{$site['name']}] 키워드 없음"); continue; }
        $keywords = array_slice($keywords, 0, $postsPerRun);
    }

    $ok = 0; $fail = 0;

    foreach ($keywords as $kwIdx => $kw) {
        // 글 간격 랜덤 딜레이 (첫 글 제외)
        if ($kwIdx > 0) {
            $delay = rand($delayMin * 60, $delayMax * 60);
            write_log("[{$site['name']}] 다음 글까지 " . round($delay / 60, 1) . "분 대기...");
            sleep($delay);
        }

        write_log("──── [{$site['name']}] 키워드: {$kw} ────");

        try {
            write_log("[1/7] Naver 검색");
            ghProgress($jobId, "[1/7] Naver 검색: {$kw}");
            $ndata = $searcher->searchAll($kw); sleep(1);

            write_log("[2/7] AI 글 생성 (SEO 강화)");
            // CLI --ai 옵션이 있으면 우선 사용 (수동 실행에서 선택한 AI)
            $cliAi = $opts['ai'] ?? '';
            if ($cliAi && in_array($cliAi, ['claude','gemini','chatgpt','grok'])) {
                $post = $ai->generateWithProvider($kw, $cliAi, $ndata, $internalLinks);
            } elseif ($cliAi === 'random') {
                $post = $ai->generateBlogPost($kw, $ndata, $internalLinks, true);
            } else {
                // 사이트별 AI 모드: random(랜덤), specific(지정), rotation(순번-기본)
                $aiMode = $site['ai_mode'] ?? 'rotation';
                $aiProvider = $site['ai_provider'] ?? '';
                if ($aiMode === 'specific' && $aiProvider) {
                    $post = $ai->generateWithProvider($kw, $aiProvider, $ndata, $internalLinks);
                } else {
                    $post = $ai->generateBlogPost($kw, $ndata, $internalLinks, $aiMode === 'random');
                }
            }
            if (!$post) { $fail++; continue; }

            $len = mb_strlen(strip_tags($post['content_html']));
            $slug = $post['slug'] ?? '';
            $focusKw = $post['focus_keyphrase'] ?? $kw;
            write_log("본문: {$len}자 | AI: {$post['_provider']}(" . getKey($post['_provider'].'.model','') . ") | 이미지: " . getKey('gemini.image_model','') . " | slug: {$slug}");
            if ($len < MIN_CONTENT_LENGTH) { write_log("⚠️ 짧음({$len}자 < " . MIN_CONTENT_LENGTH . "자) → 스킵"); $fail++; continue; }

            // AI가 제공한 이미지 검색어 사용 (관련성 높은 이미지!)
            $imgSearches = $post['image_searches'] ?? [];
            $thumbSearch = !empty($imgSearches) ? $imgSearches[0] : $kw;

            write_log("[3/7] 썸네일 (검색어: {$thumbSearch})");
            // CLI --img 옵션으로 이미지 소스 오버라이드
            $cliImg = $opts['img'] ?? '';
            $origPriority = null;
            if ($cliImg && in_array($cliImg, ['pixabay','pexels','gemini','dalle','gradient','none','local'])) {
                if ($cliImg === 'none') {
                    write_log("[3/7] 이미지 사용안함 → 건너뜀");
                    $thumb = null; $imgs = []; $content = preg_replace('/\[IMAGE:[^\]]*\]/', '', $post['content_html']);
                    goto skipImagesCli;
                }
                if ($cliImg === 'local') {
                    $thumb = $image->getLocalThumbnail($kw);
                    if (!$thumb) $thumb = $image->createThumbnail($post['title'], $kw);
                    write_log("[4/7] 로컬 이미지 본문 삽입");
                    $proc = $image->processLocalImages($post['content_html'], $kw, 3);
                    $content = $proc['content']; $imgs = $proc['images'];
                    goto skipImagesCli;
                }
                $origPriority = loadApiKeys()['image_source']['priority'] ?? ['pixabay','pexels','gradient'];
                $tmpKeys = loadApiKeys();
                $allImgSources = ['pixabay','pexels','gemini','dalle'];
                $ordered = [$cliImg];
                foreach ($allImgSources as $s) { if ($s !== $cliImg) $ordered[] = $s; }
                $ordered[] = 'gradient';
                $tmpKeys['image_source']['priority'] = $ordered;
                saveApiKeys($tmpKeys);
            }
            $thumbPrompt = $post['thumbnail_prompt'] ?? '';
            $thumb = $image->createThumbnail($post['title'], $thumbSearch, $thumbPrompt);
            write_log("[4/7] 본문이미지");
            ghProgress($jobId, "[4/7] 본문 이미지 삽입 중...");
            $proc = $image->processImages($post['content_html'], $imgSearches);
            $content = $proc['content']; $imgs = $proc['images'];
            // 이미지 소스 복원
            if ($origPriority !== null) {
                $tmpKeys = loadApiKeys();
                $tmpKeys['image_source']['priority'] = $origPriority;
                saveApiKeys($tmpKeys);
            }

            skipImagesCli:

            if ($dry) { write_log("[DRY-RUN] 완료: {$post['title']}"); $ok++; continue; }

            write_log("[5/7] WP 업로드 ({$site['name']})");
            ghProgress($jobId, "[5/7] WP 업로드 중... ({$site['name']})");
            $tu = $thumb ? $wp->uploadImage($thumb, $post['title']) : null;
            if (!empty($imgs)) $content = $wp->replaceImages($content, $imgs);

            // ━━━ Fallback: 썸네일 실패 시 본문 첫 업로드 이미지를 대표이미지로 ━━━
            if (!$tu && $content) {
                // replaceImages에서 업로드된 이미지 중 첫 번째를 찾아 업로드
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $imgM)) {
                    $fallbackUrl = $imgM[1];
                    write_log("⚠️ 썸네일 실패 → 본문 첫 이미지를 대표이미지로 사용: {$fallbackUrl}");
                    // api.php 서버 쪽 fallback이 본문에서 자동 추출하므로 여기서는 로그만
                    // (featured_media=0으로 보내도 api.php가 본문 첫 이미지를 featured_image로 설정)
                }
            }

            write_log("[6/7] 발행 → {$site['name']}");
            ghProgress($jobId, "[6/7] 발행 중... → {$site['name']}");

            // 본문 끝 여백 추가
            $content .= "\n\n<!-- wp:spacer {\"height\":\"60px\"} -->\n<div style=\"height:60px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->";

            $tags = !empty($post['tags']) ? $wp->resolveTagIds(array_slice($post['tags'], 0, 15)) : [];
            $result = $wp->createPost([
                'title' => $post['title'],
                'content' => $content,
                'slug' => $slug,
                'excerpt' => $post['excerpt'] ?? '',
                'meta_description' => $post['meta_description'] ?? '',
                'focus_keyphrase' => $focusKw,
                'tags' => $tags,
                'categories' => $categoryIds,
                'featured_media' => $tu['id'] ?? 0,
            ]);

            if ($result) {
                // 발행 성공 → 검색엔진 자동 핑
                $postLink = $result['link'] ?? '';
                if ($postLink) {
                    write_log("[7/7] 검색엔진 핑 ({$postLink})");
                    SearchEnginePing::pingAll($postLink, $site['site_url']);
                }

                $postId = $result['id'] ?? '';
                $postUrl = $postLink ?: rtrim($site['site_url'], '/') . '/?p=' . $postId;
                write_log("✅ 발행 완료: {$post['title']}");
                ghProgress($jobId, "✅ 발행 완료: {$post['title']}");
                write_log("🔗 {$postUrl}");

                // 키워드 제거
                if (!$single_kw && file_exists($kwFile)) {
                    $ls = file($kwFile, FILE_IGNORE_NEW_LINES);
                    file_put_contents($kwFile, implode(PHP_EOL, array_filter($ls, fn($l) => trim($l) !== trim($kw))) . PHP_EOL);
                }
                $ok++;
            } else { $fail++; }

            // 임시파일 정리
            foreach (array_merge([$thumb], $imgs ?: []) as $f) { if (file_exists($f)) unlink($f); }

        } catch (Exception $e) { write_log("❌ " . $e->getMessage()); $fail++; }
    }

    write_log("[{$site['name']}] 결과: 성공 {$ok} / 실패 {$fail}");
    $totalOk += $ok; $totalFail += $fail;
}

write_log("══════ 전체 완료: 성공 {$totalOk} / 실패 {$totalFail} ══════");

// 임시 파일 정리 (job.pid, job.status는 폴링 완료 후 setup.php에서 정리)
$statusFile = __DIR__ . '/logs/job.status';
if (file_exists($statusFile)) {
    $st = json_decode(file_get_contents($statusFile), true) ?: [];
    $st['status'] = 'done';
    file_put_contents($statusFile, json_encode($st));
}
