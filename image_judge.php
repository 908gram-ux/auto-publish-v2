<?php
/**
 * ★ v8: AI 이미지 판별기 (ImageJudge)
 *
 * 네이버/구글에서 수집한 이미지 후보들을 "실제로 눈으로 보고" 글 내용과 맞는지 판단합니다.
 * - 비전(이미지 인식) 가능한 AI 모델에 후보 이미지(축소본)를 한 번에 보내서
 *   각 이미지의 관련도 점수(0~10), 글자/워터마크 여부, 썸네일 적합 여부를 JSON으로 받습니다.
 * - 순서: Gemini → ChatGPT → Grok → Claude (키가 있는 것 중 먼저 성공하는 것 사용)
 * - 모든 AI가 실패하면 null 반환 → 호출 쪽에서 기존 방식(16:9 비율)으로 폴백
 *
 * 사용:
 *   $judge = new ImageJudge();
 *   $scores = $judge->judge($candidates, ['title'=>..., 'keyword'=>..., 'summary'=>..., 'image_descs'=>[...]]);
 *   // $scores[$idx] = ['score'=>8, 'thumb_ok'=>true, 'has_text'=>false, 'reason'=>'...']
 */

require_once __DIR__ . '/config.php';

class ImageJudge {

    /** AI에 보낼 축소본 최대 변 길이(px). 작을수록 토큰 절약 */
    private $previewSize = 512;

    /** 한 번에 판별할 최대 후보 수 */
    private $maxCandidates = 12;

    /**
     * 이미지 URL 다운로드 → 원본 저장 (변조/리사이즈 없음)
     * 판별용 축소본과 실제 사용 원본을 한 번의 다운로드로 해결하기 위해 공개 메서드로 둠.
     * @return string|null 로컬 원본 경로
     */
    public function downloadRaw($url) {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return null;

        $referer = (stripos($url, 'naver') !== false || stripos($url, 'pstatic') !== false)
            ? 'https://search.naver.com/' : 'https://www.google.com/';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer: ' . $referer,
            ],
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code !== 200 || strlen((string)$data) < 5000) {
            write_log("⚠️ 이미지 다운로드 실패: HTTP {$code}, " . strlen((string)$data) . " bytes ← " . substr($url, 0, 80));
            return null;
        }

        $saveDir = defined('IMAGE_SAVE_DIR') ? IMAGE_SAVE_DIR : (__DIR__ . '/tmp_images/');
        if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
        $path = $saveDir . 'web_raw_' . time() . '_' . mt_rand(1000, 9999);
        if (str_contains($contentType, 'webp')) $path .= '.webp';
        elseif (str_contains($contentType, 'png')) $path .= '.png';
        elseif (str_contains($contentType, 'gif')) $path .= '.gif';
        else $path .= '.jpg';
        file_put_contents($path, $data);

        $info = @getimagesize($path);
        if (!$info || $info[0] < 200 || $info[1] < 150) {
            @unlink($path);
            write_log("⚠️ 이미지 유효하지 않음/너무 작음 (" . ($info[0] ?? 0) . "x" . ($info[1] ?? 0) . ")");
            return null;
        }
        return $path;
    }

    /**
     * 축소본(JPEG) 생성 → base64 반환. GD 사용.
     * @return array|null ['b64'=>..., 'mime'=>'image/jpeg', 'w'=>원본W, 'h'=>원본H]
     */
    private function makePreview($path) {
        $info = @getimagesize($path);
        if (!$info) return null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($path); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($path); break;
            default: return null;
        }
        if (!$src) return null;

        $w = $info[0]; $h = $info[1];
        $ratio = min(1, $this->previewSize / max($w, $h));
        $nw = max(1, (int)($w * $ratio)); $nh = max(1, (int)($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white); // 투명 PNG 배경 흰색
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($dst, null, 70);
        $bin = ob_get_clean();
        imagedestroy($dst);
        if (!$bin) return null;
        return ['b64' => base64_encode($bin), 'mime' => 'image/jpeg', 'w' => $w, 'h' => $h];
    }

    /**
     * 후보 이미지 판별
     *
     * @param array $candidates [['url'=>..., 'local_raw'=>로컬경로(있으면), 'title'=>..., 'source'=>...], ...]
     * @param array $ctx ['title'=>글제목, 'keyword'=>키워드, 'summary'=>글요약, 'image_descs'=>[본문 [IMAGE:] 설명들]]
     * @return array|null  [$idx => ['score'=>int, 'thumb_ok'=>bool, 'has_text'=>bool, 'reason'=>string]]  (AI 전부 실패 시 null)
     */
    public function judge(array $candidates, array $ctx) {
        if (empty($candidates)) return null;

        // 1) 축소본 준비
        $previews = [];   // [idx => preview]
        foreach ($candidates as $idx => $c) {
            if (count($previews) >= $this->maxCandidates) break;
            $path = $c['local_raw'] ?? null;
            if (!$path || !file_exists($path)) continue;
            $pv = $this->makePreview($path);
            if ($pv) $previews[$idx] = $pv;
        }
        if (empty($previews)) {
            write_log("⚠️ AI 이미지 판별: 판별 가능한 후보 없음");
            return null;
        }

        // 2) 프롬프트
        $title   = trim($ctx['title'] ?? '');
        $keyword = trim($ctx['keyword'] ?? '');
        $summary = mb_substr(trim($ctx['summary'] ?? ''), 0, 700);
        $descs   = array_slice(array_filter(array_map('trim', $ctx['image_descs'] ?? [])), 0, 6);

        $system = "당신은 블로그 편집자입니다. 블로그 글의 제목/요약을 읽고, 제공된 이미지들을 하나씩 보고 "
                . "각 이미지가 이 글의 '대표 이미지(썸네일)' 및 본문 이미지로 얼마나 적합한지 평가합니다. "
                . "반드시 JSON만 출력하세요. 설명 문장, 마크다운 코드블록, 기타 텍스트를 절대 붙이지 마세요.";

        $user  = "## 글 정보\n";
        $user .= "- 키워드: {$keyword}\n";
        $user .= "- 제목: {$title}\n";
        if ($summary) $user .= "- 요약: {$summary}\n";
        if ($descs)   $user .= "- 본문에서 필요한 이미지 설명: " . implode(' / ', $descs) . "\n";
        $user .= "\n## 이미지\n이미지는 첨부된 순서대로 번호 1, 2, 3... 입니다. 총 " . count($previews) . "장.\n\n";
        $user .= "## 평가 기준\n";
        $user .= "- score (0~10): 글의 주제·분위기와 얼마나 맞는가. 주제와 무관(지도, 광고, 메뉴판, 캡처화면, 로고, 배너, 프로필사진, 다른 제품/인물)이면 0~2.\n";
        $user .= "- thumb_ok (true/false): 대표 이미지로 써도 되는가. 가로형이고, 글자/워터마크/자막/말풍선이 거의 없고, 주제를 한눈에 보여주는 깨끗한 사진일 때만 true. 세로형, 캡처화면, 표/그래프, 글자 가득한 이미지는 false.\n";
        $user .= "- has_text (true/false): 이미지 안에 글자/자막/워터마크/로고가 눈에 띄게 있는가.\n";
        $user .= "- reason: 10~20자 한국어 한 줄 이유.\n\n";
        $user .= "## 출력 형식 (JSON만)\n";
        $user .= '{"results":[{"n":1,"score":8,"thumb_ok":true,"has_text":false,"reason":"..."},{"n":2,...}]}';

        // 3) 모델 순서대로 시도
        $order = ['gemini', 'chatgpt', 'grok', 'claude'];
        $raw = null; $usedBy = '';
        foreach ($order as $p) {
            $raw = null;
            switch ($p) {
                case 'gemini':  $raw = $this->callGemini($system, $user, $previews); break;
                case 'chatgpt': $raw = $this->callOpenAICompatible('chatgpt', $system, $user, $previews); break;
                case 'grok':    $raw = $this->callOpenAICompatible('grok', $system, $user, $previews); break;
                case 'claude':  $raw = $this->callClaude($system, $user, $previews); break;
            }
            if ($raw) { $usedBy = $p; break; }
        }
        if (!$raw) {
            write_log("⚠️ AI 이미지 판별 실패 (모든 비전 모델 실패) → 비율 기반 폴백");
            return null;
        }

        // 4) 파싱
        $json = $raw;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $raw, $m)) $json = $m[1];
        elseif (preg_match('/\{[\s\S]*\}/', $raw, $m)) $json = $m[0];
        $data = json_decode($json, true);
        if (!$data || !isset($data['results']) || !is_array($data['results'])) {
            write_log("⚠️ AI 이미지 판별 JSON 파싱 실패 ({$usedBy}): " . mb_substr($raw, 0, 200));
            return null;
        }

        $idxList = array_keys($previews); // n(1-based) → 실제 후보 idx
        $out = [];
        foreach ($data['results'] as $r) {
            $n = intval($r['n'] ?? 0);
            if ($n < 1 || $n > count($idxList)) continue;
            $idx = $idxList[$n - 1];
            $score = max(0, min(10, intval($r['score'] ?? 0)));
            $thumbOk = filter_var($r['thumb_ok'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hasText = filter_var($r['has_text'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // 가로형이 아니면 썸네일 부적합으로 강제
            $pw = $previews[$idx]['w']; $ph = $previews[$idx]['h'];
            if ($ph > 0 && ($pw / $ph) < 1.05) $thumbOk = false;
            $out[$idx] = [
                'score' => $score,
                'thumb_ok' => $thumbOk,
                'has_text' => $hasText,
                'reason' => mb_substr(trim((string)($r['reason'] ?? '')), 0, 40),
            ];
        }
        // 판별 누락된 후보는 낮은 점수
        foreach ($previews as $idx => $_) {
            if (!isset($out[$idx])) $out[$idx] = ['score' => 1, 'thumb_ok' => false, 'has_text' => false, 'reason' => '판별 누락'];
        }

        $logParts = [];
        foreach ($out as $idx => $s) $logParts[] = "#{$idx}:{$s['score']}점" . ($s['thumb_ok'] ? '★' : '') . "({$s['reason']})";
        write_log("🧠 AI 이미지 판별 완료 [{$usedBy}] → " . implode(', ', $logParts));
        return $out;
    }

    // ───────────────────── 비전 API 호출부 ─────────────────────

    private function callGemini($system, $user, $previews) {
        $apiKey = getKey('gemini.api_key');
        if (!$apiKey) return null;
        $model = getKey('gemini.model', 'gemini-2.5-flash');
        // 이미지 생성 모델이 잘못 들어가 있으면 flash로 교체
        if (stripos($model, 'image') !== false) $model = 'gemini-2.5-flash';

        $parts = [['text' => $user]];
        $n = 1;
        foreach ($previews as $pv) {
            $parts[] = ['text' => "이미지 {$n}:"];
            $parts[] = ['inline_data' => ['mime_type' => $pv['mime'], 'data' => $pv['b64']]];
            $n++;
        }
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.2, 'responseMimeType' => 'application/json'],
        ];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $resp = $this->post($url, ['Content-Type: application/json'], $payload, 'Gemini(vision)');
        if (!$resp) return null;
        $r = json_decode($resp, true);
        $parts = $r['candidates'][0]['content']['parts'] ?? [];
        foreach (array_reverse($parts) as $part) {
            if (isset($part['text']) && empty($part['thought'])) return $part['text'];
        }
        return null;
    }

    /** ChatGPT / Grok (OpenAI 호환 chat/completions) */
    private function callOpenAICompatible($which, $system, $user, $previews) {
        $apiKey = getKey("{$which}.api_key");
        $model  = trim(getKey("{$which}.model", ''));
        if (!$apiKey || !$model) return null;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return null;

        $content = [['type' => 'text', 'text' => $user]];
        $n = 1;
        foreach ($previews as $pv) {
            $content[] = ['type' => 'text', 'text' => "이미지 {$n}:"];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$pv['mime']};base64,{$pv['b64']}", 'detail' => 'low']];
            $n++;
        }
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
        ];
        $isNew = ($which === 'chatgpt' && preg_match('/gpt-5|o3-|o4-/', $model));
        if ($isNew) $body['max_completion_tokens'] = 2048; else $body['max_tokens'] = 2048;
        if (!$isNew) $body['temperature'] = 0.2;

        $url = $which === 'grok' ? 'https://api.x.ai/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $resp = $this->post($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey], $body, ucfirst($which) . '(vision)');
        if (!$resp) return null;
        $r = json_decode($resp, true);
        return $r['choices'][0]['message']['content'] ?? null;
    }

    private function callClaude($system, $user, $previews) {
        $apiKey = getKey('claude.api_key');
        $model  = trim(getKey('claude.model', ''));
        if (!$apiKey || !$model) return null;
        $disabled = ['사용 안 함', '사용안함', 'none', 'disabled', '미사용'];
        if (in_array($model, $disabled, true)) return null;

        $content = [['type' => 'text', 'text' => $user]];
        $n = 1;
        foreach ($previews as $pv) {
            $content[] = ['type' => 'text', 'text' => "이미지 {$n}:"];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $pv['mime'], 'data' => $pv['b64']]];
            $n++;
        }
        $body = [
            'model' => $model,
            'max_tokens' => 2048,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        $resp = $this->post('https://api.anthropic.com/v1/messages',
            ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'], $body, 'Claude(vision)');
        if (!$resp) return null;
        $r = json_decode($resp, true);
        return $r['content'][0]['text'] ?? null;
    }

    private function post($url, $headers, $body, $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $t = microtime(true);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $el = round(microtime(true) - $t, 1);
        if ($err) { write_log("⚠️ {$label} cURL 에러: {$err}"); return null; }
        if ($code !== 200) { write_log("⚠️ {$label} HTTP {$code} ({$el}초): " . mb_substr((string)$resp, 0, 300)); return null; }
        write_log("✅ {$label} 응답 OK ({$el}초)");
        return $resp;
    }
}
