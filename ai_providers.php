<?php
/**
 * AI 제공자 인터페이스 + 프로바이더 + 라우터
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prompt.php';

/**
 * ★ v6: 모델별 max_output_tokens 상한
 * 요청한 maxTokens가 모델 상한을 초과하면 자동으로 잘라줌
 */
function getModelMaxTokens(string $model): int {
    // ── Claude ──
    $map = [
        'claude-haiku-4-5-20251001' => 8192,
        'claude-sonnet-4-5-20250929' => 8192,
        'claude-opus-4-6' => 8192,
        // ── Grok ──
        'grok-3-mini-fast' => 16384,
        'grok-3-mini' => 16384,
        'grok-3' => 16384,
        'grok-4-1-fast' => 16384,
        'grok-4-1-fast-non-reasoning' => 131072,
        'grok-4-1-fast-reasoning' => 131072,
        'grok-4-fast' => 16384,
        'grok-4-fast-non-reasoning' => 131072,
        'grok-4-fast-reasoning' => 131072,
        'grok-4' => 131072,
        // ── ChatGPT ──
        'gpt-4o-mini' => 16384,
        'gpt-4o' => 16384,
        'gpt-4.1-nano' => 16384,
        'gpt-4.1-mini' => 16384,
        'gpt-4.1' => 32768,
        'o4-mini' => 16384,
        'gpt-5-nano' => 16384,
        'gpt-5-mini' => 16384,
        'gpt-5.1-codex-mini' => 16384,
        'gpt-5.4-mini' => 16384,
        // ── Gemini ──
        'gemini-2.5-flash-lite' => 65536,
        'gemini-2.5-flash' => 65536,
        'gemini-2.5-pro' => 65536,
    ];

    // 정확한 매칭
    if (isset($map[$model])) return $map[$model];

    // 부분 매칭 (새 모델 대응)
    foreach ($map as $key => $limit) {
        if (str_contains($model, $key)) return $limit;
    }

    // 프로바이더별 안전한 기본값
    if (str_contains($model, 'claude')) return 8192;
    if (str_contains($model, 'grok')) return 16384;
    if (str_contains($model, 'gpt') || str_contains($model, 'o4')) return 16384;
    if (str_contains($model, 'gemini')) return 65536;

    return 8192; // 최종 기본값
}

/**
 * 요청할 maxTokens를 모델 상한에 맞춰 자동 조정
 */
function clampMaxTokens(string $model, int $requested): int {
    $limit = getModelMaxTokens($model);
    if ($requested > $limit) {
        write_log("ℹ️ maxTokens {$requested} → {$limit} (모델 상한: {$model})");
        return $limit;
    }
    return $requested;
}


interface AIProvider {
    public function getName(): string;
    public function getKey(): string;
    public function callAPI($system, $user, $maxTokens = 8192): ?string;
    public function isConfigured(): bool;
    public function getLastUsage(): array; // ['input'=>0, 'output'=>0]
}

// ─── Claude ───
class ClaudeProvider implements AIProvider {
    private $lastUsage = ['input'=>0, 'output'=>0];
    public function getName(): string { return 'Claude'; }
    public function getKey(): string { return 'claude'; }
    public function isConfigured(): bool {
        $model = trim(getKey('claude.model'));
        if (empty($model)) return false;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return false;
        return !empty(getKey('claude.api_key'));
    }
    public function getLastUsage(): array { return $this->lastUsage; }

    public function callAPI($system, $user, $maxTokens = 8192): ?string {
        $this->lastUsage = ['input'=>0, 'output'=>0];
        $model = getKey('claude.model', 'claude-haiku-4-5-20251001');
        $maxTokens = clampMaxTokens($model, $maxTokens);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . getKey('claude.api_key'),
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]]
            ]),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) { write_log("Claude HTTP {$code}: " . mb_substr($resp, 0, 300)); return null; }
        $r = json_decode($resp, true);
        $this->lastUsage = ['input' => $r['usage']['input_tokens'] ?? 0, 'output' => $r['usage']['output_tokens'] ?? 0];
        return $r['content'][0]['text'] ?? null;
    }
}

// ─── Grok ───
class GrokProvider implements AIProvider {
    private $lastUsage = ['input'=>0, 'output'=>0];
    public function getName(): string { return 'Grok'; }
    public function getKey(): string { return 'grok'; }
    public function isConfigured(): bool {
        $model = trim(getKey('grok.model'));
        if (empty($model)) return false;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return false;
        return !empty(getKey('grok.api_key'));
    }
    public function getLastUsage(): array { return $this->lastUsage; }

    public function callAPI($system, $user, $maxTokens = 8192): ?string {
        $this->lastUsage = ['input'=>0, 'output'=>0];
        $model = getKey('grok.model', 'grok-3-mini-fast');
        $maxTokens = clampMaxTokens($model, $maxTokens);

        // ★ v7: Grok 4 시리즈는 reasoning 모델 → max_completion_tokens 사용
        $isGrok4 = (strpos($model, 'grok-4') !== false);
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($isGrok4) {
            $body['max_completion_tokens'] = $maxTokens;
        } else {
            $body['max_tokens'] = $maxTokens;
        }

        $ch = curl_init('https://api.x.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . getKey('grok.api_key'),
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) { write_log("Grok HTTP {$code} [모델:{$model}]: " . mb_substr($resp, 0, 500)); return null; }
        $r = json_decode($resp, true);
        $this->lastUsage = ['input' => $r['usage']['prompt_tokens'] ?? 0, 'output' => $r['usage']['completion_tokens'] ?? 0];
        return $r['choices'][0]['message']['content'] ?? null;
    }
}

// ─── ChatGPT ───
class ChatGPTProvider implements AIProvider {
    private $lastUsage = ['input'=>0, 'output'=>0];
    public function getName(): string { return 'ChatGPT'; }
    public function getKey(): string { return 'chatgpt'; }
    public function isConfigured(): bool {
        $model = trim(getKey('chatgpt.model'));
        if (empty($model)) return false;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return false;
        return !empty(getKey('chatgpt.api_key'));
    }
    public function getLastUsage(): array { return $this->lastUsage; }

    public function callAPI($system, $user, $maxTokens = 8192): ?string {
        $this->lastUsage = ['input'=>0, 'output'=>0];
        $model = getKey('chatgpt.model', '');
        // ★ v7: 모델 미설정 시 명확한 에러 (gpt-4o 자동 폴백 방지)
        if (empty($model)) {
            write_log("❌ ChatGPT 모델이 설정되지 않았습니다! API 관리에서 모델을 선택하세요.");
            return null;
        }
        write_log("ChatGPT 모델 확인: {$model}");
        $maxTokens = clampMaxTokens($model, $maxTokens);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . getKey('chatgpt.api_key'),
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) { write_log("ChatGPT HTTP {$code}: " . mb_substr($resp, 0, 300)); return null; }
        $r = json_decode($resp, true);
        $this->lastUsage = ['input' => $r['usage']['prompt_tokens'] ?? 0, 'output' => $r['usage']['completion_tokens'] ?? 0];
        return $r['choices'][0]['message']['content'] ?? null;
    }
}

// ─── Gemini ───
class GeminiProvider implements AIProvider {
    private $lastUsage = ['input'=>0, 'output'=>0];
    public function getName(): string { return 'Gemini'; }
    public function getKey(): string { return 'gemini'; }
    public function isConfigured(): bool {
        $model = trim(getKey('gemini.model'));
        if (empty($model)) return false;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return false;
        return !empty(getKey('gemini.api_key'));
    }
    public function getLastUsage(): array { return $this->lastUsage; }

    public function callAPI($system, $user, $maxTokens = 8192): ?string {
        $this->lastUsage = ['input'=>0, 'output'=>0];
        $model = getKey('gemini.model', 'gemini-2.5-flash');
        $apiKey = getKey('gemini.api_key');
        $maxTokens = clampMaxTokens($model, $maxTokens);
        $isPro = (strpos($model, 'pro') !== false);
        write_log("Gemini 모델: {$model}" . ($isPro ? " (Pro 모드)" : ""));

        // ── Pro vs Flash 설정 분기 ──
        // Pro: thinking 활성화 (최소 1024, 여유 있게 8192), 긴 타임아웃
        // Flash: thinking 비활성화, 일반 타임아웃
        $genConfig = ['maxOutputTokens' => $maxTokens];
        if ($isPro) {
            // ★ v4: thinkingBudget 축소 (8192→4096) — 블로그 글 생성은 thinking보다 출력이 중요
            $genConfig['thinkingConfig'] = ['thinkingBudget' => 4096];
            $timeout = 600;       // Pro: 10분
            $connectTimeout = 30;
            $maxRetries = 2;
        } else {
            $genConfig['thinkingConfig'] = ['thinkingBudget' => 0];
            $timeout = 180;       // Flash: 3분
            $connectTimeout = 15; // 연결 타임아웃 15초
            $maxRetries = 3;      // Flash 재시도 3회 (503 과부하 대응)
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['parts' => [['text' => $user]]]],
            'generationConfig' => $genConfig,
        ]);

        // ── 재시도 루프 (Pro 504/503/타임아웃 대응) ──
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $waitSec = $attempt * 10; // 10초, 20초 대기 후 재시도
                write_log("Gemini 재시도 {$attempt}/{$maxRetries} ({$waitSec}초 대기)");
                sleep($waitSec);
                // 재시도 시 thinkingBudget 줄여서 속도 확보
                if ($isPro) {
                    $genConfig['thinkingConfig']['thinkingBudget'] = max(1024, 8192 - ($attempt * 2048));
                    $payload = json_encode([
                        'system_instruction' => ['parts' => [['text' => $system]]],
                        'contents' => [['parts' => [['text' => $user]]]],
                        'generationConfig' => $genConfig,
                    ]);
                }
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                // TCP keepalive: 장시간 연결 유지
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 60,
                CURLOPT_TCP_KEEPINTVL => 30,
                // ✅ Gemini 2.5 Flash는 thinking 중 60~90초간 무응답 → LOW_SPEED 제거
                // (CURLOPT_TIMEOUT이 최종 안전망 역할)
                // CURLOPT_LOW_SPEED_LIMIT => 1,  ← 제거
                // CURLOPT_LOW_SPEED_TIME => 60,   ← 제거
            ]);

            $startTime = microtime(true);
            $resp = curl_exec($ch);
            $elapsed = round(microtime(true) - $startTime, 1);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            write_log("Gemini 응답: HTTP {$code} | {$elapsed}초 소요");

            // cURL 자체 에러 (타임아웃 등)
            if ($err) {
                write_log("Gemini cURL 에러: {$err}");
                if ($attempt < $maxRetries) continue; // 재시도
                return null;
            }

            // 504/503/429: 서버 과부하/타임아웃 → 재시도 대상
            if (in_array($code, [503, 504, 429])) {
                write_log("Gemini HTTP {$code} (서버 과부하/타임아웃): " . mb_substr($resp, 0, 300));
                if ($attempt < $maxRetries) continue; // 재시도
                return null;
            }

            // 기타 에러
            if ($code !== 200) {
                write_log("Gemini HTTP {$code}: " . mb_substr($resp, 0, 500));
                return null; // 4xx 에러는 재시도 의미 없음
            }

            // 성공 → 파싱
            $r = json_decode($resp, true);
            unset($resp); // ✅ RAM 해제: 응답 파싱 후 원본 문자열 즉시 해제
            if (!$r) { write_log("Gemini JSON 파싱 실패"); return null; }

            // 토큰 사용량
            $um = $r['usageMetadata'] ?? [];
            $this->lastUsage = [
                'input' => $um['promptTokenCount'] ?? 0,
                'output' => $um['candidatesTokenCount'] ?? 0
            ];
            $thinkTokens = $um['thoughtsTokenCount'] ?? 0;
            if ($thinkTokens > 0) {
                write_log("Gemini thinking 토큰: {$thinkTokens}");
            }

            $parts = $r['candidates'][0]['content']['parts'] ?? [];
            if (empty($parts)) {
                // finishReason 체크
                $finishReason = $r['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                write_log("Gemini 응답에 parts 없음 (finishReason: {$finishReason})");
                if ($finishReason === 'RECITATION' || $finishReason === 'SAFETY') return null;
                if ($attempt < $maxRetries) continue;
                return null;
            }

            // thinking이 아닌 실제 텍스트 추출
            $text = null;
            foreach (array_reverse($parts) as $part) {
                if (isset($part['text']) && (!isset($part['thought']) || $part['thought'] !== true)) {
                    $text = $part['text']; break;
                }
            }
            if (!$text) {
                foreach ($parts as $part) {
                    if (isset($part['text'])) { $text = $part['text']; break; }
                }
            }
            return $text;
        }

        return null;
    }
}


// ═════════════════════════════════════════
// AI 라우터 (일일 한도 + 로테이션)
// ═════════════════════════════════════════

class AIRouter {
    private $providers = [];

    public function __construct() {
        $all = [
            'claude' => new ClaudeProvider(),
            'grok' => new GrokProvider(),
            'chatgpt' => new ChatGPTProvider(),
            'gemini' => new GeminiProvider(),
        ];

        $rotation = getKey('ai_rotation', ['claude']);
        if (!is_array($rotation)) $rotation = ['claude'];

        foreach ($rotation as $name) {
            if (isset($all[$name]) && $all[$name]->isConfigured()) {
                $this->providers[] = $all[$name];
            }
        }

        if (empty($this->providers)) {
            foreach ($all as $p) {
                if ($p->isConfigured()) $this->providers[] = $p;
            }
        }
    }

    public function getCurrentProvider(): ?AIProvider {
        if (empty($this->providers)) return null;
        $count = file_exists(COUNTER_FILE) ? intval(file_get_contents(COUNTER_FILE)) : 0;
        $idx = $count % count($this->providers);
        return $this->providers[$idx];
    }

    public function incrementCounter() {
        $count = file_exists(COUNTER_FILE) ? intval(file_get_contents(COUNTER_FILE)) : 0;
        file_put_contents(COUNTER_FILE, $count + 1);
    }

    /**
     * 글 생성 (일일 한도 체크 + 폴백)
     */
    public function generateBlogPost($keyword, $naver_data = [], $internal_links = [], $random = false, $contentMin = 3000, $contentMax = 5000, $imageCount = 2) {
        if (empty($this->providers)) {
            write_log("설정된 AI가 없습니다!");
            return null;
        }

        $prompts = PromptData::buildPrompts($keyword, $naver_data, $internal_links, $contentMin, $contentMax, null, $imageCount);
        // ★ v4: 한글 1자 ≈ 2~3 토큰 + JSON 오버헤드 + thinking 여유분 → 대폭 상향
        $maxTokens = max(16384, min(65536, (int)($contentMax * 3.5) + 4000));
        write_log("프롬프트 분량 설정: {$contentMin}~{$contentMax}자 → maxTokens:{$maxTokens}");
        $count = file_exists(COUNTER_FILE) ? intval(file_get_contents(COUNTER_FILE)) : 0;
        $total = count($this->providers);

        // 랜덤 모드: 순서를 섞음
        $indices = range(0, $total - 1);
        if ($random) {
            shuffle($indices);
            write_log("AI 랜덤 모드 활성");
        } else {
            // 순번 로테이션
            $reordered = [];
            for ($i = 0; $i < $total; $i++) $reordered[] = ($count + $i) % $total;
            $indices = $reordered;
        }

        foreach ($indices as $attempt => $idx) {
            $provider = $this->providers[$idx];
            $name = $provider->getName();
            $aiKey = $provider->getKey();

            if (!checkAiDailyLimit($aiKey)) {
                $keys = loadApiKeys();
                write_log("{$name} 일일 한도 초과 → 다음 AI");
                continue;
            }

            write_log("AI 호출: {$name}" . ($attempt > 0 ? " (폴백 #{$attempt})" : ($random ? " (랜덤)" : " (순서 #{$count})")) . " [max_tokens:{$maxTokens}]");

            $response = $provider->callAPI($prompts['system'], $prompts['user'], $maxTokens);
            if ($response) {
                $data = $this->parseJSON($response);
                if ($data) {
                    $data['content_html'] = $this->mdToHtml($data['content']);
                    $data['_provider'] = strtolower($name);
                    write_log("{$name} 글 생성 완료: {$data['title']}");
                    $this->incrementCounter();
                    incrementAiDailyCount($aiKey);
                    $usage = $provider->getLastUsage();
                    if ($usage['input'] > 0 || $usage['output'] > 0) {
                        $cost = trackAiCost($aiKey, $usage['input'], $usage['output']);
                        write_log(sprintf("토큰: in=%d out=%d | 비용: $%.4f", $usage['input'], $usage['output'], $cost));
                    }
                    return $data;
                }
            }
            write_log("{$name} 실패 → 다음 AI 시도");
        }

        write_log("모든 AI 실패");
        $this->incrementCounter();
        return null;
    }

    /**
     * 특정 AI 프로바이더로 직접 생성
     * ★ v7: 설정값 엄격 준수
     *   - isConfigured() false면 호출 안 함 (다른 AI로 폴백 안 함)
     *   - 실패 시 같은 모델로 10초 후 1회만 재시도
     *   - 다른 플랫폼, 다른 모델로 절대 바꾸지 않음
     */
    public function generateWithProvider($keyword, $providerKey, $naver_data = [], $internal_links = [], $contentMin = 3000, $contentMax = 5000, $imageCount = 2) {
        $providerMap = ['claude'=>new ClaudeProvider(),'grok'=>new GrokProvider(),'chatgpt'=>new ChatGPTProvider(),'gemini'=>new GeminiProvider()];
        $provider = $providerMap[$providerKey] ?? null;

        if (!$provider || !$provider->isConfigured()) {
            write_log("⚠️ {$providerKey} 사용 불가 (미설정 또는 '사용 안 함') → 글 생성 중단");
            return null;
        }
        if (!checkAiDailyLimit($providerKey)) {
            write_log("⚠️ {$providerKey} 일일 한도 초과 → 글 생성 중단");
            return null;
        }

        $prompts = PromptData::buildPrompts($keyword, $naver_data, $internal_links, $contentMin, $contentMax, null, $imageCount);
        $maxTokens = max(16384, min(65536, (int)($contentMax * 3.5) + 4000));
        $model = getKey("{$providerKey}.model");
        write_log("AI 호출: {$provider->getName()} (지정) [모델:{$model}, max_tokens:{$maxTokens}]");

        // ── 1차 시도 ──
        $result = $this->tryCallAndParse($provider, $providerKey, $prompts, $maxTokens);
        if ($result) return $result;

        // ── 2차 시도: 같은 모델로 10초 후 1회만 재시도 ──
        write_log("🔄 {$providerKey}({$model}) 1차 실패 → 같은 모델로 10초 후 재시도...");
        sleep(10);
        $result = $this->tryCallAndParse($provider, $providerKey, $prompts, $maxTokens);
        if ($result) return $result;

        write_log("❌ {$providerKey}({$model}) 최종 실패 → 글 생성 중단");
        return null;
    }

    /**
     * API 호출 + JSON 파싱 + 비용 기록 헬퍼
     */
    private function tryCallAndParse($provider, $providerKey, $prompts, $maxTokens) {
        $response = $provider->callAPI($prompts['system'], $prompts['user'], $maxTokens);
        if (!$response) {
            write_log("{$provider->getName()} API 응답 없음");
            return null;
        }

        $data = $this->parseJSON($response);
        if (!$data) {
            write_log("{$provider->getName()} JSON 파싱 실패");
            return null;
        }

        $data['content_html'] = $this->mdToHtml($data['content']);
        $data['_provider'] = $providerKey;
        write_log("{$provider->getName()} 글 생성 완료: {$data['title']}");
        incrementAiDailyCount($providerKey);

        $usage = $provider->getLastUsage();
        if ($usage['input'] > 0 || $usage['output'] > 0) {
            $cost = trackAiCost($providerKey, $usage['input'], $usage['output']);
            write_log(sprintf("토큰: in=%d out=%d | 비용: $%.4f", $usage['input'], $usage['output'], $cost));
        }
        return $data;
    }

    public function testAll() {
        $all = [
            'claude' => new ClaudeProvider(), 'grok' => new GrokProvider(),
            'chatgpt' => new ChatGPTProvider(), 'gemini' => new GeminiProvider(),
        ];
        $results = [];
        foreach ($all as $key => $provider) {
            if (!$provider->isConfigured()) {
                $results[$key] = ['status' => 'skip', 'msg' => '키 미입력'];
                continue;
            }
            $keys = loadApiKeys();
            $limit = $keys[$key]['daily_limit'] ?? 100;
            $used = $keys[$key]['today_count'] ?? 0;
            $resp = $provider->callAPI("테스트", "안녕 이라고만 답해");
            $results[$key] = $resp
                ? ['status' => 'ok', 'msg' => mb_substr($resp, 0, 30) . " ({$used}/{$limit})"]
                : ['status' => 'fail', 'msg' => "응답 없음 ({$used}/{$limit})"];
        }
        return $results;
    }

    public function getRotationInfo() {
        $names = array_map(fn($p) => $p->getName(), $this->providers);
        $count = file_exists(COUNTER_FILE) ? intval(file_get_contents(COUNTER_FILE)) : 0;
        $current = !empty($names) ? $names[$count % count($names)] : '없음';
        return ['order' => $names, 'next' => $current, 'count' => $count];
    }

    public function getDailyStatus() {
        $keys = loadApiKeys();
        $status = [];
        foreach (['claude','grok','chatgpt','gemini'] as $ai) {
            $today = date('Y-m-d');
            $reset = $keys[$ai]['last_reset'] ?? '';
            $status[$ai] = [
                'limit' => intval($keys[$ai]['daily_limit'] ?? 100),
                'used' => ($reset === $today) ? intval($keys[$ai]['today_count'] ?? 0) : 0,
            ];
        }
        return $status;
    }

    private function parseJSON($resp) {
        if (preg_match('/```json\s*(.*?)\s*```/s', $resp, $m)) $json = $m[1];
        elseif (preg_match('/\{[\s\S]*"title"[\s\S]*"content"[\s\S]*\}/s', $resp, $m)) $json = $m[0];
        else $json = $resp;
        $data = json_decode($json, true);
        if (!$data) { $data = json_decode(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $json), true); }
        if (!$data || empty($data['title']) || empty($data['content'])) return null;

        // ★ v5: AI 말투 후처리 — 잔존하는 AI 표현 자동 치환
        $data['content'] = $this->removeAiExpressions($data['content']);

        // ★ v5: 제목 상투적 패턴 검증 및 로그
        $badTitlePatterns = [
            '/인 줄 알았/', '/의 모든 것/', '/충격적인/', '/당신이 몰랐던/',
            '/완벽 정리/', '/완벽 가이드/', '/소름 돋는/', '/파헤치다/',
            '/의 비밀/', '/놀라운 사실/', '/7가지 진실/',
        ];
        foreach ($badTitlePatterns as $pat) {
            if (preg_match($pat, $data['title'])) {
                write_log("⚠️ 상투적 제목 패턴 감지: {$data['title']}");
                break;
            }
        }

        // ★ v4: slug 영문 강제 변환 — 한글이 포함되면 제목 기반으로 영문 slug 생성
        if (!empty($data['slug'])) {
            // 한글/비ASCII 문자가 포함되어 있으면 영문으로 변환
            if (preg_match('/[^\x20-\x7E]/', $data['slug'])) {
                $data['slug'] = $this->generateEnglishSlug($data['title'], $data['focus_keyphrase'] ?? '');
                write_log("⚠️ 한글 slug 감지 → 영문 변환: {$data['slug']}");
            }
        }
        // slug가 비어있으면 생성
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateEnglishSlug($data['title'], $data['focus_keyphrase'] ?? '');
        }
        // slug 정규화 (소문자, 하이픈, 특수문자 제거)
        $data['slug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $data['slug'])));
        $data['slug'] = preg_replace('/-+/', '-', trim($data['slug'], '-'));
        // 너무 길면 자르기 (최대 60자)
        if (strlen($data['slug']) > 60) {
            $data['slug'] = substr($data['slug'], 0, 60);
            $data['slug'] = preg_replace('/-[^-]*$/', '', $data['slug']); // 단어 중간 잘림 방지
        }

        return $data;
    }

    /**
     * ★ v6: AI 표현 자동 제거/치환 (대폭 확대)
     * 프롬프트로 막아도 남는 AI 표현들을 후처리로 제거
     */
    private function removeAiExpressions($content) {
        $replacements = [
            // 시작/전환 상투어
            '/살펴보겠습니다/' => '정리해봤습니다',
            '/알아보겠습니다/' => '정리해봤습니다',
            '/알아볼까요/' => '한번 볼까요',
            '/살펴볼까요/' => '한번 볼까요',
            '/확인해볼까요/' => '확인해보죠',
            '/결론적으로/' => '정리하면',
            '/종합적으로/' => '다시 보면',
            '/마무리하자면/' => '정리하면',
            '/요약하자면/' => '간단히 말하면',
            '/함께 알아보겠습니다/' => '같이 정리해보죠',
            '/자세히 다뤄보겠습니다/' => '좀 더 깊이 들어가보면',
            '/에 대해 알아보겠습니다/' => '에 대해 정리해봤습니다',
            // 번역체/형용사 남발
            '/다양한\s/' => '여러 ',
            '/효과적인\s/' => '괜찮은 ',
            '/체계적인\s/' => '정리된 ',
            '/종합적인\s/' => '전체적인 ',
            '/필수적입니다/' => '꼭 필요합니다',
            '/핵심입니다/' => '포인트입니다',
            // 전환어
            '/그렇다면\s/' => '그러면 ',
            '/이처럼\s/' => '이렇게 ',
            '/이를 통해\s/' => '이걸로 ',
            '/이에 따라\s/' => '그래서 ',
            // 마무리 상투어
            '/마지막으로,?\s/' => '그리고 ',
            '/끝으로,?\s/' => '참고로 ',
            // ~라고 할 수 있습니다 패턴
            '/라고 할 수 있습니다/' => '라고 봅니다',
            '/라고 볼 수 있습니다/' => '라고 보면 됩니다',
            // 의 경우/에 있어서 번역체
            '/의 경우에는/' => '는',
            '/에 있어서/' => '에서',
        ];

        $found = [];
        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $content)) {
                $found[] = trim($pattern, '/');
                $content = preg_replace($pattern, $replacement, $content);
            }
        }

        if (!empty($found)) {
            write_log("⚠️ AI 표현 자동 치환: " . implode(', ', $found));
        }

        return $content;
    }

    /**
     * 제목/키워드에서 영문 slug 생성
     */
    private function generateEnglishSlug($title, $keyword = '') {
        // 영문 단어만 추출
        $source = $keyword ?: $title;
        preg_match_all('/[a-zA-Z0-9]+/', $source, $matches);
        if (!empty($matches[0])) {
            return strtolower(implode('-', array_slice($matches[0], 0, 5)));
        }
        // 영문이 전혀 없으면 타임스탬프 기반
        return 'post-' . date('Ymd-His');
    }

    /**
     * 마크다운 → Gutenberg 블록 HTML 변환
     * 각 요소가 개별 블록으로 인식되어 WP 편집기에서 쉽게 편집 가능
     * v5: CTA 버튼 블록, H2/H3 텍스트 표기 자동 수정, 테이블 3열 강제
     */
    private function mdToHtml($md) {
        // ★ v5: 전처리 — AI가 "H2: 제목", "H3: 제목" 텍스트로 출력한 경우 마크다운으로 변환
        $md = preg_replace('/^H2:\s*(.+)$/m', '## $1', $md);
        $md = preg_replace('/^H3:\s*(.+)$/m', '### $1', $md);
        // "**소제목**" 패턴을 ## 으로 변환 (단독 줄에 **텍스트**만 있는 경우)
        $md = preg_replace('/^\*\*([^*]{4,40})\*\*\s*$/m', '## $1', $md);

        $lines = explode("\n", $md);
        $blocks = [];
        $buffer = [];
        $inList = false;    // ul 목록 수집 중
        $inOList = false;   // ol 목록 수집 중
        $inTable = false;   // 테이블 수집 중
        $tableRows = [];

        $flush = function() use (&$buffer, &$blocks) {
            if (empty($buffer)) return;
            $text = trim(implode("\n", $buffer));
            $buffer = [];
            if (!$text) return;
            // 인라인 마크다운 처리
            $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
            $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
            $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
            $blocks[] = "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
        };

        $flushList = function() use (&$buffer, &$blocks, &$inList) {
            if (empty($buffer)) return;
            $items = '';
            foreach ($buffer as $li) {
                $li = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $li);
                $li = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $li);
                $li = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $li);
                $items .= "<li>{$li}</li>";
            }
            $blocks[] = "<!-- wp:list -->\n<ul>{$items}</ul>\n<!-- /wp:list -->";
            $buffer = [];
            $inList = false;
        };

        $flushOList = function() use (&$buffer, &$blocks, &$inOList) {
            if (empty($buffer)) return;
            $items = '';
            foreach ($buffer as $li) {
                $li = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $li);
                $li = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $li);
                $li = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $li);
                $items .= "<li>{$li}</li>";
            }
            $blocks[] = "<!-- wp:list {\"ordered\":true} -->\n<ol>{$items}</ol>\n<!-- /wp:list -->";
            $buffer = [];
            $inOList = false;
        };

        $flushTable = function() use (&$tableRows, &$blocks, &$inTable) {
            if (empty($tableRows)) return;
            $head = ''; $body = ''; $skipNext = false;
            foreach ($tableRows as $ri => $row) {
                // |로 분리 후 앞뒤 빈 요소만 제거 (중간 빈 셀은 유지)
                $raw = explode('|', trim($row));
                // 앞뒤 빈 요소 제거 (| 시작/끝에 의한 것)
                if (isset($raw[0]) && trim($raw[0]) === '') array_shift($raw);
                if (!empty($raw) && trim(end($raw)) === '') array_pop($raw);
                $cells = array_map('trim', $raw);
                if (empty($cells)) continue;
                // 구분선 스킵 (---  :---:  등)
                if (preg_match('/^[\s\-:|]+$/', implode('', $cells))) { $skipNext = true; continue; }
                
                // ★ v5: 4열 이상이면 3열로 강제 자르기
                if (count($cells) > 3) {
                    write_log("⚠️ 테이블 " . count($cells) . "열 감지 → 3열로 자름");
                    $cells = array_slice($cells, 0, 3);
                }
                
                $isHead = ($ri === 0 || (!$head && !$skipNext));
                $tag = $isHead && !$head ? 'th' : 'td';
                $tr = '<tr>';
                foreach ($cells as $c) {
                    $c = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $c);
                    $c = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $c);
                    $tr .= "<{$tag}>{$c}</{$tag}>";
                }
                $tr .= '</tr>';
                if ($tag === 'th') $head .= $tr; else $body .= $tr;
                $skipNext = false;
            }
            $tableHtml = '<figure class="wp-block-table"><table>';
            if ($head) $tableHtml .= "<thead>{$head}</thead>";
            if ($body) $tableHtml .= "<tbody>{$body}</tbody>";
            $tableHtml .= '</table></figure>';
            $blocks[] = "<!-- wp:table -->\n{$tableHtml}\n<!-- /wp:table -->";
            $tableRows = [];
            $inTable = false;
        };

        foreach ($lines as $line) {
            $t = trim($line);

            // 빈 줄: 현재 버퍼 플러시
            if ($t === '') {
                if ($inList) $flushList();
                elseif ($inOList) $flushOList();
                elseif ($inTable) $flushTable();
                else $flush();
                continue;
            }

            // 테이블 행 (|로 시작하면 테이블로 인식 — 끝 |는 선택적)
            if (preg_match('/^\|.+/', $t) && substr_count($t, '|') >= 2) {
                if ($inList) $flushList();
                elseif ($inOList) $flushOList();
                elseif (!empty($buffer)) $flush();
                $inTable = true;
                $tableRows[] = $t;
                continue;
            }
            if ($inTable) $flushTable();

            // 헤딩
            if (preg_match('/^#{2,4}\s+(.+)$/', $t, $m)) {
                if ($inList) $flushList();
                elseif ($inOList) $flushOList();
                else $flush();
                $level = strlen(strtok($t, ' '));
                $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $m[1]);
                $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level}>{$text}</h{$level}>\n<!-- /wp:heading -->";
                continue;
            }

            // 비순서 리스트 항목
            if (preg_match('/^[\-\*]\s+(.+)$/', $t, $m)) {
                if ($inOList) $flushOList();
                elseif (!$inList && !empty($buffer)) $flush();
                $inList = true;
                $buffer[] = $m[1];
                continue;
            }
            if ($inList) $flushList();

            // 순서 리스트 항목
            if (preg_match('/^\d+\.\s+(.+)$/', $t, $m)) {
                if (!$inOList && !empty($buffer)) $flush();
                $inOList = true;
                $buffer[] = $m[1];
                continue;
            }
            if ($inOList) $flushOList();

            // [IMAGE:...] 태그 (나중에 processImages에서 처리)
            if (preg_match('/^\[IMAGE:/', $t)) {
                $flush();
                $blocks[] = $t; // 그대로 유지, processImages에서 블록으로 변환
                continue;
            }

            // ★ v5.1: [BUTTON:텍스트|URL] → 순수 HTML 버튼 (WordPress + 자체CMS 모두 호환)
            if (preg_match('/^\[BUTTON:\s*(.+?)\s*\|\s*(https?:\/\/[^\]]+)\s*\]$/', $t, $bm)) {
                $flush();
                $btnText = trim($bm[1]);
                $btnUrl = trim($bm[2]);
                $blocks[] = "<div style=\"text-align:center;margin:28px 0\"><a href=\"{$btnUrl}\" target=\"_blank\" rel=\"noopener\" style=\"display:inline-block;padding:14px 32px;background:#4a6cf7;color:#fff;text-decoration:none;border-radius:8px;font-size:15px;font-weight:600;box-shadow:0 2px 8px rgba(74,108,247,.3);transition:background .2s\">{$btnText}</a></div>";
                continue;
            }

            // 일반 텍스트 → 문단 버퍼에 추가
            $buffer[] = $t;
        }

        // 남은 버퍼 플러시
        if ($inList) $flushList();
        elseif ($inOList) $flushOList();
        elseif ($inTable) $flushTable();
        else $flush();

        return implode("\n\n", array_filter($blocks));
    }
}
