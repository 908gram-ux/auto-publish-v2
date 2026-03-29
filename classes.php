<?php
/**
 * 클래스 로더 - 같은 폴더의 클래스 파일들을 로드
 */

$classDir = __DIR__ . '/';

require_once $classDir . 'prompt.php';
require_once $classDir . 'ai_providers.php';
require_once $classDir . 'search.php';
require_once $classDir . 'images.php';
require_once $classDir . 'wordpress.php';
require_once $classDir . 'ping.php';
require_once $classDir . 'indexing.php';
