<?php

$dictionary = array(
    'PIPER_TTS_TITLE' => 'Piper TTS',
    'PIPER_TTS_PIPER' => 'Piper (local path or IP:port)',
    'PIPER_TTS_MODEL' => 'Model (.onnx)',
    'PIPER_TTS_MODELS_DIR' => 'Models directory',
    'PIPER_TTS_LENGTH_SCALE' => 'Speed',
    'PIPER_TTS_SENTENCE_SILENCE' => 'Sentence silence',
    'PIPER_TTS_USE_CACHE' => 'Save cache (repeated phrases are not re-synthesized)',
    'PIPER_TTS_CACHE_DIR' => 'Cache directory',
    'PIPER_TTS_WS_PORT' => 'WebSocket port (for sending audio to browser)',
    'PIPER_TTS_MODEL_MANAGEMENT' => 'Model Management',
    'PIPER_TTS_VOICE' => 'Voice',
    'PIPER_TTS_QUALITY' => 'Quality',
    'PIPER_TTS_STATUS' => 'Status',
    'PIPER_TTS_INSTALL' => 'Install',
    'PIPER_TTS_DELETE' => 'Delete',
    'PIPER_TTS_CONFIRM_INSTALL' => 'Download model %s (%s)? (~60MB)',
    'PIPER_TTS_CONFIRM_DELETE' => 'Delete model %s (%s)?',
    'PIPER_TTS_INSTALLED_TRUE' => 'Installed',
    'PIPER_TTS_INSTALLED_FALSE' => 'Not installed',
    'PIPER_TTS_INSTALLED_PENDING' => 'Installing',
    'PIPER_TTS_CONNECTED' => 'Connected',
    'PIPER_TTS_DISCONNECTED' => 'Not connected',
    'PIPER_TTS_INSTALL_PIPER' => 'Install Piper',
    'PIPER_TTS_PLAYER_SETUP' => 'Browser player setup',
    'PIPER_TTS_AUTO_SETUP' => 'On standard pages (control panel, sections) the script connects automatically. No manual action needed.',
    'PIPER_TTS_SPA_SETUP' => 'On dynamic SPA panels (e.g., mboard_pro) — add to <code>index.html</code> before <code>&lt;/body&gt;</code>:',
    'PIPER_TTS_DOWNLOADING_MODEL' => 'Downloading model %s (%s)',
    'PIPER_TTS_STARTING' => 'Starting...',
    'PIPER_TTS_DOWNLOAD_ERROR' => 'Download error',
    'PIPER_TTS_DONE' => 'Done!',
    'PIPER_TTS_FILE_PROGRESS' => 'File %d of %d (%d%%)',
    'PIPER_TTS_CACHE_CLEANUP' => 'Delete rarely used phrases (10 days)',
    'PIPER_TTS_HELP' => 'Help',
    'PIPER_TTS_SETTINGS' => 'Settings',
    'PIPER_TTS_FILTER' => 'Filter...',
    'PIPER_TTS_ALL' => 'All',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
