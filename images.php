<?php
/**
 * 이미지: 스톡 검색, 생성, 최적화
 */

require_once __DIR__ . '/config.php';


class StockImageSearch {

    /**
     * 키워드로 스톡 이미지 검색 & 다운로드
     * @param string $query 영문 검색어
     * @param string $type 'thumbnail' (1200x630) or 'section' (800x400)
     * @return string|null 로컬 저장 경로
     */
    public function search($query, $type = 'section') {
        $keys = loadApiKeys();
        $imgSettings = $keys['image_source'] ?? [];
        $priority = $imgSettings['priority'] ?? ['pixabay', 'pexels', 'gradient'];
        write_log("🔍 이미지 검색어: \"{$query}\" (type: {$type})");

        // 1) 우선순위 소스 시도 (gradient 제외)
        $tried = [];
        foreach ($priority as $source) {
            if ($source === 'gradient') continue; // gradient는 최후 폴백
            $tried[] = $source;
            $path = $this->tryImageSource($source, $query, $type);
            if ($path) {
                write_log("이미지({$source}): {$query}");
                return $path;
            }
        }

        // 2) 우선순위에 없었던 나머지 API 소스도 자동 폴백
        $allSources = ['pixabay', 'pexels', 'gemini', 'dalle'];
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

        $imageModel = getKey('gemini.image_model', 'gemini-2.5-flash-image');
        if (!$imageModel) $imageModel = 'gemini-2.5-flash-image';

        // 만료된 모델 자동 교정
        $deprecated = [
            'gemini-2.5-flash-preview-image-generation' => 'gemini-2.5-flash-image',
            'gemini-2.5-flash-image-preview' => 'gemini-2.5-flash-image',
            'gemini-2.0-flash-exp-image-generation' => 'gemini-2.5-flash-image',
            'gemini-2.0-flash-preview-image-generation' => 'gemini-2.5-flash-image',
            'imagen-3.0-generate-002' => 'imagen-4.0-generate-001',
            'imagen-3.0-generate-001' => 'imagen-4.0-generate-001',
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

        // ★ v3: 게시글 제목이 있으면 이미지 context에 추가
        $titleContext = '';
        if (!empty($this->_currentTitle)) {
            $titleContext = " The blog post title is: \"{$this->_currentTitle}\". Make the image relevant to this topic.";
        }

        // Imagen 모델은 다른 API 엔드포인트 사용
        if (str_starts_with($imageModel, 'imagen')) {
            return $this->fromImagen($apiKey, $imageModel, $query, $type, $titleContext);
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$imageModel}:generateContent?key={$apiKey}";

        // 스타일 다양화 - 사실적 사진 스타일 위주 (v3)
        $styles = [
            'Professional stock photography, natural lighting, sharp focus, realistic details, high resolution',
            'Cinematic wide-angle photography, dramatic natural lighting, shallow depth of field, photorealistic',
            'Editorial magazine photography, clean composition, studio-quality lighting, realistic textures',
            'Documentary-style photography, candid feel, natural colors, authentic atmosphere',
            'High-end commercial photography, product-shot quality, soft diffused lighting, crisp details',
            'Lifestyle photography, warm natural tones, cozy atmosphere, realistic setting',
            'Modern photojournalism style, vivid colors, dynamic composition, real-world context',
            'Fine art photography, golden hour lighting, rich colors, professional DSLR quality',
        ];
        $chosenStyle = $styles[array_rand($styles)];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [['parts' => [['text' => "Create a realistic, photographic blog image about: {$query}.{$titleContext} Style: {$chosenStyle}. NO text, NO letters, NO words, NO watermarks in the image. Horizontal {$size} ratio. Ultra high quality, photorealistic."]]]],
                'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT'], 'maxOutputTokens' => 2048],
            ]),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) {
            write_log("Gemini 이미지 HTTP {$code} (모델: {$imageModel})");
            if ($code === 404) {
                write_log("💡 '{$imageModel}' 만료/미지원. 관리자 → 전체 키 관리에서 'Nano Banana (추천)' 또는 'Imagen 4 Fast'로 변경하세요");
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
        $prompt = "A {$chosenStyle} blog image about: {$query}.{$titleContext} NO text, NO letters, NO watermarks. Photorealistic, high quality, professional.";

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
            if ($code === 404) write_log("💡 '{$model}' 만료. 관리자에서 'Imagen 4 Fast' 선택 권장");
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

        $dalleModel = getKey('chatgpt.image_model', 'dall-e-2');
        if (!$dalleModel) $dalleModel = 'dall-e-2';

        // 모델별 사이즈 설정
        $size = ($dalleModel === 'dall-e-3') ? '1792x1024' : '1024x1024';

        $styles = [
            'minimalist flat illustration', 'watercolor painting', 'isometric 3D art',
            'cinematic photography', 'abstract geometric', 'modern editorial',
            'digital art with gradients', 'retro vintage poster', 'paper cut-out layers',
        ];
        $chosenStyle = $styles[array_rand($styles)];
        $prompt = "A {$chosenStyle} blog header image about: {$query}. NO text, NO letters, NO people, NO faces, NO hands. Only visual elements and objects. High quality, professional.";

        $body = ['model' => $dalleModel, 'prompt' => $prompt, 'n' => 1, 'size' => $size, 'response_format' => 'b64_json'];
        if ($dalleModel === 'dall-e-3') $body['quality'] = 'standard';

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) {
            write_log("DALL-E HTTP {$code} (모델: {$dalleModel})");
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
     * 썸네일: AI 배경 이미지 생성 + PHP GD 제목 텍스트 오버레이 (v4)
     * 1차: AI로 배경 이미지 생성 → 텍스트 오버레이
     * 2차: 스톡 이미지 검색 → 텍스트 오버레이
     * 3차: 그라데이션 폴백
     */
    public function createThumbnail($title, $keyword = '') {
        $query = $keyword ?: $this->korToSearchTerm($title);
        $this->_currentTitle = $title;
        
        // 1차: AI 이미지로 배경 생성 시도
        $bgPath = null;
        $apiKey = getKey('gemini.api_key');
        $imageModel = getKey('gemini.image_model', '');
        if ($apiKey && $imageModel) {
            $bgPath = $this->fromGemini($query, 'thumbnail');
        }
        
        // 2차: 스톡 이미지 폴백
        if (!$bgPath) {
            $bgPath = $this->stock->search($query, 'thumbnail');
        }
        
        // 배경 이미지가 있으면 텍스트 오버레이
        if ($bgPath && file_exists($bgPath)) {
            $overlaid = $this->overlayTextOnImage($bgPath, $title);
            if ($overlaid) return $overlaid;
        }
        
        // 3차: 그라데이션 폴백
        write_log("배경 이미지 없음 → 그라데이션 썸네일");
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

    /**
     * 배경 이미지 위에 제목 텍스트 오버레이 (뉴스 썸네일 스타일)
     */
    private function overlayTextOnImage($bgPath, $title) {
        $w = 1200; $h = 630;
        
        // 배경 이미지 로드
        $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg': case 'jpeg': $bg = @imagecreatefromjpeg($bgPath); break;
            case 'png': $bg = @imagecreatefrompng($bgPath); break;
            case 'webp': $bg = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($bgPath) : null; break;
            default: $bg = null;
        }
        if (!$bg) return null;
        
        // 1200x630으로 리사이즈
        $srcW = imagesx($bg); $srcH = imagesy($bg);
        $canvas = imagecreatetruecolor($w, $h);
        imagecopyresampled($canvas, $bg, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
        imagedestroy($bg);
        
        // 하단 그라데이션 오버레이 (텍스트 가독성)
        for ($y = (int)($h * 0.35); $y < $h; $y++) {
            $alpha = (int)(($y - $h * 0.35) / ($h * 0.65) * 100);
            $alpha = min(100, $alpha);
            $overlay = imagecolorallocatealpha($canvas, 0, 0, 0, 127 - (int)($alpha * 1.1));
            imageline($canvas, 0, $y, $w, $y, $overlay);
        }
        
        // 폰트 확인
        $fontPath = defined('FONT_PATH') ? FONT_PATH : (__DIR__ . '/fonts/NanumGothicBold.ttf');
        if (!file_exists($fontPath)) {
            write_log("⚠️ 폰트 없음: {$fontPath}");
            imagedestroy($canvas);
            return null;
        }
        
        // 제목 텍스트 줄바꿈 처리 (한 줄 최대 16자)
        $maxCharsPerLine = 16;
        $titleLines = [];
        $remaining = $title;
        while (mb_strlen($remaining) > 0) {
            if (mb_strlen($remaining) <= $maxCharsPerLine) {
                $titleLines[] = $remaining;
                break;
            }
            $line = mb_substr($remaining, 0, $maxCharsPerLine);
            // 단어 중간 끊김 방지 (공백/쉼표/마침표에서 끊기)
            $lastSpace = mb_strrpos($line, ' ');
            $lastComma = mb_strrpos($line, ',');
            $breakPos = max($lastSpace ?: 0, $lastComma ?: 0);
            if ($breakPos > $maxCharsPerLine * 0.4) {
                $line = mb_substr($remaining, 0, $breakPos + 1);
            }
            $titleLines[] = trim($line);
            $remaining = trim(mb_substr($remaining, mb_strlen($line)));
        }
        // 최대 3줄
        $titleLines = array_slice($titleLines, 0, 3);
        
        // 텍스트 렌더링
        $fontSize = 42;
        $lineHeight = (int)($fontSize * 1.6);
        $totalTextH = count($titleLines) * $lineHeight;
        $startY = $h - $totalTextH - 40; // 하단에서 40px 위
        
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $shadow = imagecolorallocate($canvas, 0, 0, 0);
        
        foreach ($titleLines as $i => $line) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
            $textW = $bbox[2] - $bbox[0];
            $x = 50; // 왼쪽 정렬, 50px 마진
            $y = $startY + ($i * $lineHeight) + $fontSize;
            
            // 그림자
            imagettftext($canvas, $fontSize, 0, $x + 2, $y + 2, $shadow, $fontPath, $line);
            imagettftext($canvas, $fontSize, 0, $x + 1, $y + 1, $shadow, $fontPath, $line);
            // 본문
            imagettftext($canvas, $fontSize, 0, $x, $y, $white, $fontPath, $line);
        }
        
        // 저장
        $path = IMAGE_SAVE_DIR . 'thumb_overlay_' . time() . '_' . mt_rand(1000,9999) . '.jpg';
        imagejpeg($canvas, $path, 92);
        imagedestroy($canvas);
        
        // 원본 배경 이미지 삭제 (임시 파일)
        @unlink($bgPath);
        
        write_log("🖼️ 썸네일 생성: AI 배경 + 텍스트 오버레이");
        return $path;
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
     * 본문 이미지 처리
     * [IMAGE: 설명 | search_term] 형식 지원
     */
    /**
     * 본문 이미지 처리
     * [IMAGE: 설명 | search_term] 형식 지원
     * $aiSearches: AI가 생성한 영문 검색어 배열 (폴백용)
     */
    public function processImages($html, $aiSearches = []) {
        $paths = [];
        $imgIdx = 0;
        $html = preg_replace_callback('/\[IMAGE:\s*(.+?)\]/', function($m) use(&$paths, &$imgIdx, $aiSearches) {
            $parts = explode('|', $m[1], 2);
            $altText = trim($parts[0]);

            // 검색어 우선순위: [IMAGE:설명|검색어] > AI image_searches > 한글변환
            if (isset($parts[1]) && trim($parts[1])) {
                $searchTerm = trim($parts[1]);
            } elseif (!empty($aiSearches[$imgIdx])) {
                $searchTerm = $aiSearches[$imgIdx];
            } else {
                $searchTerm = $this->korToSearchTerm($altText);
            }
            $imgIdx++;

            write_log("이미지 검색: \"{$searchTerm}\" (alt: {$altText})");

            // 스톡 이미지 검색
            $p = $this->stock->search($searchTerm, 'section');
            if (!$p) {
                $p = $this->createGradientSection($altText);
            }
            $paths[] = $p;
            return "<!-- wp:image {\"align\":\"center\",\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image aligncenter size-large\" style=\"max-width:1080px;margin:12px auto;\"><img src=\"{$p}\" alt=\"".htmlspecialchars($altText)."\" style=\"width:100%;height:auto;display:block;\"/></figure>\n<!-- /wp:image -->";
        }, $html);
        return ['content'=>$html, 'images'=>$paths];
    }

    /**
     * 로컬 폴더 이미지로 본문 이미지 처리
     * local_images/ 폴더에서 이미지를 가져와서 [IMAGE:...] 태그를 교체하고,
     * 남은 이미지는 본문 중간에 자동 삽입
     */
    public function processLocalImages($html, $keyword = '', $imageCount = 3) {
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
        $html = preg_replace_callback('/\[IMAGE:\s*(.+?)\]/', function($m) use(&$paths, &$usedIdx, $available) {
            if ($usedIdx >= count($available)) return ''; // 이미지 부족하면 제거
            $p = $available[$usedIdx++];
            $altText = trim(explode('|', $m[1])[0]);
            $paths[] = $p;
            write_log("로컬 이미지 삽입: " . basename($p) . " (alt: {$altText})");
            return "<!-- wp:image {\"align\":\"center\",\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image aligncenter size-large\" style=\"max-width:1080px;margin:12px auto;\"><img src=\"{$p}\" alt=\"".htmlspecialchars($altText)."\" style=\"width:100%;height:auto;display:block;\"/></figure>\n<!-- /wp:image -->";
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
                    $imgHtml = "\n<!-- wp:image {\"align\":\"center\",\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image aligncenter size-large\" style=\"max-width:1080px;margin:12px auto;\"><img src=\"{$p}\" alt=\"".htmlspecialchars($keyword)."\" style=\"width:100%;height:auto;display:block;\"/></figure>\n<!-- /wp:image -->\n";
                    $html = substr($html, 0, $pos) . $imgHtml . substr($html, $pos);
                    write_log("로컬 이미지 자동 삽입: " . basename($p));
                }
            }
        }

        write_log("로컬 이미지 총 " . count($paths) . "개 삽입 (폴더에 " . count($available) . "개 보유)");
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
        switch ($type) {
            case 'thumbnail': $maxW = 1200; $maxH = 630; $crop = true; break;
            case 'content':   $maxW = 1200; $maxH = 2000; $crop = false; break;
            default:          $maxW = 1200; $maxH = 2000; $crop = false; break;
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
            // 크롭 모드: 1200x630 비율로 중앙 크롭
            $targetRatio = $maxW / $maxH;
            $srcRatio = $origW / $origH;
            if ($srcRatio > $targetRatio) {
                $cropH = $origH; $cropW = (int)($origH * $targetRatio);
                $cropX = (int)(($origW - $cropW) / 2); $cropY = 0;
            } else {
                $cropW = $origW; $cropH = (int)($origW / $targetRatio);
                $cropX = 0; $cropY = (int)(($origH - $cropH) / 2);
            }
            $dst = imagecreatetruecolor($maxW, $maxH);
            imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $maxW, $maxH, $cropW, $cropH);
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
