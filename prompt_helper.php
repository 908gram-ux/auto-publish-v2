<?php
/**
 * 프롬프트 헬퍼 — 멀티 프롬프트 슬롯 지원
 * 
 * auto_publish.php / setup.php에서 require_once 하세요
 * 
 * 사용법:
 *   require_once __DIR__ . '/prompt_helper.php';
 *   $blogPrompt = getCustomPrompt('blog', $defaultBlogPrompt);
 *   $imagePrompt = getCustomPrompt('image', $defaultImagePrompt);
 */

// ── 슬롯 데이터 경로 ──
function _promptSlotsFile($type) {
    return __DIR__ . '/data/prompts/' . preg_replace('/[^a-z0-9_-]/', '', $type) . '_slots.json';
}

// ── 슬롯 데이터 로드 (마이그레이션 포함) ──
function loadPromptSlots($type) {
    $file = _promptSlotsFile($type);
    $default = ['mode' => 'rotation', 'fixed_id' => null, 'last_index' => 0, 'slots' => []];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data) && isset($data['slots'])) return $data;
    }

    // 기존 단일 프롬프트 파일 마이그레이션
    $oldFile = __DIR__ . '/data/prompts/' . preg_replace('/[^a-z0-9_-]/', '', $type) . '.txt';
    if (file_exists($oldFile) && trim(file_get_contents($oldFile))) {
        $default['slots'][] = [
            'id' => 's1',
            'name' => '기존 프롬프트',
            'content' => file_get_contents($oldFile),
            'active' => true,
            'created' => date('Y-m-d H:i:s'),
            'use_count' => 0,
        ];
        savePromptSlots($type, $default);
    }

    return $default;
}

// ── 슬롯 데이터 저장 ──
function savePromptSlots($type, $data) {
    $dir = __DIR__ . '/data/prompts';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = _promptSlotsFile($type);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 하위 호환: 선택된 프롬프트를 기존 .txt에도 저장
    $selected = _getSelectedSlot($data);
    $txtFile = $dir . '/' . preg_replace('/[^a-z0-9_-]/', '', $type) . '.txt';
    file_put_contents($txtFile, $selected ? $selected['content'] : '');
}

// ── 모드에 따라 프롬프트 선택 ──
function _getSelectedSlot($data) {
    $activeSlots = array_values(array_filter($data['slots'] ?? [], function($s) { return !empty($s['active']); }));
    if (empty($activeSlots)) {
        $activeSlots = array_values($data['slots'] ?? []);
    }
    if (empty($activeSlots)) return null;

    $mode = $data['mode'] ?? 'rotation';

    if ($mode === 'fixed' && !empty($data['fixed_id'])) {
        foreach ($activeSlots as $s) {
            if ($s['id'] === $data['fixed_id']) return $s;
        }
        return $activeSlots[0];
    }

    if ($mode === 'random') {
        return $activeSlots[array_rand($activeSlots)];
    }

    // rotation (순차)
    $idx = (int)($data['last_index'] ?? 0);
    if ($idx >= count($activeSlots)) $idx = 0;
    return $activeSlots[$idx];
}

// ── 사용 후 rotation 인덱스 증가 ──
function advancePromptRotation($type) {
    $data = loadPromptSlots($type);
    if (($data['mode'] ?? 'rotation') !== 'rotation') return;
    $activeSlots = array_values(array_filter($data['slots'] ?? [], function($s) { return !empty($s['active']); }));
    if (empty($activeSlots)) $activeSlots = array_values($data['slots'] ?? []);
    if (count($activeSlots) <= 1) return;

    $idx = (int)($data['last_index'] ?? 0);
    $data['last_index'] = ($idx + 1) % count($activeSlots);

    // 사용 카운트 증가
    $usedId = ($activeSlots[$idx] ?? null) ? $activeSlots[$idx]['id'] : null;
    if ($usedId) {
        foreach ($data['slots'] as &$s) {
            if ($s['id'] === $usedId) { $s['use_count'] = ($s['use_count'] ?? 0) + 1; break; }
        }
        unset($s);
    }

    savePromptSlots($type, $data);
}

// ── 메인 함수: 커스텀 프롬프트 가져오기 (멀티슬롯 지원) ──
function getCustomPrompt($type, $default = '') {
    $data = loadPromptSlots($type);

    if (empty($data['slots'])) {
        // 슬롯 없으면 기존 단일 파일 체크 (하위 호환)
        $file = __DIR__ . '/data/prompts/' . preg_replace('/[^a-z0-9_-]/', '', $type) . '.txt';
        if (file_exists($file) && trim(file_get_contents($file))) {
            return file_get_contents($file);
        }
        return $default;
    }

    $slot = _getSelectedSlot($data);
    if (!$slot || !trim($slot['content'])) return $default;

    // rotation 모드면 인덱스 전진
    if (($data['mode'] ?? 'rotation') === 'rotation') {
        advancePromptRotation($type);
    } else {
        // random/fixed도 사용 카운트 증가
        foreach ($data['slots'] as &$s) {
            if ($s['id'] === $slot['id']) { $s['use_count'] = ($s['use_count'] ?? 0) + 1; break; }
        }
        unset($s);
        savePromptSlots($type, $data);
    }

    return $slot['content'];
}

/**
 * 이미지 프롬프트를 한글 중심으로 변환
 */
function buildImagePrompt($title, $keyword) {
    $customPrompt = getCustomPrompt('image', '');
    
    if ($customPrompt) {
        return str_replace(
            ['{{TITLE}}', '{{KEYWORD}}'],
            [$title, $keyword],
            $customPrompt
        );
    }
    
    return "블로그 대표 이미지를 생성해주세요.

제목: {$title}
키워드: {$keyword}

■ 반드시 한글 텍스트 '{$keyword}'를 이미지 중앙에 크게 포함하세요
■ '{$title}' 주제를 직관적으로 보여주는 구체적인 일러스트
■ 플랫 디자인 또는 3D 아이소메트릭 스타일
■ 밝고 선명한 파스텔 톤 배경
■ 블로그 썸네일에 적합한 16:9 비율
■ 깔끔하고 전문적인 인포그래픽 느낌
■ 추상적이거나 모호한 이미지 절대 금지 — 주제가 한눈에 보여야 함";
}

/**
 * 블로그 글 프롬프트를 구성
 */
function buildBlogPrompt($keyword, $title, $minLen = 2500, $maxLen = 4000, $internalLinks = '') {
    $customPrompt = getCustomPrompt('blog', '');
    
    if ($customPrompt) {
        return str_replace(
            ['{{KEYWORD}}', '{{TITLE}}', '{{MIN_LENGTH}}', '{{MAX_LENGTH}}', '{{INTERNAL_LINKS}}'],
            [$keyword, $title, $minLen, $maxLen, $internalLinks],
            $customPrompt
        );
    }
    
    return '';
}
