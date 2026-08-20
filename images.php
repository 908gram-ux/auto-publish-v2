<?php
/**
 * 이미지: 스톡 검색, 생성, 최적화
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image_judge.php';


class StockImageSearch {

    /**
     * 키워드로 스톡 이미지 검색 & 다운로드
     * @param string $query 영문 검색어
     * @param string $type 'thumbnail' (1200x630) or 'section' (800x400)
     * @return string|null 로컬 저장 경로
     */
    public function search($query, $type = 'section', $excludeStock = false) {
        $keys = loadApiKeys();
        $imgSettings = $keys['image_source'] ?? [];
        $priority = $imgSettings['priority'] ?? ['pixabay', 'pexels', 'gradient'];
        write_log("🔍 이미지 검색어: \"{$query}\" (type: {$type})");

        // 1) 우선순위 소스 시도 (gradient 제외)
        $tried = [];
        foreach ($priority as $source) {
            if ($source === 'gradient') continue; // gradient는 최후 폴백
            // ★ v9.1: excludeStock=true면 무작위 스톡(pixabay/pexels) 건너뜀 — 글 내용 기반 생성(gemini/dalle)만 허용
            if ($excludeStock && in_array($source, ['pixabay', 'pexels'], true)) continue;
            $tried[] = $source;
            $path = $this->tryImageSource($source, $query, $type);
            if ($path) {
                write_log("이미지({$source}): {$query}");
                return $path;
            }
        }

        // 2) 우선순위에 없었던 나머지 API 소스도 자동 폴백
        $allSources = $excludeStock ? ['gemini', 'dalle'] : ['pixabay', 'pexels', 'gemini', 'dalle'];
        $remaining = array_diff($allSources, $tried);
        foreach ($remaining as $source) {
            $path = $this->tryImageSource($source, $query, $type);
            if ($path) {
                write_log("이미지({$source} 폴백): {$query}");
                return $path;
            }
        }

        return null; // 전부 실패 → 그라데이션 폴백
    }

    /** 이미지 소스별 호출 헬퍼 */
    private function tryImageSource($source, $query, $type) {
        switch ($source) {
            case 'pixabay': return $this->fromPixabay($query, $type);
            case 'pexels':  return $this->fromPexels($query, $type);
            case 'gemini':  return $this->fromGemini($query, $type);
            case 'dalle':   return $this->fromDalle($query, $type);
            default: return null;
        }
    }

    /**
     * Pixabay API 검색
     */
    private function fromPixabay($query, $type) {
        $apiKey = getKey('pixabay.api_key');
        if (!$apiKey) return null;

        $minW = $type === 'thumbnail' ? 1200 : 800;
        // ★ 검색어를 그대로 사용 (접미사 추가하면 관련 없는 이미지 반환됨)
        // 사람 제외는 Pixabay API 파라미터로 처리
        $url = 'https://pixabay.com/api/?' . http_build_query([
            'key' => $apiKey, 'q' => $query, 'image_type' => 'photo',
            'orientation' => 'horizontal', 'min_width' => $minW,
            'safesearch' => 'true', 'per_page' => 15, 'lang' => 'en',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        $hits = $data['hits'] ?? [];
        
        // ★ 결과 없으면 검색어 단순화해서 재시도 (단어 수 줄이기)
        if (empty($hits)) {
            $words = explode(' ', $query);
            if (count($words) > 2) {
                $shortQuery = implode(' ', array_slice($words, 0, 2));
                write_log("Pixabay 결과 없음 → 검색어 단순화: \"{$shortQuery}\"");
                $retryUrl = 'https://pixabay.com/api/?' . http_build_query([
                    'key' => $apiKey, 'q' => $shortQuery, 'image_type' => 'photo',
                    'orientation' => 'horizontal', 'min_width' => $minW,
                    'safesearch' => 'true', 'per_page' => 15, 'lang' => 'en',
                ]);
                $ch = curl_init($retryUrl);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
                $resp2 = curl_exec($ch); curl_close($ch);
                $data2 = json_decode($resp2, true);
                $hits = $data2['hits'] ?? [];
            }
        }
        if (empty($hits)) return null;

        // 랜덤 선택 (상위 5개 중)
        $pick = $hits[array_rand(array_slice($hits, 0, min(5, count($hits))))];
        $imgUrl = $pick['largeImageURL'] ?? $pick['webformatURL'] ?? null;
        if (!$imgUrl) return null;

        return $this->downloadAndConvert($imgUrl, $type);
    }

    /**
     * Pexels API 검색
     */
    private function fromPexels($query, $type) {
        $apiKey = getKey('pexels.api_key');
        if (!$apiKey) return null;

        $url = 'https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query, 'orientation' => 'landscape',
            'size' => 'large', 'per_page' => 15,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $apiKey],
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        $photos = $data['photos'] ?? [];
        
        // ★ 결과 없으면 검색어 단순화해서 재시도
        if (empty($photos)) {
            $words = explode(' ', $query);
            if (count($words) > 2) {
                $shortQuery = implode(' ', array_slice($words, 0, 2));
                write_log("Pexels 결과 없음 → 검색어 단순화: \"{$shortQuery}\"");
                $retryUrl = 'https://api.pexels.com/v1/search?' . http_build_query([
                    'query' => $shortQuery, 'orientation' => 'landscape',
                    'size' => 'large', 'per_page' => 15,
                ]);
                $ch = curl_init($retryUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Authorization: ' . $apiKey],
                ]);
                $resp2 = curl_exec($ch); curl_close($ch);
                $data2 = json_decode($resp2, true);
                $photos = $data2['photos'] ?? [];
            }
        }
        if (empty($photos)) return null;

        $pick = $photos[array_rand(array_slice($photos, 0, min(5, count($photos))))];
        $imgUrl = $pick['src']['large'] ?? $pick['src']['medium'] ?? null;
        if (!$imgUrl) return null;

        return $this->downloadAndConvert($imgUrl, $type);
    }

    /**
     * Gemini 이미지 생성 (무료 티어)
     */
    private function fromGemini($query, $type) {
        $apiKey = getKey('gemini.api_key');
        if (!$apiKey) return null;

        // ★ v8: 기본값 Nano Banana 2 Lite (Imagen 4 종료 2026-08-17, 2.5-flash-image 종료 예정 2026-10-02)
        $imageModel = getKey('gemini.image_model', 'gemini-3.1-flash-lite-image');
        if (!$imageModel) $imageModel = 'gemini-3.1-flash-lite-image';

        // 만료된 모델 자동 교정
        $deprecated = [
            'gemini-2.5-flash-preview-image-generation' => 'gemini-3.1-flash-lite-image',
            'gemini-2.5-flash-image-preview' => 'gemini-3.1-flash-lite-image',
            'gemini-2.0-flash-exp-image-generation' => 'gemini-3.1-flash-lite-image',
            'gemini-2.0-flash-preview-image-generation' => 'gemini-3.1-flash-lite-image',
            'gemini-3.1-flash-image-preview' => 'gemini-3.1-flash-image',   // 2026-06-25 종료
            'gemini-3-pro-image-preview' => 'gemini-3-pro-image',           // 2026-06-25 종료
            'imagen-3.0-generate-002' => 'gemini-3.1-flash-lite-image',
            'imagen-3.0-generate-001' => 'gemini-3.1-flash-lite-image',
            'imagen-4.0-generate-001' => 'gemini-3.1-flash-lite-image',      // 2026-08-17 종료
            'imagen-4.0-fast-generate-001' => 'gemini-3.1-flash-lite-image', // 2026-08-17 종료
            'imagen-4.0-ultra-generate-001' => 'gemini-3.1-flash-image',     // 2026-08-17 종료
        ];
        if (isset($deprecated[$imageModel])) {
            $oldModel = $imageModel;
            $imageModel = $deprecated[$imageModel];
            write_log("⚠️ 이미지 모델 자동 교정: {$oldModel} → {$imageModel} (관리자에서 직접 변경 권장)");
            $keys = loadApiKeys();
            $keys['gemini']['image_model'] = $imageModel;
            saveApiKeys($keys);
        }

        $size = $type === 'thumbnail' ? '1200x630' : '800x400';

        // ★ v5: 제목 context → 영문 검색어만 사용 (한글 제목 전달하면 AI가 텍스트를 이미지에 삽입해버림)
        $titleContext = '';
        // 한글 제목은 전달하지 않음 — 대신 영문 검색어(query)로 주제 전달

        // Imagen 모델은 다른 API 엔드포인트 사용
        if (str_starts_with($imageModel, 'imagen')) {
            return $this->fromImagen($apiKey, $imageModel, $query, $type, '');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$imageModel}:generateContent?key={$apiKey}";

        // ★ v7: 스타일 다양화 — 포토리얼 강제 해제, 웹 UI처럼 자유로운 스타일
        // Gemini 웹에서 만드는 것처럼 다양한 비주얼 스타일 허용
        $styles = [
            // 일러스트/그래픽 (웹 UI에서 인기 스타일)
            'Modern Korean-style illustration, vibrant colors, trendy character design, social media aesthetic, clean lines, eye-catching composition',
            'Colorful flat illustration with bold outlines, infographic style, modern minimal design, bright saturated palette',
            'Digital art illustration, Korean webtoon inspired style, dynamic poses, vivid neon accents, urban backdrop',
            'Cute Korean illustration style, soft pastel colors, rounded shapes, friendly characters, warm atmosphere',
            '3D isometric illustration, clean geometric shapes, soft shadows, modern tech aesthetic, vibrant gradient colors',
            // 시네마틱/사진풍
            'Cinematic photography style, dramatic lighting, shallow depth of field, warm color grading, Korean setting',
            'High-quality lifestyle photography, natural soft lighting, cozy Korean cafe atmosphere, warm tones',
            'Modern editorial design, bold typography-friendly layout, clean negative space, professional magazine quality',
            // 혼합/크리에이티브
            'Mixed media collage style, combining photography and illustration elements, trendy Korean design aesthetic',
            'Korean YouTube thumbnail style, eye-catching composition, bold visual elements, dynamic layout, high contrast colors',
        ];
        $chosenStyle = $styles[array_rand($styles)];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' => "Create a high-quality blog thumbnail image about: {$query}. Style: {$chosenStyle}. RULES: 1) ABSOLUTELY NO text, NO letters, NO words, NO numbers, NO characters, NO watermarks anywhere in the image. Pure visual content only. The image must contain ZERO text elements. 2) Must be 16:9 wide horizontal aspect ratio. 3) High resolution, professional quality, visually striking. 4) The image should clearly represent the topic at a glance. 5) Korean context preferred (Korean people, Korean settings) when relevant."]]]],
                'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT'], 'maxOutputTokens' => 2048],
            ]),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) {
            write_log("Gemini 이미지 HTTP {$code} (모델: {$imageModel})");
            if ($code === 404) {
                write_log("💡 '{$imageModel}' 만료/미지원. 관리자 → 전체 키 관리에서 'Nano Banana 2 Lite'로 변경하세요");
            }
            if ($code === 429 || $code === 403 || $code === 400) {
                write_log("💡 Gemini 이미지 생성은 무료 티어에서 제공되지 않습니다(유료 전용). 결제 미연결이면 이미지 소스를 pixabay/pexels/naver로 두세요");
            }
            return null;
        }

        $data = json_decode($resp, true);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                $imgData = base64_decode($part['inlineData']['data']);
                $mime = $part['inlineData']['mimeType'] ?? 'image/png';
                $ext = str_contains($mime, 'webp') ? 'webp' : (str_contains($mime, 'jpeg') ? 'jpg' : 'png');
                $path = IMAGE_SAVE_DIR . 'gemini_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                file_put_contents($path, $imgData);
                return $path;
            }
        }
        return null;
    }


    /**
     * Google Imagen 이미지 생성
     */
    private function fromImagen($apiKey, $model, $query, $type, $titleContext = '') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict?key={$apiKey}";
        // 사실적 사진 스타일 (v3)
        $styles = [
            'professional stock photography, realistic, sharp details',
            'cinematic photography, natural lighting, photorealistic',
            'editorial magazine photo, studio quality, crisp',
            'lifestyle photography, warm natural tones, authentic',
            'high-end commercial photo, clean composition, vivid colors',
            'documentary photography, candid, natural atmosphere',
            'fine art photography, golden hour, DSLR quality',
        ];
        $chosenStyle = $styles[array_rand($styles)];
        $prompt = "A {$chosenStyle} blog image about: {$query}. CRITICAL: ABSOLUTELY NO text, NO letters, NO words, NO numbers, NO characters, NO watermarks anywhere. Pure visual content only. Photorealistic, high quality, professional.";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'instances' => [['prompt' => $prompt]],
                'parameters' => ['sampleCount' => 1, 'aspectRatio' => '16:9'],
            ]),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) {
            write_log("Imagen HTTP {$code} (모델: {$model})");
            if ($code === 404) write_log("💡 Imagen 계열은 2026-08-17 종료. 관리자에서 'Nano Banana 2 Lite' 선택 권장");
            return null;
        }

        $data = json_decode($resp, true);
        $predictions = $data['predictions'] ?? [];
        foreach ($predictions as $pred) {
            if (!empty($pred['bytesBase64Encoded'])) {
                $imgData = base64_decode($pred['bytesBase64Encoded']);
                $path = IMAGE_SAVE_DIR . 'imagen_' . time() . '_' . mt_rand(1000,9999) . '.png';
                file_put_contents($path, $imgData);
                return $path;
            }
        }
        return null;
    }

    /**
     * OpenAI DALL-E 이미지 생성
     */
    private function fromDalle($query, $type) {
        $apiKey = getKey('chatgpt.api_key');
        if (!$apiKey) return null;

        $dalleModel = getKey('chatgpt.image_model', 'gpt-image-2');
        if (!$dalleModel) $dalleModel = 'gpt-image-2';
        // ★ v9.1: DALL-E 2/3은 2026-05-12 API 종료 → gpt-image-2로 자동 교정
        if (str_starts_with($dalleModel, 'dall-e')) {
            write_log("⚠️ 이미지 모델 자동 교정: {$dalleModel} → gpt-image-2 (DALL-E API 종료)");
            $dalleModel = 'gpt-image-2';
            $keys = loadApiKeys(); $keys['chatgpt']['image_model'] = 'gpt-image-2'; saveApiKeys($keys);
        }
        $isGptImage = str_starts_with($dalleModel, 'gpt-image');

        // 모델별 사이즈 설정 (gpt-image: 1536x1024 가로형 지원)
        $size = $isGptImage ? '1536x1024' : (($dalleModel === 'dall-e-3') ? '1792x1024' : '1024x1024');

        $styles = [
            'minimalist flat illustration', 'watercolor painting', 'isometric 3D art',
            'cinematic photography', 'abstract geometric', 'modern editorial',
            'digital art with gradients', 'retro vintage poster', 'paper cut-out layers',
        ];
        $chosenStyle = $styles[array_rand($styles)];
        $prompt = "A {$chosenStyle} blog header image about: {$query}. CRITICAL: ABSOLUTELY NO text, NO letters, NO words, NO numbers, NO watermarks anywhere. Pure visual content only. High quality, professional.";

        $body = ['model' => $dalleModel, 'prompt' => $prompt, 'n' => 1, 'size' => $size];
        if ($isGptImage) {
            $body['quality'] = 'medium';   // gpt-image는 항상 b64_json 반환, response_format 파라미터 미지원
        } else {
            $body['response_format'] = 'b64_json';
            if ($dalleModel === 'dall-e-3') $body['quality'] = 'standard';
        }

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) {
            write_log("OpenAI 이미지 HTTP {$code} (모델: {$dalleModel}): " . mb_substr($resp, 0, 200));
            return null;
        }

        $data = json_decode($resp, true);
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!$b64) return null;

        $imgData = base64_decode($b64);
        $path = IMAGE_SAVE_DIR . 'dalle_' . time() . '_' . mt_rand(1000,9999) . '.png';
        file_put_contents($path, $imgData);

        // 리사이즈 (DALL-E 출력은 정사각형일 수 있으므로)
        $src = @imagecreatefromstring($imgData);
        if ($src) {
            $tw = $type === 'thumbnail' ? 1200 : 800;
            $th = $type === 'thumbnail' ? 630 : 400;
            $dst = imagecreatetruecolor($tw, $th);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
            imagewebp($dst, $path . '.webp', 85);
            imagedestroy($src); imagedestroy($dst);
            @unlink($path);
            return $path . '.webp';
        }
        return $path;
    }

    /**
     * URL에서 이미지 다운로드 → WebP 변환
     */
    private function downloadAndConvert($url, $type) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
        $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200 || strlen($data) < 1000) return null;

        $src = @imagecreatefromstring($data);
        if (!$src) return null;

        // 리사이즈
        $tw = $type === 'thumbnail' ? 1200 : 800;
        $th = $type === 'thumbnail' ? 630 : 400;
        $sw = imagesx($src); $sh = imagesy($src);
        $scale = max($tw / $sw, $th / $sh);
        $nw = (int)($sw * $scale); $nh = (int)($sh * $scale);

        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, (int)(($tw - $nw) / 2), (int)(($th - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
        imagedestroy($src);

        $f = 'stock_' . time() . '_' . mt_rand(1000,9999);
        if (function_exists('imagewebp')) {
            $path = IMAGE_SAVE_DIR . $f . '.webp'; imagewebp($dst, $path, 80);
        } else {
            $path = IMAGE_SAVE_DIR . $f . '.jpg'; imagejpeg($dst, $path, 80);
        }
        imagedestroy($dst);
        return $path;
    }
}


// ═════════════════════════════════════════
// 이미지 제공자 (스톡 + 그라데이션 폴백)
// ═════════════════════════════════════════

class ImageGenerator {
    private $stock;
    private $_thumbnailPrompt = '';
    // 색감 타입별 팔레트 (pick.brain177.com 참고)
    private $colorThemes = [
        'pastel' => [ // 🌸 파스텔
            [[255,182,193],[173,216,230]], [[255,218,185],[221,160,221]], [[176,224,230],[255,228,196]],
            [[255,192,203],[230,230,250]], [[200,230,201],[255,224,178]], [[187,222,251],[248,187,208]],
        ],
        'vivid' => [ // 🌈 비비드
            [[255,0,128],[0,200,255]], [[255,64,0],[255,200,0]], [[0,200,83],[0,176,255]],
            [[156,39,176],[233,30,99]], [[0,150,136],[76,175,80]], [[255,87,34],[255,152,0]],
        ],
        'dark' => [ // 🌙 다크
            [[30,30,60],[60,60,100]], [[20,20,40],[80,40,80]], [[10,30,50],[40,70,100]],
            [[40,20,60],[20,40,80]], [[25,25,35],[55,55,75]], [[30,10,40],[60,30,70]],
        ],
        'warm' => [ // 🔥 웜톤
            [[255,94,77],[255,167,38]], [[255,138,101],[255,183,77]], [[239,83,80],[255,112,67]],
            [[230,74,25],[245,124,0]], [[255,109,0],[255,202,40]], [[183,28,28],[230,81,0]],
        ],
        'cool' => [ // ❄️ 쿨톤
            [[33,150,243],[0,188,212]], [[63,81,181],[3,169,244]], [[0,121,107],[38,166,154]],
            [[21,101,192],[0,131,143]], [[48,63,159],[0,150,136]], [[13,71,161],[0,96,100]],
        ],
        'neon' => [ // 💡 네온
            [[255,0,255],[0,255,255]], [[0,255,128],[255,0,128]], [[255,255,0],[0,255,255]],
            [[128,0,255],[255,0,64]], [[0,255,0],[0,128,255]], [[255,0,64],[255,128,0]],
        ],
        'dreamy' => [ // 🦄 드리미
            [[199,125,255],[135,206,250]], [[255,154,162],[255,218,193]], [[147,180,255],[237,187,255]],
            [[255,179,186],[255,223,186]], [[186,200,255],[218,185,255]], [[162,210,255],[255,183,240]],
        ],
        'earth' => [ // 🌿 어스톤
            [[139,119,101],[189,174,146]], [[110,130,80],[175,195,130]], [[140,100,70],[195,165,120]],
            [[85,107,47],[143,164,96]], [[120,90,60],[180,160,110]], [[100,120,80],[160,180,130]],
        ],
        'mono' => [ // ⬜ 모노
            [[50,50,50],[150,150,150]], [[30,30,30],[120,120,120]], [[60,60,70],[170,170,180]],
            [[40,40,50],[130,130,140]], [[20,20,30],[100,100,110]], [[70,70,80],[180,180,190]],
        ],
    ];
    private $fontPath = null;

    public function __construct() {
        $this->fontPath = $this->findFont();
        $this->stock = new StockImageSearch();
    }

    private function findFont() {
        $candidates = [FONT_PATH,
            '/usr/share/fonts/truetype/nanum/NanumGothicBold.ttf',
            '/usr/share/fonts/truetype/nanum/NanumGothic.ttf',
            '/usr/share/fonts/nanum-gothic/NanumGothicBold.ttf',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
        ];
        foreach ($candidates as $fp) { if (file_exists($fp)) return $fp; }

        $fontDir = dirname(FONT_PATH);
        if (!is_dir($fontDir)) mkdir($fontDir, 0755, true);
        $urls = [
            'https://github.com/google/fonts/raw/main/ofl/nanumgothic/NanumGothic-Bold.ttf',
            'https://cdn.jsdelivr.net/gh/google/fonts@main/ofl/nanumgothic/NanumGothic-Bold.ttf',
        ];
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
            $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code === 200 && strlen($data) > 10000) {
                file_put_contents(FONT_PATH, $data); write_log("한글 폰트 다운로드 완료"); return FONT_PATH;
            }
        }
        return null;
    }

    private function saveGradient($img, $prefix) {
        $f = $prefix . '_' . time() . '_' . mt_rand(1000,9999);
        if (function_exists('imagewebp')) {
            $path = IMAGE_SAVE_DIR . $f . '.webp'; imagewebp($img, $path, 80);
        } else {
            $path = IMAGE_SAVE_DIR . $f . '.jpg'; imagejpeg($img, $path, 75);
        }
        imagedestroy($img); return $path;
    }

    /** 랜덤 색감 팔레트 가져오기 */
    private function getRandomPalette($themeName = null) {
        if (!$themeName) {
            $themeNames = array_keys($this->colorThemes);
            $themeName = $themeNames[array_rand($themeNames)];
        }
        $pals = $this->colorThemes[$themeName] ?? $this->colorThemes['pastel'];
        return $pals[array_rand($pals)];
    }

    /** 다양한 그라데이션 배경 생성 */
    private function drawBackground($img, $w, $h, $pal, $style = null) {
        $styles = ['linear_v','linear_h','diagonal','radial','split','tricolor'];
        if (!$style) $style = $styles[array_rand($styles)];
        $c1 = $pal[0]; $c2 = $pal[1];

        switch ($style) {
            case 'linear_h': // 가로 그라데이션
                for ($x = 0; $x < $w; $x++) {
                    $r = max(0, min(255, (int)($c1[0]+($c2[0]-$c1[0])*$x/$w)));
                    $g = max(0, min(255, (int)($c1[1]+($c2[1]-$c1[1])*$x/$w)));
                    $b = max(0, min(255, (int)($c1[2]+($c2[2]-$c1[2])*$x/$w)));
                    imageline($img, $x, 0, $x, $h, imagecolorallocate($img, $r, $g, $b));
                }
                break;
            case 'diagonal': // 대각선
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $t = ($x/$w + $y/$h) / 2;
                        $r = max(0, min(255, (int)($c1[0]+($c2[0]-$c1[0])*$t)));
                        $g = max(0, min(255, (int)($c1[1]+($c2[1]-$c1[1])*$t)));
                        $b = max(0, min(255, (int)($c1[2]+($c2[2]-$c1[2])*$t)));
                        imagesetpixel($img, $x, $y, imagecolorallocate($img, $r, $g, $b));
                    }
                }
                break;
            case 'radial': // 방사형
                $cx = $w/2; $cy = $h/2;
                $maxDist = sqrt($cx*$cx + $cy*$cy);
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $dist = sqrt(($x-$cx)*($x-$cx) + ($y-$cy)*($y-$cy));
                        $t = min(1, $dist / $maxDist);
                        $r = max(0, min(255, (int)($c1[0]+($c2[0]-$c1[0])*$t)));
                        $g = max(0, min(255, (int)($c1[1]+($c2[1]-$c1[1])*$t)));
                        $b = max(0, min(255, (int)($c1[2]+($c2[2]-$c1[2])*$t)));
                        imagesetpixel($img, $x, $y, imagecolorallocate($img, $r, $g, $b));
                    }
                }
                break;
            case 'split': // 상하 2분할
                $mid = $h / 2;
                for ($y = 0; $y < $mid; $y++) {
                    $t = $y / $mid;
                    imageline($img, 0, $y, $w, $y, imagecolorallocate($img,
                        max(0, min(255, (int)($c1[0]+($c2[0]-$c1[0])*$t*0.5))),
                        max(0, min(255, (int)($c1[1]+($c2[1]-$c1[1])*$t*0.5))),
                        max(0, min(255, (int)($c1[2]+($c2[2]-$c1[2])*$t*0.5)))));
                }
                for ($y = (int)$mid; $y < $h; $y++) {
                    $t = ($y - $mid) / $mid;
                    imageline($img, 0, $y, $w, $y, imagecolorallocate($img,
                        max(0, min(255, (int)($c2[0]+($c1[0]-$c2[0])*$t*0.5+($c2[0]-$c1[0])*0.5))),
                        max(0, min(255, (int)($c2[1]+($c1[1]-$c2[1])*$t*0.5+($c2[1]-$c1[1])*0.5))),
                        max(0, min(255, (int)($c2[2]+($c1[2]-$c2[2])*$t*0.5+($c2[2]-$c1[2])*0.5)))));
                }
                break;
            case 'tricolor': // 3색 그라데이션
                $c3 = [max(0,min(255,(int)(($c1[0]+$c2[0])/2+mt_rand(-30,30)))), max(0,min(255,(int)(($c1[1]+$c2[1])/2+mt_rand(-30,30)))), max(0,min(255,(int)(($c1[2]+$c2[2])/2+mt_rand(-30,30))))];
                for ($y = 0; $y < $h; $y++) {
                    $t = $y / $h;
                    if ($t < 0.5) {
                        $tt = $t * 2;
                        $r=max(0,min(255,(int)($c1[0]+($c3[0]-$c1[0])*$tt))); $g=max(0,min(255,(int)($c1[1]+($c3[1]-$c1[1])*$tt))); $b=max(0,min(255,(int)($c1[2]+($c3[2]-$c1[2])*$tt)));
                    } else {
                        $tt = ($t - 0.5) * 2;
                        $r=max(0,min(255,(int)($c3[0]+($c2[0]-$c3[0])*$tt))); $g=max(0,min(255,(int)($c3[1]+($c2[1]-$c3[1])*$tt))); $b=max(0,min(255,(int)($c3[2]+($c2[2]-$c3[2])*$tt)));
                    }
                    imageline($img, 0, $y, $w, $y, imagecolorallocate($img, $r, $g, $b));
                }
                break;
            default: // linear_v 세로
                for ($y = 0; $y < $h; $y++) {
                    $r = max(0, min(255, (int)($c1[0]+($c2[0]-$c1[0])*$y/$h)));
                    $g = max(0, min(255, (int)($c1[1]+($c2[1]-$c1[1])*$y/$h)));
                    $b = max(0, min(255, (int)($c1[2]+($c2[2]-$c1[2])*$y/$h)));
                    imageline($img, 0, $y, $w, $y, imagecolorallocate($img, $r, $g, $b));
                }
        }
    }

    /** 오버레이 효과 (반투명 사각형, 하단 그림자 등) */
    private function drawOverlay($img, $w, $h, $style = null) {
        $styles = ['center_box','bottom_fade','full_dim','top_bar','border_frame','vignette'];
        if (!$style) $style = $styles[array_rand($styles)];

        switch ($style) {
            case 'center_box':
                $mx = (int)($w*0.06); $my = (int)($h*0.1);
                imagefilledrectangle($img, $mx, $my, $w-$mx, $h-$my, imagecolorallocatealpha($img, 0, 0, 0, 55));
                break;
            case 'bottom_fade':
                for ($y = (int)($h*0.5); $y < $h; $y++) {
                    $a = (int)(80 - 50 * (($y - $h*0.5) / ($h*0.5)));
                    imagefilledrectangle($img, 0, $y, $w, $y, imagecolorallocatealpha($img, 0, 0, 0, max(0, $a)));
                }
                break;
            case 'full_dim':
                imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 70));
                break;
            case 'top_bar':
                imagefilledrectangle($img, 0, 0, $w, (int)($h*0.15), imagecolorallocatealpha($img, 255, 255, 255, 85));
                imagefilledrectangle($img, 0, (int)($h*0.3), $w, (int)($h*0.75), imagecolorallocatealpha($img, 0, 0, 0, 60));
                break;
            case 'border_frame':
                $bw = max(6, (int)($w * 0.025));
                imagefilledrectangle($img, 0, 0, $w, $bw, imagecolorallocatealpha($img, 255, 255, 255, 50));
                imagefilledrectangle($img, 0, $h-$bw, $w, $h, imagecolorallocatealpha($img, 255, 255, 255, 50));
                imagefilledrectangle($img, 0, 0, $bw, $h, imagecolorallocatealpha($img, 255, 255, 255, 50));
                imagefilledrectangle($img, $w-$bw, 0, $w, $h, imagecolorallocatealpha($img, 255, 255, 255, 50));
                imagefilledrectangle($img, (int)($w*0.08), (int)($h*0.12), $w-(int)($w*0.08), $h-(int)($h*0.12), imagecolorallocatealpha($img, 0, 0, 0, 60));
                break;
            case 'vignette':
                $cx = $w/2; $cy = $h/2; $maxD = sqrt($cx*$cx+$cy*$cy);
                for ($y = 0; $y < $h; $y += 2) {
                    for ($x = 0; $x < $w; $x += 2) {
                        $d = sqrt(($x-$cx)*($x-$cx)+($y-$cy)*($y-$cy));
                        $a = (int)(127 - 60 * ($d / $maxD));
                        if ($a < 90) {
                            imagefilledrectangle($img, $x, $y, $x+1, $y+1, imagecolorallocatealpha($img, 0, 0, 0, max(0, $a)));
                        }
                    }
                }
                break;
        }
    }

    /** 장식용 기하학 패턴 추가 */
    private function drawDecoration($img, $w, $h) {
        $types = ['none','none','circles','dots','lines','corner_accent'];
        $type = $types[array_rand($types)];
        $white = imagecolorallocatealpha($img, 255, 255, 255, 90);

        switch ($type) {
            case 'circles':
                for ($i = 0; $i < 5; $i++) {
                    $cx = mt_rand(0, $w); $cy = mt_rand(0, $h); $r = mt_rand(30, 120);
                    imageellipse($img, $cx, $cy, $r*2, $r*2, $white);
                }
                break;
            case 'dots':
                for ($i = 0; $i < 30; $i++) {
                    $x = mt_rand(0, $w); $y = mt_rand(0, $h); $s = mt_rand(3, 8);
                    imagefilledellipse($img, $x, $y, $s, $s, $white);
                }
                break;
            case 'lines':
                for ($i = 0; $i < 4; $i++) {
                    imageline($img, mt_rand(0,$w), mt_rand(0,$h), mt_rand(0,$w), mt_rand(0,$h), $white);
                }
                break;
            case 'corner_accent':
                $s = min($w, $h) * 0.15;
                imageline($img, 0, 0, (int)$s, 0, $white);
                imageline($img, 0, 0, 0, (int)$s, $white);
                imageline($img, $w-1, $h-1, $w-1-(int)$s, $h-1, $white);
                imageline($img, $w-1, $h-1, $w-1, $h-1-(int)$s, $white);
                break;
        }
    }

    /**
     * 썸네일: AI 이미지 또는 스톡 이미지 (v5)
     * 1차: AI 이미지 생성 (선택된 모델 사용)
     * 2차: 스톡 이미지 폴백
     * 3차: 그라데이션 폴백
     */
    public function createThumbnail($title, $keyword = '', $thumbnailPrompt = '', $allowStock = true) {
        $query = $keyword ?: $this->korToSearchTerm($title);
        $this->_currentTitle = $title;
        $this->_thumbnailPrompt = '';

        // 1차: 스톡/AI 이미지 (내부에서 우선순위대로 시도)
        // ★ v9.1: $allowStock=false면 무작위 스톡(pixabay/pexels) 제외 — 생성(gemini/dalle)만
        $stockPath = $this->stock->search($query, 'thumbnail', !$allowStock);

        // ★ v9.1: 스톡/생성 썸네일도 AI가 실제로 보고 확인 — 무관하면 그라데이션으로
        //   ("로블록스 동물병원 게임" 글에 실제 수술실 사진이 대표로 걸리는 사고 방지)
        if ($stockPath && intval(getKey('image_source.judge_stock', 1))) {
            $judge = new ImageJudge();
            $scores = $judge->judge([['url' => '', 'local_raw' => $stockPath]], [
                'title' => $title, 'keyword' => $keyword, 'summary' => '', 'image_descs' => [],
            ]);
            $minScore = intval(getKey('image_source.min_relevance', 6));
            if ($scores !== null && intval($scores[0]['score'] ?? 0) < $minScore) {
                write_log("🚫 썸네일 후보(스톡/생성) 관련도 미달({$scores[0]['score']}점: {$scores[0]['reason']}) → 그라데이션 대체");
                @unlink($stockPath);
                $stockPath = null;
            }
        }
        if ($stockPath) return $stockPath;

        // 최후: 그라데이션 폴백 (제목 텍스트가 들어가서 주제와 항상 일치)
        write_log("이미지 생성 실패/부적합 → 그라데이션 썸네일");
        $w=1200; $h=630;
        $pal = $this->getRandomPalette();
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        $this->drawBackground($img, $w, $h, $pal);
        $this->drawDecoration($img, $w, $h);
        $this->drawOverlay($img, $w, $h);
        $this->text($img, $title, $w, $h, imagecolorallocate($img, 255, 255, 255), 28);
        return $this->saveGradient($img, 'thumb');
    }

    private function createGradientSection($text) {
        $w=800; $h=400;
        $pal = $this->getRandomPalette();
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        $this->drawBackground($img, $w, $h, $pal);
        $this->drawDecoration($img, $w, $h);
        $this->drawOverlay($img, $w, $h);
        $this->text($img, $text, $w, $h, imagecolorallocate($img, 255, 255, 255), 22);
        return $this->saveGradient($img, 'sec');
    }

    /**
     * ★ 이미지 figure HTML 생성 헬퍼
     * - 이미지를 컨텐츠 가로폭에 꽉 채우지 않음!
     * - max-width: 560px, 원본이 작으면 그대로 (400px도 OK)
     * - 중앙 정렬, figcaption SEO, alt 키워드 포함
     */
    private function buildFigureHtml($src, $altText, $keyword = '') {
        $safeAlt = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');
        // figcaption: alt 텍스트를 설명문으로 활용, 키워드 자연 포함
        $captionText = $safeAlt;
        if ($keyword && mb_strpos($altText, $keyword) === false) {
            $captionText = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') . ' - ' . $safeAlt;
        }
        return "<!-- wp:image {\"align\":\"center\",\"sizeSlug\":\"medium\"} -->\n"
             . "<figure class=\"wp-block-image aligncenter size-medium\" style=\"text-align:center;margin:24px auto;\">"
             . "<img src=\"{$src}\" alt=\"{$safeAlt}\" "
             . "style=\"max-width:560px;height:auto;display:block;margin:0 auto;border-radius:6px;\" loading=\"lazy\"/>"
             . "<figcaption class=\"wp-element-caption\" style=\"text-align:center;font-size:13px;color:#888;margin-top:8px;\">"
             . "{$captionText}</figcaption>"
             . "</figure>\n<!-- /wp:image -->";
    }

    /**
     * ★ v7: 연속 이미지 방지 — [IMAGE:] 태그가 연달아 나오면 재배치
     * AI가 이미지를 연속으로 배치하면 블로그에서 이미지만 줄줄이 보여서 가독성 최악.
     * 최소한 문단 1개(100자 이상) 이상의 텍스트가 이미지 사이에 있어야 함.
     */
    private function redistributeImageTags($html) {
        // [IMAGE:] 태그 위치 찾기
        preg_match_all('/\[IMAGE:[^\]]*\]/', $html, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0]) <= 1) return $html;

        $toMove = []; // 재배치해야 할 태그들
        $prev = null;
        foreach ($matches[0] as $idx => $match) {
            if ($prev !== null) {
                // 이전 이미지와 현재 이미지 사이의 텍스트 길이
                $between = substr($html, $prev[1] + strlen($prev[0]), $match[1] - $prev[1] - strlen($prev[0]));
                $textOnly = trim(strip_tags($between));
                // 사이에 실질적인 텍스트가 100자 미만이면 → 연속 이미지
                if (mb_strlen($textOnly) < 100) {
                    $toMove[] = $match[0]; // 이 태그를 재배치 대상으로 마킹
                    write_log("⚠️ 연속 이미지 감지 → 재배치: " . mb_substr($match[0], 0, 40));
                    continue; // prev 갱신 안 함 (이전 이미지 위치 유지)
                }
            }
            $prev = $match;
        }

        if (empty($toMove)) return $html;

        // 재배치 대상 태그를 원래 위치에서 제거
        foreach ($toMove as $tag) {
            $html = preg_replace('/' . preg_quote($tag, '/') . '/', '', $html, 1);
        }

        // H2/H3 위치 찾기 — 이미지가 없는 섹션에 재배치
        preg_match_all('/(<h[23][^>]*>|## |### )/', $html, $headings, PREG_OFFSET_CAPTURE);
        $headingPositions = array_column($headings[0], 1);

        // 이미지가 있는 위치 다시 스캔
        preg_match_all('/\[IMAGE:[^\]]*\]/', $html, $existingImgs, PREG_OFFSET_CAPTURE);
        $imgPositions = array_column($existingImgs[0], 1);

        // 이미지 없는 섹션 찾기
        $emptySlots = [];
        foreach ($headingPositions as $hPos) {
            $hasImage = false;
            foreach ($imgPositions as $iPos) {
                if (abs($iPos - $hPos) < 500) { $hasImage = true; break; } // 가까운 이미지 있으면 스킵
            }
            if (!$hasImage) $emptySlots[] = $hPos;
        }

        // 재배치
        $moved = 0;
        rsort($emptySlots); // 역순으로 삽입 (offset 밀림 방지)
        foreach ($emptySlots as $pos) {
            if ($moved >= count($toMove)) break;
            $tag = $toMove[$moved++];
            $html = substr($html, 0, $pos) . "\n" . $tag . "\n" . substr($html, $pos);
        }

        // 남은 태그는 버림 (배치할 곳 없음)
        if ($moved < count($toMove)) {
            write_log("⚠️ 재배치 불가 이미지 " . (count($toMove) - $moved) . "개 제거됨");
        }

        return $html;
    }

    /**
     * 본문 이미지 처리
     * [IMAGE: 설명 | search_term] 형식 지원
     * $aiSearches: AI가 생성한 영문 검색어 배열 (폴백용)
     */
    public function processImages($html, $aiSearches = [], $judgeCtx = null) {
        // ★ v7: 연속 이미지 방지 — 처리 전에 재배치
        $html = $this->redistributeImageTags($html);

        // 1) [IMAGE:] 태그 수집 + 스톡/생성 이미지 검색
        preg_match_all('/\[IMAGE:\s*(.+?)\]/', $html, $tm);
        if (empty($tm[0])) return ['content' => $html, 'images' => []];

        $self = $this;
        $items = [];
        foreach ($tm[1] as $i => $d) {
            $parts = explode('|', $d, 2);
            $altText = trim($parts[0]);
            if (isset($parts[1]) && trim($parts[1])) {
                $searchTerm = trim($parts[1]);
            } elseif (!empty($aiSearches[$i])) {
                $searchTerm = $aiSearches[$i];
            } else {
                $searchTerm = $this->korToSearchTerm($altText);
            }
            write_log("이미지 검색: \"{$searchTerm}\" (alt: {$altText})");
            $p = $this->stock->search($searchTerm, 'section');
            $items[$i] = ['alt' => $altText, 'path' => $p];
        }

        // 2) ★ v9.1: 스톡/생성 이미지도 AI가 "실제로 보고" 글과 맞는지 판별
        //    예전엔 영문 검색어로 받아온 스톡 사진을 검증 없이 그대로 넣어서
        //    "카드샵 글에 포커 사진" 같은 사고가 남 → 이제 모든 사진이 판별을 거침
        if ($judgeCtx && intval(getKey('image_source.judge_stock', 1))) {
            $cands = [];
            foreach ($items as $i => $it) {
                if ($it['path']) $cands[$i] = ['url' => '', 'local_raw' => $it['path']];
            }
            if (!empty($cands)) {
                $judge = new ImageJudge();
                $scores = $judge->judge($cands, [
                    'title'   => (string)($judgeCtx['title'] ?? ''),
                    'keyword' => (string)($judgeCtx['keyword'] ?? ''),
                    'summary' => (string)($judgeCtx['summary'] ?? ''),
                    'image_descs' => array_map(fn($it) => $it['alt'], $items),
                ]);
                $minScore = intval(getKey('image_source.min_relevance', 6));
                if ($scores === null) {
                    // 판별 불가(비전 AI 전부 실패) → 확인 안 된 스톡 사진은 전부 그라데이션으로 대체
                    write_log("🚫 스톡 이미지 AI 판별 실패 → 확인 안 된 사진 대신 그라데이션 사용");
                    foreach ($items as $i => $it) {
                        if ($it['path']) { @unlink($it['path']); $items[$i]['path'] = null; }
                    }
                } else {
                    foreach ($cands as $i => $_) {
                        $sc = $scores[$i] ?? ['score' => 0, 'reason' => '판별 누락', 'caption' => ''];
                        if ($sc['score'] < $minScore) {
                            write_log("🚫 스톡 이미지 관련도 미달({$sc['score']}점: {$sc['reason']}) → 그라데이션 대체");
                            @unlink($items[$i]['path']);
                            $items[$i]['path'] = null;
                        } elseif (!empty($sc['caption'])) {
                            // ★ 캡션은 AI가 실제 이미지를 보고 쓴 내용으로 교체 (설명-이미지 불일치 방지)
                            $items[$i]['alt'] = $sc['caption'];
                        }
                    }
                }
            }
        }

        // 3) 삽입 — ★ v9.2: 검증을 통과한 이미지가 없는 자리는 "이미지 없이" 발행
        //    (예전엔 텍스트 그라데이션 카드를 넣었는데, 본문에 큰 색상 박스가 박혀 보기 흉함)
        $paths = [];
        $imgIdx = 0;
        $dropped = 0;
        $html = preg_replace_callback('/\[IMAGE:\s*(.+?)\]/', function($m) use(&$paths, &$imgIdx, &$dropped, $items, $self) {
            $it = $items[$imgIdx++] ?? null;
            if (!$it || !$it['path']) { $dropped++; return ''; }
            $paths[] = $it['path'];
            return $self->buildFigureHtml($it['path'], $it['alt']);
        }, $html);
        if ($dropped > 0) write_log("ℹ️ 검증 통과 이미지 없는 자리 {$dropped}곳 → 이미지 없이 발행 (그라데이션 카드 미사용)");
        return ['content'=>$html, 'images'=>$paths];
    }

    /**
     * 로컬 폴더 이미지로 본문 이미지 처리
     * local_images/ 폴더에서 이미지를 가져와서 [IMAGE:...] 태그를 교체하고,
     * 남은 이미지는 본문 중간에 자동 삽입
     */
    public function processLocalImages($html, $keyword = '', $imageCount = 3) {
        // ★ v7: 연속 이미지 방지
        $html = $this->redistributeImageTags($html);

        $localDir = defined('LOCAL_IMAGE_DIR') ? LOCAL_IMAGE_DIR : (__DIR__ . '/local_images/');
        if (!is_dir($localDir)) { @mkdir($localDir, 0755, true); }

        // 키워드별 하위 폴더 우선, 없으면 전체 폴더에서
        $keywordDir = $localDir . preg_replace('/[\/\\\\:*?"<>|]/', '_', $keyword) . '/';
        $searchDirs = [];
        if (is_dir($keywordDir)) $searchDirs[] = $keywordDir;
        $searchDirs[] = $localDir;

        // 사용 가능한 이미지 수집
        $exts = ['jpg','jpeg','png','gif','webp'];
        $available = [];
        foreach ($searchDirs as $dir) {
            foreach (glob($dir . '*') as $f) {
                if (is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) {
                    $available[] = $f;
                }
            }
            if (!empty($available)) break; // 키워드 폴더에 있으면 그것만 사용
        }

        if (empty($available)) {
            write_log("⚠️ 로컬 이미지 없음 (폴더: {$localDir}). [IMAGE] 태그 제거.");
            $html = preg_replace('/\[IMAGE:[^\]]*\]/', '', $html);
            return ['content' => $html, 'images' => []];
        }

        shuffle($available);
        $paths = [];
        $usedIdx = 0;

        // 1단계: [IMAGE:...] 태그를 로컬 이미지로 교체
        $self = $this;
        $html = preg_replace_callback('/\[IMAGE:\s*(.+?)\]/', function($m) use(&$paths, &$usedIdx, $available, $self) {
            if ($usedIdx >= count($available)) return ''; // 이미지 부족하면 제거
            $p = $available[$usedIdx++];
            $altText = trim(explode('|', $m[1])[0]);
            $paths[] = $p;
            write_log("로컬 이미지 삽입: " . basename($p) . " (alt: {$altText})");
            return $self->buildFigureHtml($p, $altText);
        }, $html);

        // 2단계: 이미지가 더 남았으면 H2 태그 사이에 자동 삽입
        $remaining = min($imageCount - $usedIdx, count($available) - $usedIdx);
        if ($remaining > 0) {
            // H2 위치 찾기
            preg_match_all('/(<h2[^>]*>|## )/', $html, $matches, PREG_OFFSET_CAPTURE);
            $h2Positions = array_column($matches[0], 1);

            if (count($h2Positions) > 1) {
                // H2와 H2 사이 중간 지점에 삽입 (균등 분배)
                $insertPoints = [];
                $step = max(1, floor(count($h2Positions) / ($remaining + 1)));
                for ($i = 0; $i < $remaining && ($step * ($i+1)) < count($h2Positions); $i++) {
                    $insertPoints[] = $h2Positions[$step * ($i+1)];
                }
                // 역순으로 삽입 (offset 밀림 방지)
                rsort($insertPoints);
                foreach ($insertPoints as $pos) {
                    if ($usedIdx >= count($available)) break;
                    $p = $available[$usedIdx++];
                    $paths[] = $p;
                    $imgHtml = "\n" . $this->buildFigureHtml($p, $keyword . ' 관련 이미지', $keyword) . "\n";
                    $html = substr($html, 0, $pos) . $imgHtml . substr($html, $pos);
                    write_log("로컬 이미지 자동 삽입: " . basename($p));
                }
            }
        }

        write_log("로컬 이미지 총 " . count($paths) . "개 삽입 (폴더에 " . count($available) . "개 보유)");
        return ['content' => $html, 'images' => $paths];
    }

    /**
     * ★ v7: 네이버 이미지 중 16:9(가로형)에 가장 가까운 이미지를 썸네일로 선택
     * 네이버 API가 반환한 width/height 데이터를 활용 (다운로드 불필요)
     * 세로형(9:16) 이미지는 절대 썸네일로 사용하지 않음
     * 
     * @param array $naverImages searchNaverImages() 반환값 [['url'=>..., 'width'=>..., 'height'=>...], ...]
     * @return int 가장 적합한 이미지의 인덱스 (기본 0)
     */
    public function pickBestThumbnailIndex($naverImages) {
        if (empty($naverImages)) return -1;
        if (count($naverImages) <= 1) {
            // ★ v9: 후보가 1개뿐이어도 세로형이면 쓰지 않음
            $w = intval($naverImages[0]['width'] ?? 0);
            $h = intval($naverImages[0]['height'] ?? 0);
            return ($w >= 200 && $h > 0 && ($w / $h) >= 1.0) ? 0 : -1;
        }

        $targetRatio = 16 / 9; // ≈ 1.778
        $bestIdx = -1; // ★ v9: 적합한 후보가 하나도 없으면 -1 (예전엔 무조건 0번을 골라 엉뚱한 이미지가 대표가 됨)
        $bestDiff = PHP_FLOAT_MAX;

        foreach ($naverImages as $idx => $imgData) {
            if (!is_array($imgData)) continue;

            $w = intval($imgData['width'] ?? 0);
            $h = intval($imgData['height'] ?? 0);

            // API에서 크기 정보가 없으면 스킵
            if ($w < 200 || $h < 100) continue;

            $ratio = $w / $h;

            // ★ 세로형 이미지(비율 < 1.0) 완전 제외 — 썸네일로 절대 안 됨
            if ($ratio < 1.0) {
                write_log("🚫 세로형 이미지 제외: #{$idx} (비율 " . round($ratio, 2) . ", {$w}x{$h})");
                continue;
            }

            $diff = abs($ratio - $targetRatio);

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIdx = $idx;
            }

            // 거의 정확한 16:9면 즉시 선택
            if ($diff < 0.1) {
                write_log("🎯 썸네일 선택: #{$idx} (비율 " . round($ratio, 2) . " ≈ 16:9, {$w}x{$h})");
                return $idx;
            }
        }

        // 최종 선택된 이미지 로그
        if ($bestIdx < 0) {
            write_log("🚫 비율 기준을 통과한 썸네일 후보 없음 → 웹수집 썸네일 사용 안 함");
            return -1;
        }
        $selData = $naverImages[$bestIdx] ?? [];
        $selW = $selData['width'] ?? '?';
        $selH = $selData['height'] ?? '?';
        write_log("🎯 썸네일 선택: #{$bestIdx} ({$selW}x{$selH}, 16:9 차이: " . round($bestDiff, 3) . ")");
        return $bestIdx;
    }

    /**
     * ★ v8: 로컬 원본 → 리사이즈/압축 → 변조 (downloadNaverImage의 후반부와 동일)
     * @param string $rawPath ImageJudge::downloadRaw() 등이 저장한 원본 경로
     * @return string|null 변조 완료된 경로
     */
    public function prepareFromRaw($rawPath, $isThumb = false) {
        if (!$rawPath || !file_exists($rawPath)) return null;
        $tmpPath = $rawPath;
        $optimized = ImageOptimizer::optimize($tmpPath, 'content');
        if ($optimized && $optimized !== $tmpPath) {
            @unlink($tmpPath);
            $tmpPath = $optimized;
        }
        // 썸네일은 뒤에서 1200x630 레터박스(번짐 배경)를 입히므로 여기서는 덧대기 생략 (이중 테두리 방지)
        $disguised = ImageOptimizer::disguise($tmpPath, !$isThumb);
        if ($disguised && $disguised !== $tmpPath) {
            @unlink($tmpPath);
            return $disguised;
        }
        return $tmpPath;
    }

    /**
     * ★ v8: 수집 이미지 후보를 AI가 보고 판단 → 썸네일 선택 + 본문용 정렬
     *
     * 흐름:
     *   1) 후보 이미지 원본 다운로드 (최대 12장)
     *   2) ImageJudge(비전 AI)로 각 이미지의 글 관련도/썸네일 적합성 점수화
     *   3) 썸네일 = thumb_ok 중 최고점 → 없으면 가로형 중 최고점 → 없으면 16:9 비율 방식
     *   4) 본문용 = 나머지를 점수순 정렬, 관련 없는(2점 이하) 이미지는 제외
     *
     * @param array  $candidates searchWebImages()/searchNaverImages() 결과
     * @param array  $post       AI가 생성한 글 (title, excerpt/meta_description, content_html 사용)
     * @param string $keyword
     * @param int    $imageCount 본문 이미지 개수
     * @return array ['thumb_raw'=>원본경로|null, 'thumb_caption'=>AI캡션, 'body'=>[후보배열(local_raw, ai_caption 포함)], 'ai_judged'=>bool]
     */
    public function selectWebImages(array $candidates, array $post, $keyword, $imageCount = 3) {
        $judge = new ImageJudge();
        $maxDl = min(count($candidates), max(6, $imageCount * 3), 12);

        // 1) 원본 다운로드
        $dl = 0;
        foreach ($candidates as $i => $c) {
            if ($dl >= $maxDl) break;
            if (!is_array($c)) { $candidates[$i] = ['url' => $c]; $c = $candidates[$i]; }
            if (!empty($c['local_raw']) && file_exists($c['local_raw'])) {
                if (empty($c['width']) || empty($c['height'])) {
                    $info = @getimagesize($c['local_raw']);
                    $candidates[$i]['width'] = $info[0] ?? 0; $candidates[$i]['height'] = $info[1] ?? 0;
                }
                $dl++; continue;
            }
            $raw = $judge->downloadRaw($c['url'] ?? '');
            if ($raw) {
                $info = @getimagesize($raw);
                $candidates[$i]['local_raw'] = $raw;
                $candidates[$i]['width'] = $info[0] ?? ($c['width'] ?? 0);
                $candidates[$i]['height'] = $info[1] ?? ($c['height'] ?? 0);
                $dl++;
            } else {
                $candidates[$i]['local_raw'] = null;
            }
            usleep(300000);
        }
        $ready = array_filter($candidates, fn($c) => !empty($c['local_raw']));
        write_log("🖼️ 이미지 후보 원본 다운로드: " . count($ready) . "/" . count($candidates) . "개");
        if (empty($ready)) return ['thumb_raw' => null, 'body' => [], 'ai_judged' => false];

        // 2) AI 판별
        $summary = trim((string)($post['excerpt'] ?? '')) ?: trim((string)($post['meta_description'] ?? ''));
        if (!$summary) $summary = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($post['content_html'] ?? $post['content'] ?? '')))), 0, 500);
        preg_match_all('/\[IMAGE:\s*(.+?)\]/u', (string)($post['content_html'] ?? ''), $dm);
        $descs = array_map(fn($d) => trim(explode('|', $d)[0]), $dm[1] ?? []);
        $scores = $judge->judge($ready, [
            'title' => $post['title'] ?? '', 'keyword' => $keyword,
            'summary' => $summary, 'image_descs' => $descs,
        ]);

        $thumbIdx = null;
        if ($scores) {
            // 3) 썸네일: thumb_ok 최고점
            $best = -1;
            foreach ($scores as $idx => $sc) {
                if ($sc['thumb_ok'] && $sc['score'] > $best && $sc['score'] >= 6) { $best = $sc['score']; $thumbIdx = $idx; } // ★ v9.1: 엄격 기준 (6점 이상)
            }
            // 폴백: 가로형 & 글자 없음 중 최고점
            if ($thumbIdx === null) {
                foreach ($scores as $idx => $sc) {
                    $w = $ready[$idx]['width'] ?? 0; $h = $ready[$idx]['height'] ?? 1;
                    if ($h > 0 && $w / $h >= 1.15 && !$sc['has_text'] && $sc['score'] > $best && $sc['score'] >= 6) { $best = $sc['score']; $thumbIdx = $idx; } // ★ v9.1: 엄격 기준
                }
            }
            $thumbCaption = '';
            if ($thumbIdx !== null) {
                $thumbCaption = $scores[$thumbIdx]['caption'] ?? '';
                write_log("🎯 AI 썸네일 선택: #{$thumbIdx} {$scores[$thumbIdx]['score']}점 ({$scores[$thumbIdx]['reason']}) ← " . substr($ready[$thumbIdx]['url'] ?? '', 0, 70));
            } else {
                write_log("⚠️ AI 판별 결과 썸네일로 적합한 이미지 없음 → 썸네일은 생성(그라데이션/AI) 폴백");
            }
            // 4) 본문: 점수순. ★ v8.1: 관련도 기준(기본 5점) 미만은 **절대 사용 안 함**
            //    → 5개 설정해도 맞는 이미지가 2~3개면 2~3개만 넣음 (억지로 채우지 않음)
            $minScore = intval(getKey('image_source.min_relevance', 6));
            $body = [];
            foreach ($scores as $idx => $sc) {
                if ($idx === $thumbIdx) continue;
                $cand = $ready[$idx];
                $cand['ai_caption'] = $sc['caption'] ?? '';   // 캡션을 후보에 실어 보냄
                $cand['ai_score'] = $sc['score'];
                $body[] = ['cand' => $cand, 'score' => $sc['score']];
            }
            usort($body, fn($a, $b) => $b['score'] <=> $a['score']);
            $bodyGood = array_values(array_filter($body, fn($b) => $b['score'] >= $minScore));
            $dropped = count($body) - count($bodyGood);
            if ($dropped > 0) write_log("🧹 관련도 {$minScore}점 미만 이미지 {$dropped}개 제외 → 본문에 쓸 수 있는 이미지 " . count($bodyGood) . "개 (설정 {$imageCount}개)");
            if (count($bodyGood) < $imageCount) write_log("ℹ️ 내용에 맞는 이미지가 설정({$imageCount}개)보다 적어 " . count($bodyGood) . "개만 사용합니다");
            $bodyCands = array_map(fn($b) => $b['cand'], $bodyGood);
            return ['thumb_raw' => $thumbIdx !== null ? $ready[$thumbIdx]['local_raw'] : null, 'thumb_caption' => $thumbCaption, 'body' => $bodyCands, 'ai_judged' => true];
        }

        // ★ v9: AI 판별 실패 시 처리
        // 예전에는 16:9 비율만 보고 아무 이미지나 골랐음 → 키워드가 애매하면
        // 완전히 무관한 이미지(다른 회사 로고 등)가 대표이미지로 발행되는 사고 발생.
        // 기본값: AI가 확인 못 한 웹수집 이미지는 **아예 사용하지 않음** (생성/그라데이션 폴백으로 넘어감)
        $requireJudge = intval(getKey('image_source.require_ai_judge', 1));
        if ($requireJudge) {
            write_log("🚫 AI 이미지 판별 실패 → 확인 안 된 웹수집 이미지는 사용하지 않음 (썸네일·본문 모두 생성 폴백)");
            return ['thumb_raw' => null, 'thumb_caption' => '', 'body' => [], 'ai_judged' => false, 'judge_failed' => true];
        }

        // (설정으로 끈 경우에만) 기존 16:9 비율 방식 — 단, 적합 후보 없으면 썸네일 안 씀
        $readyList = array_values($ready);
        $ti = $this->pickBestThumbnailIndex($readyList);
        $thumbRaw = ($ti >= 0) ? ($readyList[$ti]['local_raw'] ?? null) : null;
        if ($ti >= 0) unset($readyList[$ti]);
        return ['thumb_raw' => $thumbRaw, 'thumb_caption' => '', 'body' => array_values($readyList), 'ai_judged' => false];
    }

    /**
     * ★ v6: 네이버 수집 이미지 다운로드 + 변조
     * @param string $url 이미지 URL
     * @return string|null 로컬 저장 경로 (변조 완료)
     */
    public function downloadNaverImage($url) {
        // ★ v8: 이미 다운로드된 원본(local_raw)이 있는 후보 배열이 들어오면 다운로드 생략
        if (is_array($url)) {
            if (!empty($url['local_raw']) && file_exists($url['local_raw'])) {
                return $this->prepareFromRaw($url['local_raw']);
            }
            $url = $url['url'] ?? '';
        }
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer: https://search.naver.com/',
            ],
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code !== 200 || strlen($data) < 5000) {
            write_log("⚠️ 네이버 이미지 다운로드 실패: HTTP {$code}, 크기: " . strlen($data) . " bytes");
            return null;
        }

        // 이미지 유효성 확인
        $tmpPath = (defined('IMAGE_SAVE_DIR') ? IMAGE_SAVE_DIR : (__DIR__ . '/tmp_images/'))
                   . 'nv_raw_' . time() . '_' . mt_rand(1000, 9999);

        // 확장자 판단
        if (str_contains($contentType, 'webp')) $tmpPath .= '.webp';
        elseif (str_contains($contentType, 'png')) $tmpPath .= '.png';
        elseif (str_contains($contentType, 'gif')) $tmpPath .= '.gif';
        else $tmpPath .= '.jpg';

        $saveDir = dirname($tmpPath);
        if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
        file_put_contents($tmpPath, $data);

        // GD로 열 수 있는지 확인
        $info = @getimagesize($tmpPath);
        if (!$info || $info[0] < 200 || $info[1] < 150) {
            @unlink($tmpPath);
            write_log("⚠️ 네이버 이미지 유효하지 않음 (크기: " . ($info[0] ?? 0) . "x" . ($info[1] ?? 0) . ")");
            return null;
        }

        // 1단계: 리사이즈 + 압축 (ImageOptimizer::optimize)
        $optimized = ImageOptimizer::optimize($tmpPath, 'content');
        if ($optimized && $optimized !== $tmpPath) {
            @unlink($tmpPath);
            $tmpPath = $optimized;
        }

        // 2단계: 변조 (중복 감지 회피)
        $disguised = ImageOptimizer::disguise($tmpPath);
        if ($disguised && $disguised !== $tmpPath) {
            @unlink($tmpPath);
            return $disguised;
        }

        return $tmpPath;
    }

    /**
     * ★ v6: 네이버 수집 이미지로 본문 이미지 처리
     * search.php의 searchNaverImages()가 반환한 이미지 URL을 다운로드 + 변조 후 본문에 삽입
     *
     * @param string $html 본문 HTML ([IMAGE:...] 태그 포함)
     * @param array $naverImages searchNaverImages() 반환값 [['url'=>..., 'source'=>..., 'title'=>...], ...]
     * @param string $keyword 키워드 (alt 텍스트 폴백용)
     * @param int $imageCount 최대 삽입할 이미지 수
     * @return array ['content'=>수정된HTML, 'images'=>로컬파일경로배열]
     */
    public function processNaverImages($html, $naverImages, $keyword = '', $imageCount = 3, $judgeCtx = null) {
        // ★ v7: 연속 이미지 방지
        $html = $this->redistributeImageTags($html);
        // 1단계: 이미지 다운로드 + 변조
        $localImages = [];
        $localCaptions = []; // ★ v8.1: AI가 이미지를 보고 쓴 캡션 (없으면 '')
        $aiJudged = !empty($naverImages) && is_array($naverImages[0] ?? null) && array_key_exists('ai_caption', $naverImages[0]);
        $tried = 0;
        foreach ($naverImages as $imgData) {
            if (count($localImages) >= $imageCount + 1) break; // 여유분 1개
            if ($tried >= $imageCount * 2) break; // 무한 시도 방지
            $tried++;

            $url = is_array($imgData) ? ($imgData['url'] ?? '') : $imgData;
            if (!$url && empty($imgData['local_raw'])) continue;

            // ★ v8: local_raw 가 있으면 재다운로드 없이 변조만 수행
            $localPath = $this->downloadNaverImage(is_array($imgData) ? $imgData : $url);
            if ($localPath) {
                $localImages[] = $localPath;
                $localCaptions[] = is_array($imgData) ? trim((string)($imgData['ai_caption'] ?? '')) : '';
                write_log("🖼️ 네이버 이미지 준비 완료: " . basename($localPath) . " ← " . substr($url, 0, 80));
            }
            usleep(500000); // 다운로드 간 0.5초 대기
        }

        if (empty($localImages)) {
            if ($aiJudged) {
                // ★ v8.1: AI 판별 후 내용에 맞는 이미지가 0개 → 엉뚱한 이미지 대신 [IMAGE] 태그만 제거 (이미지 없이 발행)
                write_log("ℹ️ 내용에 맞는 수집 이미지가 없음 → 본문 이미지 없이 진행 ([IMAGE] 태그 제거)");
                return ['content' => preg_replace('/\[IMAGE:[^\]]*\]/', '', $html), 'images' => []];
            }
            write_log("⚠️ 웹 이미지 수집 실패 → 스톡 폴백 (★ v9.1: 스톡도 AI 판별 거침)");
            // 폴백: 스톡 경로 — 단, judgeCtx를 넘겨서 스톡 사진도 반드시 AI 판별을 거치게 함
            return $this->processImages($html, [], $judgeCtx);
        }

        write_log("🖼️ 네이버 이미지 " . count($localImages) . "개 다운로드+변조 완료");

        $paths = [];
        $usedIdx = 0;

        // 2단계: [IMAGE:...] 태그를 네이버 이미지로 교체
        $self = $this;
        $html = preg_replace_callback('/\[IMAGE:\s*(.+?)\]/', function($m) use (&$paths, &$usedIdx, $localImages, $localCaptions, $self, $keyword) {
            if ($usedIdx >= count($localImages)) return ''; // 이미지 부족하면 제거
            $p = $localImages[$usedIdx];
            // ★ v8.1: 캡션은 AI가 '실제 이미지'를 보고 쓴 것을 우선 사용. 없으면 글의 [IMAGE:] 설명
            $altText = $localCaptions[$usedIdx] ?: trim(explode('|', $m[1])[0]);
            $usedIdx++;
            $paths[] = $p;
            write_log("🖼️ 네이버 이미지 삽입: " . basename($p) . " (alt: {$altText})");
            return $self->buildFigureHtml($p, $altText, $keyword);
        }, $html);

        // 3단계: 이미지가 남아있고 [IMAGE] 태그보다 이미지가 많으면 H2 사이에 자동 삽입
        $remaining = min($imageCount - $usedIdx, count($localImages) - $usedIdx);
        if ($remaining > 0) {
            preg_match_all('/(<h2[^>]*>|<!-- wp:heading )/', $html, $matches, PREG_OFFSET_CAPTURE);
            $h2Positions = array_column($matches[0], 1);

            if (count($h2Positions) > 1) {
                $insertPoints = [];
                $step = max(1, floor(count($h2Positions) / ($remaining + 1)));
                for ($i = 0; $i < $remaining && ($step * ($i + 1)) < count($h2Positions); $i++) {
                    $insertPoints[] = $h2Positions[$step * ($i + 1)];
                }
                rsort($insertPoints); // 역순 삽입 (offset 밀림 방지)
                foreach ($insertPoints as $pos) {
                    if ($usedIdx >= count($localImages)) break;
                    $p = $localImages[$usedIdx];
                    $cap = $localCaptions[$usedIdx] ?: ($keyword . ' 관련 이미지');
                    $usedIdx++;
                    $paths[] = $p;
                    $imgHtml = "\n" . $this->buildFigureHtml($p, $cap, $keyword) . "\n";
                    $html = substr($html, 0, $pos) . $imgHtml . substr($html, $pos);
                    write_log("🖼️ 네이버 이미지 자동 삽입(H2 사이): " . basename($p));
                }
            }
        }

        write_log("🖼️ 네이버 이미지 총 " . count($paths) . "개 본문 삽입 완료");
        return ['content' => $html, 'images' => $paths];
    }

    /**
     * 로컬 이미지에서 썸네일용 이미지 선택
     */
    public function getLocalThumbnail($keyword = '') {
        $localDir = defined('LOCAL_IMAGE_DIR') ? LOCAL_IMAGE_DIR : (__DIR__ . '/local_images/');
        $keywordDir = $localDir . preg_replace('/[\/\\\\:*?"<>|]/', '_', $keyword) . '/';

        $exts = ['jpg','jpeg','png','gif','webp'];
        $dirs = [];
        if (is_dir($keywordDir)) $dirs[] = $keywordDir;
        $dirs[] = $localDir;

        foreach ($dirs as $dir) {
            $files = [];
            foreach (glob($dir . '*') as $f) {
                if (is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) {
                    $files[] = $f;
                }
            }
            if (!empty($files)) {
                // 파일명에 thumb, cover, main 이 포함된 것 우선
                foreach ($files as $f) {
                    $bn = strtolower(basename($f));
                    if (strpos($bn, 'thumb') !== false || strpos($bn, 'cover') !== false || strpos($bn, 'main') !== false) {
                        return $f;
                    }
                }
                return $files[array_rand($files)];
            }
        }
        return null;
    }

    /**
     * 한글 → 영문 검색어 변환 (키워드 기반)
     * ★ 매핑 안 되면 한글에서 핵심 단어를 추출하여 검색어로 활용
     */
    private function korToSearchTerm($text) {
        // 일반적인 한국어 블로그 키워드 → 영문 매핑
        $map = [
            '노트북'=>'laptop computer', '컴퓨터'=>'computer desktop', '스마트폰'=>'smartphone', '아이폰'=>'iphone',
            '갤럭시'=>'galaxy phone', '건강'=>'health wellness', '다이어트'=>'healthy diet food', '운동'=>'exercise fitness gym',
            '음식'=>'food cooking', '식단'=>'meal preparation', '요리'=>'cooking kitchen', '여행'=>'travel landscape',
            '서울'=>'seoul city', '카페'=>'coffee cafe interior', '맛집'=>'restaurant food', '투자'=>'investment finance',
            '주식'=>'stock market trading', '부동산'=>'real estate house', '재테크'=>'personal finance money',
            '프리랜서'=>'freelancer workspace', '유튜브'=>'video content creator', '블로그'=>'blogging writing',
            '강아지'=>'dog puppy cute', '고양이'=>'cat kitten', '반려동물'=>'pet animal', '육아'=>'parenting baby',
            '교육'=>'education learning', '영어'=>'english language study', '코딩'=>'coding programming laptop',
            '자동차'=>'car automobile', '패션'=>'fashion clothing style', '뷰티'=>'beauty skincare cosmetics',
            '인테리어'=>'interior design room', '이사'=>'moving new home', '결혼'=>'wedding ceremony',
            '취업'=>'job career office', '면접'=>'job interview', '이직'=>'career change',
            '약국'=>'pharmacy medicine', '병원'=>'hospital medical', '보험'=>'insurance document',
            '커피'=>'coffee beans brewing', '독서'=>'books reading library', '공부'=>'studying desk',
            '피부'=>'skincare beauty', '치과'=>'dental care', '안과'=>'eye care',
            '캠핑'=>'camping outdoor nature', '등산'=>'hiking mountain', '낚시'=>'fishing lake',
            '사진'=>'photography camera', '그림'=>'painting art', '음악'=>'music instrument',
            '수면'=>'sleep bedroom', '스트레스'=>'stress relief relaxation', '명상'=>'meditation mindfulness',
            '비타민'=>'vitamins supplements', '단백질'=>'protein nutrition', '채소'=>'vegetables fresh',
            '과일'=>'fruit fresh colorful', '디저트'=>'dessert cake sweet', '빵'=>'bread bakery',
            '차'=>'tea ceremony', '와인'=>'wine glass bottle', '맥주'=>'craft beer',
            '집'=>'home house interior', '아파트'=>'apartment building', '전세'=>'housing real estate',
            '세금'=>'tax document calculator', '연말정산'=>'tax return document', '급여'=>'salary paycheck',
            '창업'=>'startup business', '마케팅'=>'marketing strategy', '브랜딩'=>'branding design',
            '정리'=>'organization tidy', '청소'=>'cleaning home', '수납'=>'storage organization',
            '식물'=>'plant indoor green', '정원'=>'garden flowers', '꽃'=>'flowers bouquet',
        ];
        foreach ($map as $kr => $en) {
            if (mb_strpos($text, $kr) !== false) return $en;
        }
        
        // ★ 매핑 안 되면 한글 텍스트에서 의미 있는 단어를 추출
        // 조사/접미사 등을 제거하고 핵심 명사만 남기기
        $cleaned = preg_replace('/[은는이가을를에서의로도만요\s]+/u', ' ', $text);
        $cleaned = trim($cleaned);
        
        // 한글이 포함된 경우 → 그대로 Pixabay/Pexels에 넘기면 결과 없음
        // 최소한의 컨텍스트라도 전달
        if (preg_match('/[가-힣]/u', $cleaned)) {
            // 한글만 있으면 generic하지만 주제와 약간이라도 관련된 검색어
            return 'concept illustration abstract';
        }
        
        // 영문이 포함된 경우 → 그대로 사용
        if (preg_match('/[a-zA-Z]{3,}/', $cleaned)) {
            return $cleaned;
        }
        
        return 'concept illustration abstract';
    }

    private function text($img,$text,$w,$h,$color,$size) {
        if ($this->fontPath) {
            $mw=$w-160;$chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);$lines=[];$cur='';
            foreach($chars as $ch){$t=$cur.$ch;$bb=imagettfbbox($size,0,$this->fontPath,$t);if(($bb[2]-$bb[0])>$mw&&$cur){$lines[]=$cur;$cur=$ch;}else $cur.=$ch;}
            if($cur)$lines[]=$cur; $lh=$size*1.8;$sy=($h-count($lines)*$lh)/2+$size;
            foreach($lines as $i=>$l){$bb=imagettfbbox($size,0,$this->fontPath,$l);imagettftext($img,$size,0,(int)max(80,($w-($bb[2]-$bb[0]))/2),(int)($sy+$i*$lh),$color,$this->fontPath,$l);}
        } else { imagestring($img,5,60,(int)($h/2),$text,$color); }
    }
}


/**
 * 이미지 최적화 유틸리티
 */
class ImageOptimizer {

    /**
     * ★ v6: 이미지 변조 — 중복 이미지 감지 회피
     * GD로 원본에 랜덤 변형을 적용하여 해시값을 완전히 변경
     * @param string $srcPath 원본 이미지 경로
     * @return string|null 변조된 이미지 경로
     */
    public static function disguise($srcPath, $pad = true) {
        if (!file_exists($srcPath)) return null;

        $info = @getimagesize($srcPath);
        if (!$info) return null;

        $origW = $info[0]; $origH = $info[1];
        if ($origW < 100 || $origH < 100) return null;

        // 이미지 로드
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
            default: return $srcPath;
        }
        if (!$src) return $srcPath;

        // ── 변조 1: 캔버스 덧대기 (★ v8.1: 크롭 대신) ──
        //    원본은 한 픽셀도 자르지 않고 비율 그대로 둔 채, 바깥에 3~9% 여백(캔버스)을 랜덤 비대칭으로 덧댐.
        //    여백은 ① 원본을 확대+블러한 '번짐 배경' 또는 ② 이미지 가장자리 평균색의 부드러운 단색.
        //    → 해상도/비율/픽셀 배치가 모두 달라지면서도 원본 내용은 100% 보존됨.
        $padPctW = $pad ? mt_rand(3, 9) / 100 : 0;
        $padPctH = $pad ? mt_rand(3, 9) / 100 : 0;
        $padL = (int)($origW * $padPctW * (mt_rand(20, 80) / 100));
        $padR = (int)($origW * $padPctW) - $padL;
        $padT = (int)($origH * $padPctH * (mt_rand(20, 80) / 100));
        $padB = (int)($origH * $padPctH) - $padT;
        $newW = $origW + $padL + $padR;
        $newH = $origH + $padT + $padB;

        $canvas = imagecreatetruecolor($newW, $newH);
        $padStyle = !$pad ? 'none' : ((mt_rand(1, 100) <= 60) ? 'blur' : 'solid');
        if ($padStyle === 'none') {
            // 덧대기 없음 (썸네일용)
        } elseif ($padStyle === 'blur') {
            // 원본을 캔버스 크기로 늘려서 깔고 강하게 블러 → 번짐 배경
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            for ($b = 0; $b < 12; $b++) imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -mt_rand(10, 30));
        } else {
            // 가장자리 픽셀 평균색 (밝게 보정) 으로 채움
            $sumR = $sumG = $sumB = $n = 0;
            $stepX = max(1, (int)($origW / 40)); $stepY = max(1, (int)($origH / 40));
            for ($x = 0; $x < $origW; $x += $stepX) {
                foreach ([0, $origH - 1] as $y) { $c = imagecolorsforindex($src, imagecolorat($src, $x, $y)); $sumR += $c['red']; $sumG += $c['green']; $sumB += $c['blue']; $n++; }
            }
            for ($y = 0; $y < $origH; $y += $stepY) {
                foreach ([0, $origW - 1] as $x) { $c = imagecolorsforindex($src, imagecolorat($src, $x, $y)); $sumR += $c['red']; $sumG += $c['green']; $sumB += $c['blue']; $n++; }
            }
            $n = max(1, $n);
            $mix = mt_rand(55, 75) / 100; // 흰색과 섞어 부드럽게
            $r = (int)(($sumR / $n) * (1 - $mix) + 255 * $mix);
            $g = (int)(($sumG / $n) * (1 - $mix) + 255 * $mix);
            $bcol = (int)(($sumB / $n) * (1 - $mix) + 255 * $mix);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $bcol));
        }
        // 원본을 그대로(리샘플 없이) 올림 — 비율/내용 100% 보존
        imagecopy($canvas, $src, $padL, $padT, 0, 0, $origW, $origH);
        imagedestroy($src);
        $src = $canvas;

        // ── 변조 1-2: 좌우 반전 (옵션, 기본 OFF) ──
        //    ★ v8: api_keys.json → image_source.disguise_flip = true 로 켜면 30% 확률로 반전.
        //    (이미지 안에 글자가 있으면 거울처럼 뒤집혀 보이므로 기본은 끔)
        $flipped = false;
        if (getKey('image_source.disguise_flip', false) && mt_rand(1, 100) <= 30) {
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $flipped = true;
        }

        // ── 변조 2: 밝기 미세 조정 (±5~12) ──
        $brightness = mt_rand(-12, 12);
        if (abs($brightness) < 5) $brightness = ($brightness >= 0 ? 5 : -5);
        imagefilter($src, IMG_FILTER_BRIGHTNESS, $brightness);

        // ── 변조 3: 대비 미세 조정 (±3~8) ──
        $contrast = mt_rand(-8, 8);
        if (abs($contrast) < 3) $contrast = ($contrast >= 0 ? 3 : -3);
        imagefilter($src, IMG_FILTER_CONTRAST, $contrast);

        // ── 변조 3-2: 색감 변경 (★ v8 신규) ──
        //    웜톤/쿨톤/그린틴트/채도 낮춤/감마 중 하나를 랜덤 적용 → 색 히스토그램이 달라짐
        $toneNames = ['warm', 'cool', 'green', 'desat', 'gamma_up', 'gamma_down'];
        $tone = $toneNames[array_rand($toneNames)];
        switch ($tone) {
            case 'warm':   // 붉은/노란 기운
                imagefilter($src, IMG_FILTER_COLORIZE, mt_rand(8, 16), mt_rand(2, 8), -mt_rand(6, 14));
                break;
            case 'cool':   // 푸른 기운
                imagefilter($src, IMG_FILTER_COLORIZE, -mt_rand(6, 14), mt_rand(0, 4), mt_rand(8, 16));
                break;
            case 'green':  // 살짝 그린 틴트 (필름 느낌)
                imagefilter($src, IMG_FILTER_COLORIZE, -mt_rand(2, 6), mt_rand(6, 12), -mt_rand(2, 6));
                break;
            case 'desat':  // 채도 낮춤: 흑백 복사본을 10~25% 섞음
                $gray = imagecreatetruecolor(imagesx($src), imagesy($src));
                imagecopy($gray, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                imagefilter($gray, IMG_FILTER_GRAYSCALE);
                imagecopymerge($src, $gray, 0, 0, 0, 0, imagesx($src), imagesy($src), mt_rand(10, 25));
                imagedestroy($gray);
                break;
            case 'gamma_up':
                imagegammacorrect($src, 1.0, mt_rand(108, 120) / 100);
                break;
            case 'gamma_down':
                imagegammacorrect($src, 1.0, mt_rand(85, 94) / 100);
                break;
        }

        // ── 변조 3-3: 비네팅 (40% 확률, 가장자리 살짝 어둡게) ──
        $vignette = false;
        if (mt_rand(1, 100) <= 40) {
            $vignette = true;
            imagealphablending($src, true);
            $vw = imagesx($src); $vh = imagesy($src);
            $band = max(8, (int)(min($vw, $vh) * mt_rand(6, 10) / 100)); // 가장자리 6~10% 영역
            $strength = mt_rand(22, 38);                                  // 최대 어두움 (0~127 중)
            imagesetthickness($src, 1);
            for ($i = 0; $i < $band; $i++) {
                $t = 1 - ($i / $band);                                    // 바깥 1 → 안쪽 0
                $alpha = 127 - (int)($strength * $t * $t);                // 부드럽게 감소
                $c = imagecolorallocatealpha($src, 0, 0, 0, min(127, max(0, $alpha)));
                imagerectangle($src, $i, $i, $vw - 1 - $i, $vh - 1 - $i, $c);
            }
            imagesetthickness($src, 1);
        }

        // ── 변조 4: 얇은 테두리 추가 (랜덤 색상, 1~3px) ──
        $borderW = mt_rand(1, 3);
        $borderStyles = ['white', 'light_gray', 'soft_shadow', 'subtle_color'];
        $borderStyle = $borderStyles[array_rand($borderStyles)];

        $bW = imagesx($src); $bH = imagesy($src);
        switch ($borderStyle) {
            case 'white':
                $bc = imagecolorallocate($src, 255, 255, 255);
                break;
            case 'light_gray':
                $g = mt_rand(220, 245);
                $bc = imagecolorallocate($src, $g, $g, $g);
                break;
            case 'soft_shadow':
                $bc = imagecolorallocate($src, mt_rand(180, 210), mt_rand(180, 210), mt_rand(180, 210));
                break;
            case 'subtle_color':
                $bc = imagecolorallocate($src, mt_rand(200, 240), mt_rand(200, 240), mt_rand(210, 245));
                break;
            default:
                $bc = imagecolorallocate($src, 240, 240, 240);
        }
        // 상하좌우 테두리 그리기
        for ($i = 0; $i < $borderW; $i++) {
            imagerectangle($src, $i, $i, $bW - 1 - $i, $bH - 1 - $i, $bc);
        }

        // ── 변조 5: 랜덤으로 추가 효과 (50% 확률) ──
        $extraEffect = mt_rand(0, 3);
        switch ($extraEffect) {
            case 0: // 약간의 가우시안 블러 (매우 미세)
                if (function_exists('imagefilter')) {
                    imagefilter($src, IMG_FILTER_SMOOTH, mt_rand(6, 10));
                }
                break;
            case 1: // 채도 미세 변경 (colorize)
                imagefilter($src, IMG_FILTER_COLORIZE, mt_rand(-5, 5), mt_rand(-5, 5), mt_rand(-5, 5));
                break;
            case 2: // ★ v8: 약한 샤픈 (픽셀값 변화)
                imageconvolution($src, [[0, -0.3, 0], [-0.3, 2.2, -0.3], [0, -0.3, 0]], 1, 0);
                break;
            // case 3: 추가 효과 없음
        }

        // ── 저장: 랜덤 품질로 재인코딩 ──
        $saveDir = defined('IMAGE_SAVE_DIR') ? IMAGE_SAVE_DIR : (__DIR__ . '/tmp_images/');
        if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
        $basename = 'nv_' . time() . '_' . mt_rand(1000, 9999);

        $quality = mt_rand(75, 85); // ★ 품질 향상 (65~78 → 75~85): 해시는 변경되면서 화질 유지
        if (function_exists('imagewebp')) {
            $outPath = $saveDir . $basename . '.webp';
            imagewebp($src, $outPath, $quality);
        } else {
            $outPath = $saveDir . $basename . '.jpg';
            imagejpeg($src, $outPath, $quality);
        }
        imagedestroy($src);

        $origSize = filesize($srcPath);
        $newSize = filesize($outPath);
        write_log("🎭 이미지 변조: " . basename($srcPath) . " → " . basename($outPath) . " (" . round($newSize/1024) . "KB, 덧대기:" . round($padPctW*100) . "/" . round($padPctH*100) . "%({$padStyle}) 색감:{$tone}" . ($flipped ? ' 반전' : '') . ($vignette ? ' 비네팅' : '') . " 밝기:{$brightness} 대비:{$contrast} 테두리:{$borderW}px)");

        return $outPath;
    }

    /**
     * 이미지 최적화: 리사이즈 + 압축 + WebP 변환
     * @param string $srcPath 원본 이미지 경로
     * @param string $type 'thumbnail' (1200x630) 또는 'content' (1200xauto) 또는 'auto'
     * @return string|null 최적화된 이미지 경로
     */
    public static function optimize($srcPath, $type = 'auto') {
        if (!file_exists($srcPath)) return null;

        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        $info = @getimagesize($srcPath);
        if (!$info) return null;

        $origW = $info[0]; $origH = $info[1];

        // 타입별 최대 크기
        // ★ content 이미지: 800px로 제한 (720px로 출력하므로 800px이면 충분히 선명)
        // 1200px 원본을 720px로 출력하면 선명하지만, 네이버 수집 이미지처럼
        // 원본이 작은 경우 1200px로 키우면 오히려 깨져 보임
        switch ($type) {
            case 'thumbnail': $maxW = 1200; $maxH = 630; $crop = true; break;
            case 'content':   $maxW = 800; $maxH = 1400; $crop = false; break;
            default:          $maxW = 800; $maxH = 1400; $crop = false; break;
        }

        // 이미지 로드
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
            default: return $srcPath; // 지원 안 하는 포맷은 원본 반환
        }
        if (!$src) return $srcPath;

        if ($crop && $type === 'thumbnail') {
            // ★ v8.1: 크롭 대신 "비율 유지 + 번짐 배경" (레터박스)
            //   1200x630 캔버스에 원본을 확대+블러해서 배경으로 깔고, 그 위에 원본을 비율 그대로 맞춰(contain) 올림.
            //   → 원본이 잘리지 않음. 16:9가 아닌 이미지도 대표이미지로 자연스럽게 사용 가능.
            $dst = imagecreatetruecolor($maxW, $maxH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $maxH, $origW, $origH);
            for ($b = 0; $b < 14; $b++) imagefilter($dst, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($dst, IMG_FILTER_BRIGHTNESS, -25);
            $scale = min($maxW / $origW, $maxH / $origH);
            $fitW = max(1, (int)round($origW * $scale)); $fitH = max(1, (int)round($origH * $scale));
            $offX = (int)(($maxW - $fitW) / 2); $offY = (int)(($maxH - $fitH) / 2);
            imagecopyresampled($dst, $src, $offX, $offY, 0, 0, $fitW, $fitH, $origW, $origH);
        } else {
            // 리사이즈 모드: 비율 유지
            if ($origW <= $maxW && $origH <= $maxH) {
                // 이미 작으면 리사이즈 불필요 → 압축만
                $dst = $src;
                $src = null; // destroy 방지
            } else {
                $ratio = min($maxW / $origW, $maxH / $origH);
                $newW = (int)($origW * $ratio); $newH = (int)($origH * $ratio);
                $dst = imagecreatetruecolor($newW, $newH);
                // 투명도 유지 (PNG)
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            }
        }

        // 저장 (WebP 우선, 미지원 시 JPEG)
        $saveDir = defined('IMAGE_SAVE_DIR') ? IMAGE_SAVE_DIR : (__DIR__ . '/tmp_images/');
        if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
        $basename = 'opt_' . time() . '_' . mt_rand(1000, 9999);

        if (function_exists('imagewebp')) {
            $outPath = $saveDir . $basename . '.webp';
            imagewebp($dst, $outPath, 82);
        } else {
            $outPath = $saveDir . $basename . '.jpg';
            imagejpeg($dst, $outPath, 82);
        }

        imagedestroy($dst);
        if ($src) imagedestroy($src);

        $origSize = filesize($srcPath);
        $newSize = filesize($outPath);
        $pct = $origSize > 0 ? round((1 - $newSize / $origSize) * 100) : 0;
        write_log("이미지 최적화: " . basename($srcPath) . " → " . basename($outPath) . " ({$pct}% 절감, " . round($newSize/1024) . "KB)");

        return $outPath;
    }
}
