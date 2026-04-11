<?php
/**
 * ═══════════════════════════════════════════════════════════
 * GitHub Actions 콜백 엔드포인트 — gh_callback.php
 * ═══════════════════════════════════════════════════════════
 * 
 * GitHub Actions에서 auto_publish.php 실행 중 진행 상황을
 * 서버로 보내는 콜백을 처리합니다. 토큰 인증 기반 (세션 불필요).
 * 
 * 📌 의존: config.php (getKey, loadJobs, updateJob, updateJobPost)
 * 📌 호출: auto_publish.php의 ghCallback() 함수
 * 📌 수신 데이터: POST JSON {token, job_id, type, ...}
 * 
 * 🔧 콜백 타입:
 *   post_running   → 글 처리 시작
 *   post_progress  → 상세 진행 단계 (NEW: [1/7] 검색, [2/7] AI 생성 등)
 *   post_done      → 글 발행 완료
 *   post_failed    → 글 실패
 *   job_done       → 전체 작업 완료
 *   choco_running  → 초코365 진행 중
 *   choco_done     → 초코365 개별 완료
 *   choco_failed   → 초코365 개별 실패
 *   choco_job_done → 초코365 전체 완료
 */
if (($_GET['action'] ?? '') === 'gh_callback' || ($_POST['action'] ?? '') === 'gh_callback_ext') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $cbToken = $input['token'] ?? '';
    $expectedToken = md5(getKey('github.token') . getKey('admin.email'));
    if ($cbToken !== $expectedToken) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid token']);
        exit;
    }
    
    $cbJobId = $input['job_id'] ?? '';
    $cbType = $input['type'] ?? '';
    
    // ★ FIX: stopped/done 상태인 Job은 콜백 무시 (강제종료 후 GitHub가 계속 보내는 콜백 차단)
    if ($cbJobId) {
        $cbJob = getJob($cbJobId);
        if ($cbJob && in_array($cbJob['status'] ?? '', ['stopped', 'done'])) {
            // 단, job_done 콜백은 허용 (최종 완료 처리)
            if ($cbType !== 'job_done') {
                echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'job already ' . $cbJob['status']]);
                exit;
            }
        }
    }

    // ★ v7: check_stop — GitHub Actions에서 서버에 중지 여부 확인
    // auto_publish.php의 isJobStopped() 함수가 이 콜백을 호출
    if ($cbType === 'check_stop') {
        $isStopped = false;
        if ($cbJobId) {
            $job = getJob($cbJobId);
            $isStopped = $job && in_array($job['status'] ?? '', ['stopped', 'failed']);
        }
        echo json_encode(['ok' => true, 'stopped' => $isStopped]);
        exit;
    }

    // ★ v7: get_models — GitHub Actions 실행 시 서버의 최신 모델 설정 가져오기
    // 관리자 페이지에서 모델을 변경하면 GitHub Actions에도 자동 반영됨
    if ($cbType === 'get_models') {
        $keys = loadApiKeys();
        $models = [];
        foreach (['claude', 'grok', 'chatgpt', 'gemini'] as $ai) {
            $models[$ai] = [
                'model' => $keys[$ai]['model'] ?? '',
                'image_model' => $keys[$ai]['image_model'] ?? '',
            ];
        }
        echo json_encode(['ok' => true, 'models' => $models]);
        exit;
    }
    
    if ($cbJobId && $cbType === 'post_done') {
        $pi = intval($input['post_idx'] ?? -1);
        if ($pi >= 0) {
            updateJobPost($cbJobId, $pi, [
                'status' => 'done',
                'post_id' => $input['post_id'] ?? '',
                'url' => $input['url'] ?? '',
                'ai_used' => $input['ai_used'] ?? '',
                'title' => $input['title'] ?? '',
            ]);
            $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
            $t = date('H:i:s');
            $aiInfo = $input['ai_used'] ?? '';
            $logMsg = "[{$t}] ✅ [{$pi}] 발행 완료: " . ($input['title'] ?? '') . " | AI: {$aiInfo}\n[{$t}] 🔗 " . ($input['url'] ?? '') . "\n\n";
            file_put_contents($logFile, $logMsg, FILE_APPEND);
        }
    } elseif ($cbJobId && $cbType === 'post_failed') {
        $pi = intval($input['post_idx'] ?? -1);
        if ($pi >= 0) {
            updateJobPost($cbJobId, $pi, ['status' => 'failed', 'error' => $input['error'] ?? '실행 실패']);
            $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
            $t = date('H:i:s');
            file_put_contents($logFile, "[{$t}] ❌ [{$pi}] 실패: " . ($input['error'] ?? '') . "\n\n", FILE_APPEND);
        }
    } elseif ($cbJobId && $cbType === 'post_running') {
        $pi = intval($input['post_idx'] ?? -1);
        if ($pi >= 0) {
            updateJobPost($cbJobId, $pi, ['status' => 'running']);
            $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
            $t = date('H:i:s');
            file_put_contents($logFile, "[{$t}] ⏳ [{$pi}] " . ($input['keyword'] ?? '') . " 처리 중...\n", FILE_APPEND);
        }
    } elseif ($cbJobId && $cbType === 'post_progress') {
        // 🆕 상세 진행 단계 로그 (GitHub Actions → 서버 실시간 전달)
        $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
        $stepMsg = $input['message'] ?? '';
        if ($stepMsg) {
            $t = date('H:i:s');
            file_put_contents($logFile, "[{$t}] {$stepMsg}\n", FILE_APPEND);
        }
    } elseif ($cbJobId && ($cbType === 'job_done' || $cbType === 'job_continuing')) {
        // job_continuing: 타임아웃 임박으로 새 워크플로우로 이어하기
        if ($cbType === 'job_continuing') {
            updateJob($cbJobId, ['status' => 'continuing']);
            $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
            $t = date('H:i:s');
            $remaining = $input['remaining'] ?? '?';
            file_put_contents($logFile, "\n[{$t}] 🔄 타임아웃 임박 → 남은 {$remaining}개 자동 이어하기 (성공 " . ($input['ok']??0) . " / 실패 " . ($input['fail']??0) . ")\n\n", FILE_APPEND);
        } else {
            // ★ v9 FIX: job_done 수신 시 서버 jobs.json에 pending 글이 남아있는지 확인
            // payload 분할로 일부만 보냈을 때, 나머지를 자동 이어하기
            $serverJob = getJob($cbJobId);
            $pendingRemain = 0;
            if ($serverJob) {
                foreach ($serverJob['posts'] ?? [] as $sp) {
                    if (($sp['status'] ?? '') === 'pending') $pendingRemain++;
                }
            }
            
            if ($pendingRemain > 0) {
                // 아직 pending 글이 남아있음 → 자동 이어하기 트리거
                updateJob($cbJobId, ['status' => 'continuing']);
                $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
                $t = date('H:i:s');
                file_put_contents($logFile, "\n[{$t}] 🐙 이번 배치 완료 (성공 " . ($input['ok']??0) . " / 실패 " . ($input['fail']??0) . ")\n", FILE_APPEND);
                file_put_contents($logFile, "[{$t}] 📦 서버에 pending {$pendingRemain}개 남음 → 자동 이어하기 트리거\n", FILE_APPEND);
                
                // 이어하기 트리거 (continue_job 로직 재사용)
                $ghToken = getKey('github.token');
                $ghOwner = getKey('github.owner');
                $ghRepo = $ghOwner ? ($ghOwner . '/' . getKey('github.repo')) : getKey('github.repo');
                if ($ghToken && $ghRepo) {
                    // ★ payload 경량화: pending 글만 추출 + 필수 필드만
                    $lightJob = $serverJob;
                    $lightPosts = [];
                    foreach ($serverJob['posts'] as $sp) {
                        if (($sp['status'] ?? '') !== 'pending') continue;
                        $lightPosts[] = [
                            'keyword' => $sp['keyword'],
                            'title' => $sp['title'] ?? '',
                            'site_idx' => $sp['site_idx'] ?? 0,
                            'category_id' => $sp['category_id'] ?? 0,
                            'ai_mode' => $sp['ai_mode'] ?? 'random',
                            'image_source' => $sp['image_source'] ?? 'none',
                            'image_count' => $sp['image_count'] ?? 1,
                            'content_min' => $sp['content_min'] ?? 2500,
                            'content_max' => $sp['content_max'] ?? 4000,
                            'delay_min' => $sp['delay_min'] ?? 0,
                            'delay_max' => $sp['delay_max'] ?? 0,
                            'status' => 'pending',
                        ];
                    }
                    $lightJob['posts'] = $lightPosts;
                    unset($lightJob['custom_prompt']);
                    
                    // payload 크기 체크 및 분할
                    $cbUrl2 = getKey('github.callback_url', '');
                    $cbTk2 = md5($ghToken . getKey('admin.email'));
                    $maxPayloadBytes = 55000;
                    $sendPosts = $lightPosts;
                    $sendCount = count($lightPosts);
                    
                    $testEncoded = base64_encode(json_encode($lightJob, JSON_UNESCAPED_UNICODE));
                    $testPayload = json_encode(['event_type'=>'continue-job','client_payload'=>['job_id'=>$cbJobId,'job_data'=>$testEncoded,'callback_url'=>$cbUrl2,'callback_token'=>$cbTk2]]);
                    if (strlen($testPayload) > $maxPayloadBytes) {
                        // 이진 탐색으로 보낼 수 있는 최대 수 찾기
                        $lo = 1; $hi = count($lightPosts);
                        while ($lo < $hi) {
                            $mid = intval(ceil(($lo + $hi) / 2));
                            $lightJob['posts'] = array_slice($lightPosts, 0, $mid);
                            $te = base64_encode(json_encode($lightJob, JSON_UNESCAPED_UNICODE));
                            $tp = json_encode(['event_type'=>'continue-job','client_payload'=>['job_id'=>$cbJobId,'job_data'=>$te,'callback_url'=>$cbUrl2,'callback_token'=>$cbTk2]]);
                            if (strlen($tp) <= $maxPayloadBytes) { $lo = $mid; if ($lo === $hi) break; } else { $hi = $mid - 1; }
                        }
                        $sendCount = $lo;
                        file_put_contents($logFile, "[{$t}] 📦 이어하기 payload 분할: {$pendingRemain}개 중 {$sendCount}개만 이번에 전송\n", FILE_APPEND);
                    }
                    
                    $lightJob['posts'] = array_slice($lightPosts, 0, $sendCount);
                    $jobDataB64 = base64_encode(json_encode($lightJob, JSON_UNESCAPED_UNICODE));
                    
                    $dispatchPayload2 = json_encode([
                        'event_type' => 'continue-job',
                        'client_payload' => [
                            'job_id' => $cbJobId,
                            'job_data' => $jobDataB64,
                            'callback_url' => $cbUrl2,
                            'callback_token' => $cbTk2,
                        ],
                    ]);
                    
                    $ch2 = curl_init("https://api.github.com/repos/{$ghRepo}/dispatches");
                    curl_setopt_array($ch2, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Accept: application/vnd.github+json',
                            "Authorization: Bearer {$ghToken}",
                            'User-Agent: auto-publish-bot',
                        ],
                        CURLOPT_POSTFIELDS => $dispatchPayload2,
                    ]);
                    $ghResp2 = curl_exec($ch2);
                    $ghCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                    curl_close($ch2);
                    
                    if ($ghCode2 === 204 || $ghCode2 === 200) {
                        file_put_contents($logFile, "[{$t}] 🔄 자동 이어하기 워크플로우 트리거 성공 → 남은 {$pendingRemain}개 처리 예정\n\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, "[{$t}] ⚠️ 자동 이어하기 트리거 실패 (HTTP {$ghCode2}) → 수동 재실행 필요\n\n", FILE_APPEND);
                        updateJob($cbJobId, ['status' => 'draft']); // 실패 시 draft로 → 수동 재실행 가능
                    }
                } else {
                    file_put_contents($logFile, "[{$t}] ⚠️ GitHub 토큰/레포 미설정 → 자동 이어하기 불가\n\n", FILE_APPEND);
                    updateJob($cbJobId, ['status' => 'draft']);
                }
            } else {
                // pending 없음 → 정상 완료
                updateJob($cbJobId, ['status' => 'done']);
                $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
                $t = date('H:i:s');
                file_put_contents($logFile, "\n[{$t}] 🐙 GitHub Actions 작업 완료!\n", FILE_APPEND);
            }
        }
        $statusFile = ROOT_DIR . '/logs/job.status';
        if (file_exists($statusFile)) {
            $st = json_decode(file_get_contents($statusFile), true) ?: [];
            $st['status'] = ($cbType === 'job_continuing' || $pendingRemain > 0) ? 'continuing' : 'done';
            file_put_contents($statusFile, json_encode($st));
        }
    } elseif ($cbJobId && $cbType === 'continue_job') {
        // ★ 자동 이어하기: 서버에서 GitHub Actions 새 워크플로우 트리거
        $job = getJob($cbJobId);
        if ($job) {
            $ghToken = getKey('github.token');
            $ghOwner = getKey('github.owner');
            $ghRepo = $ghOwner ? ($ghOwner . '/' . getKey('github.repo')) : getKey('github.repo');
            if ($ghToken && $ghRepo) {
                $jobDataB64 = base64_encode(json_encode($job, JSON_UNESCAPED_UNICODE));
                $cbUrl = getKey('github.callback_url', '');
                $cbTk = md5($ghToken . getKey('admin.email'));
                
                $dispatchPayload = json_encode([
                    'event_type' => 'continue-job',
                    'client_payload' => [
                        'job_id' => $cbJobId,
                        'job_data' => $jobDataB64,
                        'callback_url' => $cbUrl,
                        'callback_token' => $cbTk,
                    ],
                ]);
                
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
                    CURLOPT_POSTFIELDS => $dispatchPayload,
                ]);
                $ghResp = curl_exec($ch);
                $ghCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $logFile = ROOT_DIR . '/logs/job_' . $cbJobId . '.log';
                $t = date('H:i:s');
                if ($ghCode === 204 || $ghCode === 200) {
                    file_put_contents($logFile, "[{$t}] 🔄 자동 이어하기 워크플로우 트리거 성공\n", FILE_APPEND);
                } else {
                    file_put_contents($logFile, "[{$t}] ⚠️ 자동 이어하기 트리거 실패 (HTTP {$ghCode})\n", FILE_APPEND);
                }
            }
        }
    } elseif ($cbJobId && $cbType === 'choco_running') {
        $pi = intval($input['post_idx'] ?? -1);
        // 초코365 진행 상태를 로그 파일에 기록
        $logFile = ROOT_DIR . '/logs/choco_' . $cbJobId . '.json';
        $chocoLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ['items'=>[],'status'=>'running'];
        if ($pi >= 0) {
            $chocoLog['items'][$pi] = ['status'=>'running','keyword'=>$input['keyword']??'','time'=>date('H:i:s')];
        }
        file_put_contents($logFile, json_encode($chocoLog, JSON_UNESCAPED_UNICODE));
    } elseif ($cbJobId && $cbType === 'choco_done') {
        $pi = intval($input['post_idx'] ?? -1);
        $logFile = ROOT_DIR . '/logs/choco_' . $cbJobId . '.json';
        $chocoLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ['items'=>[],'status'=>'running'];
        if ($pi >= 0) {
            $chocoLog['items'][$pi] = [
                'status'=>'done', 'title'=>$input['title']??'', 'slug'=>$input['slug']??'',
                'emoji'=>$input['emoji']??'', 'ai'=>$input['ai']??'', 'ai_sec'=>$input['ai_sec']??0,
                'theme'=>$input['theme']??'', 'tone'=>$input['tone']??'',
                'format'=>$input['format']??'', 'chars'=>$input['chars']??0, 'time'=>date('H:i:s'),
            ];
        }
        file_put_contents($logFile, json_encode($chocoLog, JSON_UNESCAPED_UNICODE));
    } elseif ($cbJobId && $cbType === 'choco_failed') {
        $pi = intval($input['post_idx'] ?? -1);
        $logFile = ROOT_DIR . '/logs/choco_' . $cbJobId . '.json';
        $chocoLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ['items'=>[],'status'=>'running'];
        if ($pi >= 0) {
            $chocoLog['items'][$pi] = ['status'=>'failed','keyword'=>$input['keyword']??'','error'=>$input['error']??'','time'=>date('H:i:s')];
        }
        file_put_contents($logFile, json_encode($chocoLog, JSON_UNESCAPED_UNICODE));
    } elseif ($cbJobId && $cbType === 'choco_job_done') {
        $logFile = ROOT_DIR . '/logs/choco_' . $cbJobId . '.json';
        $chocoLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ['items'=>[]];
        $chocoLog['status'] = 'done';
        $chocoLog['ok'] = $input['ok'] ?? 0;
        $chocoLog['fail'] = $input['fail'] ?? 0;
        $chocoLog['finished_at'] = date('Y-m-d H:i:s');
        file_put_contents($logFile, json_encode($chocoLog, JSON_UNESCAPED_UNICODE));
    }
    
    echo json_encode(['ok' => true]);
    exit;
}
