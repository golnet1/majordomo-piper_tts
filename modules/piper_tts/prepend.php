<?php
if (PHP_SAPI === 'cli') return;
if (!isset($_SERVER['REQUEST_URI'])) return;
$uri = $_SERVER['REQUEST_URI'];
if (preg_match('#/(admin|popup/)#', $uri)) return;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return;

ob_start(function ($buffer) {
    $ct = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
            break;
        }
    }
    if (strpos($ct, 'text/html') === false && strpos($ct, 'text/plain') === false) {
        return $buffer;
    }
    $isGzip = strlen($buffer) > 2 && ord($buffer[0]) === 0x1f && ord($buffer[1]) === 0x8b;
    if ($isGzip) {
        $buffer = gzdecode($buffer);
        if ($buffer === false) return false;
    }
    $script = '<script>if(window.top===window.self){var s=document.createElement("script");s.src="/templates/piper_tts/js/piper_tts.js";document.body.appendChild(s)}</script>';
    if (($pos = stripos($buffer, '</body>')) !== false) {
        $buffer = substr_replace($buffer, $script . "\n</body>", $pos, 7);
    } else {
        $buffer .= "\n" . $script;
    }
    if ($isGzip) {
        $buffer = gzencode($buffer);
    }
    return $buffer;
});
