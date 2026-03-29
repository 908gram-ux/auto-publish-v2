<?php
/**
 * 초코365 테스트 자동생성 (GitHub Actions용 CLI)
 * 
 * Usage: php choco_generate.php --job=JOB_FILE
 *   JOB_FILE: JSON file with topics, cat, ai_mode
 * 
 * Environment:
 *   CALLBACK_URL   - 서버 콜백 URL
 *   CALLBACK_TOKEN - 콜백 인증 토큰
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes.php';
require_once __DIR__ . '/prompt_helper.php';

// logs 디렉토리
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) mkdir($logsDir, 0755, true);

// ── 로그 ──
function clog($msg) {
    $ts = date('H:i:s');
    echo "[{$ts}] {$msg}\n";
    flush();
}

// ── 콜백 ──
function chocoCallback($type, $data = []) {
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
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── CLI 인수 파싱 ──
$jobFile = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job=')) $jobFile = substr($arg, 6);
}

if (!$jobFile || !file_exists($jobFile)) {
    clog("❌ Job 파일이 없습니다: {$jobFile}");
    exit(1);
}

$job = json_decode(file_get_contents($jobFile), true);
if (!$job) {
    clog("❌ Job 파일 파싱 실패");
    exit(1);
}

$cat = $job['cat'] ?? '';
$aiMode = $job['ai_mode'] ?? 'random';
$topics = $job['topics'] ?? [];
$jobId = $job['job_id'] ?? 'choco_' . date('Ymd_His');
$jobPrompt = $job['custom_prompt'] ?? '';

if (!$cat || empty($topics)) {
    clog("❌ 카테고리 또는 주제가 없습니다");
    exit(1);
}

clog("━━━ 초코365 테스트 생성 시작 (GitHub Actions) ━━━");
clog("📋 카테고리: {$cat} | AI: {$aiMode} | 총 " . count($topics) . "건");
clog("🔑 Job ID: {$jobId}");

// ═══ 디자인 파라미터 (setup.php와 동일) ═══
$themes = [
    ['name'=>'푸망 스타일','bg'=>'#ffffff','accent'=>'#ff2d55','text'=>'#1a1a2e','card'=>'#f5f5f7','radius'=>'16px','font'=>'Pretendard','desc'=>'깨끗한 흰 배경, 플로팅 이모지, 둥근 pill 버튼, 부드러운 그림자'],
    ['name'=>'파스텔 핑크','bg'=>'linear-gradient(135deg,#fff0f3,#ffe0e6)','accent'=>'#ff6b8a','text'=>'#5c2434','card'=>'rgba(255,255,255,.7)','radius'=>'20px','font'=>'Pretendard','desc'=>'부드러운 핑크 그라데이션, 귀여운 분위기, 하트/별 이모지'],
    ['name'=>'카카오 옐로우','bg'=>'#fee500','accent'=>'#3c1e1e','text'=>'#3c1e1e','card'=>'#fff8d4','radius'=>'12px','font'=>'Pretendard','desc'=>'카카오톡 감성, 노란 배경, 갈색 텍스트, 심플 카드'],
    ['name'=>'레트로 도트','bg'=>'#1a1a2e','accent'=>'#00ff88','text'=>'#00ff88','card'=>'rgba(0,255,136,.08)','radius'=>'4px','font'=>'monospace','desc'=>'게임보이 느낌, 도트 패턴, 깜빡이는 커서, 픽셀 아트'],
    ['name'=>'다크 미스터리','bg'=>'linear-gradient(135deg,#0f0c29,#302b63,#24243e)','accent'=>'#e94560','text'=>'#e8e8e8','card'=>'rgba(255,255,255,.06)','radius'=>'16px','font'=>'Pretendard','desc'=>'짙은 보라/남색, 미스터리한 분위기, 별/달 장식'],
    ['name'=>'빨간 신문 속보','bg'=>'#fff9f0','accent'=>'#cc0000','text'=>'#1a1a1a','card'=>'#ffffff','radius'=>'2px','font'=>'serif','desc'=>'신문 레이아웃, 빨간 헤더 라인, serif 폰트, 속보 느낌'],
    ['name'=>'네온 게이밍','bg'=>'#0a0a0a','accent'=>'#00f0ff','text'=>'#ffffff','card'=>'rgba(0,240,255,.08)','radius'=>'12px','font'=>'Pretendard','desc'=>'짙은 배경, 네온 cyan/magenta 글로우, 사이버펑크'],
    ['name'=>'봄날 민트','bg'=>'linear-gradient(180deg,#e8f5e9,#f1f8e9)','accent'=>'#26a69a','text'=>'#2e4a3e','card'=>'rgba(255,255,255,.8)','radius'=>'20px','font'=>'Pretendard','desc'=>'연한 민트/그린, 식물 이모지, 상쾌한 느낌'],
    ['name'=>'오렌지 푸드','bg'=>'linear-gradient(135deg,#fff3e0,#ffe0b2)','accent'=>'#e65100','text'=>'#4e2600','card'=>'rgba(255,255,255,.7)','radius'=>'16px','font'=>'Pretendard','desc'=>'따뜻한 오렌지, 음식 관련 이모지, 맛있는 분위기'],
    ['name'=>'블루 쿨톤','bg'=>'linear-gradient(135deg,#e3f2fd,#bbdefb)','accent'=>'#1565c0','text'=>'#0d2137','card'=>'rgba(255,255,255,.7)','radius'=>'14px','font'=>'Pretendard','desc'=>'차갑고 깔끔한 파란 계열, 물방울/얼음 이모지'],
    ['name'=>'Y2K 레트로','bg'=>'linear-gradient(135deg,#e8d5f5,#fce4ec,#e1f5fe)','accent'=>'#e040fb','text'=>'#4a148c','card'=>'rgba(255,255,255,.5)','radius'=>'24px','font'=>'Pretendard','desc'=>'메탈릭 느낌, 핫핑크+실버, 스타/반짝이 장식, 둥글둥글'],
    ['name'=>'인스타 감성','bg'=>'#faf8f5','accent'=>'#c0956c','text'=>'#3e2723','card'=>'#ffffff','radius'=>'16px','font'=>'Pretendard','desc'=>'크림+갈색, 미니멀, 감성 사진 느낌, 얇은 라인'],
    ['name'=>'코튼캔디','bg'=>'linear-gradient(135deg,#fce4ec,#e1bee7,#b3e5fc)','accent'=>'#ab47bc','text'=>'#4a0072','card'=>'rgba(255,255,255,.6)','radius'=>'22px','font'=>'Pretendard','desc'=>'핑크+보라+하늘 파스텔 믹스, 구름/솜사탕 느낌'],
    ['name'=>'라벤더 드림','bg'=>'linear-gradient(180deg,#ede7f6,#e8eaf6)','accent'=>'#7e57c2','text'=>'#311b92','card'=>'rgba(255,255,255,.7)','radius'=>'18px','font'=>'Pretendard','desc'=>'보라색 계열, 몽환적, 달/꽃/나비 이모지'],
    ['name'=>'서울 나이트','bg'=>'#1a1a2e','accent'=>'#ff6b6b','text'=>'#f0f0f0','card'=>'rgba(255,107,107,.08)','radius'=>'12px','font'=>'Pretendard','desc'=>'도시 야경 느낌, 짙은 배경에 빨간 포인트, 건물/네온'],
    ['name'=>'숲속 캠핑','bg'=>'linear-gradient(180deg,#e8f5e9,#c8e6c9)','accent'=>'#558b2f','text'=>'#1b3a0f','card'=>'rgba(255,255,255,.7)','radius'=>'16px','font'=>'Pretendard','desc'=>'숲/나무 테마, 자연 이모지, 편안한 녹색 계열'],
    ['name'=>'로즈골드','bg'=>'linear-gradient(135deg,#fce4ec,#f8bbd0)','accent'=>'#c2185b','text'=>'#880e4f','card'=>'rgba(255,255,255,.5)','radius'=>'20px','font'=>'Pretendard','desc'=>'로즈골드+핑크, 고급스러운 느낌, 다이아몬드/장미 장식'],
];

$tones = [
    '반말 친근체 (ㅋㅋ, ~임, ~함 등 인터넷 말투)',
    '존댓말 정중체 (~습니다, ~세요)',
    '츤데레 말투 (관심없는 척하면서 알려주는 느낌)',
    '할머니 말투 (~이란다, ~하렴, 아이고~)',
    'MZ세대 말투 (ㄹㅇ, 개~, 레전드, 갓생)',
    '아나운서 말투 (격식체, ~것으로 보입니다)',
    '연예인 MC 말투 (자~ 여러분!, 두구두구~)',
    '게임 나레이터 (용사여, ~하였도다, 퀘스트)',
    '귀여운 말투 (~용, ~당, ~쥬, 뿌잉뿌잉)',
    '교수님 말투 (~인데요, 여기서 중요한 건, 자 봅시다)',
    'SNS 인플루언서 (진짜 대박..!, 이건 못 참지, 찐으로)',
    '점쟁이 말투 (그대의 운명은..., 별이 말하길...)',
];

$formats = [
    ['type'=>'4유형분류', 'desc'=>'4개 유형 중 하나로 분류. 각 보기가 특정 유형 점수를 올림. 마지막에 가장 높은 유형이 결과'],
    ['type'=>'점수합산형', 'desc'=>'각 보기에 점수(1~4점). 총점으로 결과 구간 판단 (예: 0~15 / 16~30 / 31~40 / 41+)'],
    ['type'=>'스토리분기형', 'desc'=>'각 질문의 선택에 따라 다음 상황이 달라지는 스토리. 최종 엔딩이 4~6개'],
];

$questionStyles = [
    '상황 제시형 (구체적인 상황을 묘사하고 어떻게 할지 물어보기)',
    '이것 vs 저것 (두 가지 중 선택)',
    'OX형 질문 (동의하는지 안 하는지)',
    '완성형 ("나는 ___할 때 가장 행복하다" 빈칸 채우기 느낌)',
    '만약에~ 형 (만약 ~한다면? 가정 질문)',
    '연상 질문 ("이 단어를 들으면 뭐가 떠오르나요?")',
    '순위 질문 ("가장 중요한 것은?")',
];

$resultStyles = [
    '캐릭터형 (동물/직업/캐릭터에 비유. 큰 이모지 + 유형명 + 상세 설명 + 특징 3~4개)',
    '레벨형 (레벨/등급/퍼센트로 표시. 프로그레스바 + 수치 + 해석)',
    '카드형 (타로/운세 카드 느낌. 카드 뒤집기 애니메이션 + 의미 해석)',
    '보고서형 (분석 보고서 느낌. 차트/그래프 CSS로 표현 + 항목별 점수)',
];

// AI 프로바이더 준비
$keys = loadApiKeys();
$allProviders = [
    'claude' => new ClaudeProvider(),
    'grok' => new GrokProvider(),
    'chatgpt' => new ChatGPTProvider(),
    'gemini' => new GeminiProvider(),
];

$system = "당신은 한국에서 가장 인기있는 바이럴 심리테스트 제작 전문가입니다. 주어진 파라미터를 반드시 따라서 완성된 HTML 코드만 출력하세요. 마크다운 코드블록 없이 순수 HTML만. <!--META 부터 </script> 까지만 출력.";

// choco365 저장 정보
$btUrl = $keys['choco365_url'] ?? 'https://www.choco365.com';
$btKey = $keys['choco365_api_key'] ?? 'choco365_auto_2026';

$ok = 0;
$fail = 0;
$total = count($topics);

foreach ($topics as $idx => $topic) {
    $num = $idx + 1;
    clog("──── [{$num}/{$total}] \"{$topic}\" ────");
    
    // 콜백: 진행 중
    chocoCallback('choco_running', [
        'job_id' => $jobId, 'post_idx' => $idx,
        'keyword' => $topic, 'total' => $total,
    ]);
    
    try {
        // 랜덤 파라미터 선택
        $theme = $themes[array_rand($themes)];
        $tone = $tones[array_rand($tones)];
        $format = $formats[array_rand($formats)];
        $qStyle = $questionStyles[array_rand($questionStyles)];
        $rStyle = $resultStyles[array_rand($resultStyles)];
        $qCount = rand(10, 13);
        $choiceCount = [2,3,4][array_rand([2,3,4])];
        
        clog("🎨 {$theme['name']} | 🗣 " . explode(' ', $tone)[0] . " | 📝 {$format['type']} | ❓ {$qCount}문 {$choiceCount}지선다");
        
        // 주제 프롬프트
        $topicPrompt = $topic ? "[테스트 주제]: {$topic}" :
            "[테스트 주제]: 자유롭게 정해줘. 단, 바이럴되기 좋은 재미있고 독특한 심리테스트 주제로.";
        
        // 커스텀 프롬프트 체크
        $customPrompt = $jobPrompt ?: getCustomPrompt('braintest', '');
        clog("Prompt: " . ($customPrompt ? ($jobPrompt ? "Job(" . mb_substr($customPrompt, 0, 30) . "...)" : "File") : "DEFAULT"));
        if ($customPrompt) {
            $prompt = $customPrompt;
            $replacements = [
                '{{TOPIC}}' => $topicPrompt,
                '{{THEME_NAME}}' => $theme['name'],
                '{{THEME_DESC}}' => $theme['desc'],
                '{{THEME_BG}}' => $theme['bg'],
                '{{THEME_ACCENT}}' => $theme['accent'],
                '{{THEME_TEXT}}' => $theme['text'],
                '{{THEME_CARD}}' => $theme['card'],
                '{{THEME_RADIUS}}' => $theme['radius'],
                '{{THEME_FONT}}' => $theme['font'],
                '{{TONE}}' => $tone,
                '{{FORMAT_TYPE}}' => $format['type'],
                '{{FORMAT_DESC}}' => $format['desc'],
                '{{QUESTION_STYLE}}' => $qStyle,
                '{{RESULT_STYLE}}' => $rStyle,
                '{{QUESTION_COUNT}}' => $qCount,
                '{{CHOICE_COUNT}}' => $choiceCount,
            ];
            $prompt = str_replace(array_keys($replacements), array_values($replacements), $prompt);
        } else {
            // 기본 프롬프트 (setup.php와 동일 — 간략화)
            $prompt = "당신은 한국에서 가장 핫한 바이럴 심리테스트 제작자입니다.\n아래 파라미터를 반드시 따라서 완성된 HTML 코드를 만들어주세요.\n\n{$topicPrompt}\n\n";
            $prompt .= "━━━━ 필수 디자인 파라미터 ━━━━\n";
            $prompt .= "■ 디자인 테마: {$theme['name']}\n  → {$theme['desc']}\n";
            $prompt .= "  → 배경: {$theme['bg']} | 메인 컬러: {$theme['accent']} | 텍스트: {$theme['text']}\n";
            $prompt .= "  → 카드 배경: {$theme['card']} | border-radius: {$theme['radius']} | 폰트: {$theme['font']}\n\n";
            $prompt .= "■ 말투: {$tone}\n■ 포맷: {$format['type']} — {$format['desc']}\n";
            $prompt .= "■ 질문 스타일: {$qStyle}\n■ 결과 표현: {$rStyle}\n";
            $prompt .= "■ 질문 수: {$qCount}개 | 보기 수: {$choiceCount}개\n\n";
            $prompt .= "━━━━ 코드 구조 규칙 ━━━━\n";
            $prompt .= "1. <!--META title=... subtitle=... emoji=... badge=... time=... desc=... --> 로 시작\n";
            $prompt .= "2. <!--THUMB <div style=\"...\">썸네일 HTML</div> --> (카드 미니 포스터, 풍성하게)\n";
            $prompt .= "3. <style> + HTML + <script> (body/html 태그 금지, iframe 안에서 동작)\n";
            $prompt .= "4. .wrapper{max-width:640px;margin:0 auto} #intro/#quiz/#result 구조\n";
            $prompt .= "5. 한 번에 하나만 표시, display:none 토글, innerHTML= 교체\n";
            $prompt .= "6. 결과 시 testComplete({name:'유형명',desc:'설명'}) 호출 필수\n";
            $prompt .= "7. '다시하기' 버튼 → location.reload()\n\n";
            $prompt .= "━━━━ 출력 형식 ━━━━\n코드만 출력. 마크다운(```) 없이 <!--META 부터 </script> 까지만.\n";
        }
        
        // AI 프로바이더 선택
        $provider = null;
        $providerName = '';
        if ($aiMode === 'random') {
            $available = [];
            foreach ($allProviders as $k => $p) { if ($p->isConfigured()) $available[$k] = $p; }
            if (empty($available)) throw new Exception('설정된 AI가 없습니다');
            $ka = array_keys($available);
            $providerName = $ka[array_rand($ka)];
            $provider = $available[$providerName];
        } else {
            if (!isset($allProviders[$aiMode]) || !$allProviders[$aiMode]->isConfigured()) {
                throw new Exception("{$aiMode} API 키 미설정");
            }
            $provider = $allProviders[$aiMode];
            $providerName = $aiMode;
        }
        
        // AI 호출
        clog("🤖 {$providerName} 호출 중...");
        $aiStart = microtime(true);
        $result = $provider->callAPI($system, $prompt, 16384);
        $aiSec = round(microtime(true) - $aiStart, 1);
        
        if (!$result) throw new Exception("AI 응답 없음 ({$providerName})");
        clog("✅ AI 응답: {$aiSec}초, " . strlen($result) . "자");
        
        // HTML 정리
        $html = $result;
        $html = preg_replace('/^```html?\s*\n?/m', '', $html);
        $html = preg_replace('/\n?```\s*$/m', '', $html);
        $html = trim($html);
        
// META 추출 (한줄/ 여러줄 모두 지원)
        $testMeta = [];
        if (preg_match('/<!--META\s*(.*?)-->/s', $html, $mm)) {
            $metaStr = trim($mm[1]);
            $knownKeys = ['title','subtitle','emoji','badge','time','desc'];
            $pattern = '/\b(' . implode('|', $knownKeys) . ')=/';
            if (preg_match_all($pattern, $metaStr, $km, PREG_OFFSET_CAPTURE)) {
                for ($i = 0; $i < count($km[0]); $i++) {
                    $key = $km[1][$i][0];
                    $valStart = $km[0][$i][1] + strlen($km[0][$i][0]);
                    $valEnd = isset($km[0][$i+1]) ? $km[0][$i+1][1] : strlen($metaStr);
                    $testMeta[$key] = trim(substr($metaStr, $valStart, $valEnd - $valStart));
                }
            }
            // HTML 내 META를 줐바꿈 포맣일로 재작성 → choco365 사이트에서도 정상 파싱
            $newMeta = "<!--META\n";
            foreach ($testMeta as $k => $v) {
                $newMeta .= "{$k}={$v}\n";
            }
            $newMeta .= "-->";
            $html = preg_replace('/<!--META\s*.*?-->/s', $newMeta, $html, 1);
        }

        $testTitle = $testMeta['title'] ?? ($topic ?: 'test');
        
        // 슬러그 생성
        $slug = preg_replace('/[^가-힣a-z0-9]+/u', '-', mb_strtolower($testTitle));
        $slug = preg_replace('/[^a-z0-9-]/', '', preg_replace('/[\x{AC00}-\x{D7AF}]+/u', '', $slug));
        if (strlen($slug) < 3) $slug = 'test-' . date('ymd-His');
        $slug = substr($slug, 0, 50) . '-' . rand(100,999);
        
        // choco365 저장
        $saveUrl = rtrim($btUrl, '/') . '/api_tests.php?action=save';
        clog("💾 초코365 저장: {$slug} (" . round(strlen($html)/1024,1) . "KB)");
        
        $ch = curl_init($saveUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Choco365-AutoGen/1.0',
            CURLOPT_POSTFIELDS => ['cat'=>$cat, 'slug'=>$slug, 'html'=>$html, 'api_key'=>$btKey],
        ]);
        $saveResp = curl_exec($ch);
        $saveCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $saveResult = json_decode($saveResp, true);
        
        if (!$saveResult || !($saveResult['ok'] ?? false)) {
            $errMsg = $saveResult['error'] ?? "HTTP {$saveCode}";
            if ($curlErr) $errMsg .= " | curl: {$curlErr}";
            throw new Exception("초코365 저장 실패: {$errMsg}");
        }
        
        $emoji = $testMeta['emoji'] ?? '📝';
        clog("✅ 완료! {$emoji} {$testTitle}");
        clog("   🎨 {$theme['name']} · 🗣 " . explode(' ', $tone)[0] . " · 🤖 {$providerName} ({$aiSec}초)");
        
        // 콜백: 성공
        chocoCallback('choco_done', [
            'job_id' => $jobId, 'post_idx' => $idx,
            'title' => $testTitle, 'slug' => $slug,
            'emoji' => $testMeta['emoji'] ?? '📝',
            'ai' => $providerName, 'ai_sec' => $aiSec,
            'theme' => $theme['name'], 'tone' => explode(' ', $tone)[0],
            'format' => $format['type'], 'chars' => strlen($html),
        ]);
        $ok++;
        
    } catch (Exception $e) {
        clog("❌ 실패: " . $e->getMessage());
        chocoCallback('choco_failed', [
            'job_id' => $jobId, 'post_idx' => $idx,
            'keyword' => $topic, 'error' => $e->getMessage(),
        ]);
        $fail++;
    }
    
    // 다음 건 전 약간의 딜레이
    if ($idx < $total - 1) {
        clog("⏳ 3초 대기...\n");
        sleep(3);
    }
}

clog("\n══════ 생성 완료: 성공 {$ok} / 실패 {$fail} (총 {$total}건) ══════");

// 콜백: 전체 완료
chocoCallback('choco_job_done', [
    'job_id' => $jobId, 'ok' => $ok, 'fail' => $fail, 'total' => $total,
]);

exit($fail > 0 ? 1 : 0);
