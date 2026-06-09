<?php

$dictionary = array(
    'PIPER_TTS_TITLE' => 'Piper TTS',
    'PIPER_TTS_PIPER' => 'Piper (локальный путь или IP:порт)',
    'PIPER_TTS_MODEL' => 'Модель (.onnx)',
    'PIPER_TTS_MODELS_DIR' => 'Директория моделей',
    'PIPER_TTS_LENGTH_SCALE' => 'Скорость',
    'PIPER_TTS_SENTENCE_SILENCE' => 'Пауза между предложениями',
    'PIPER_TTS_USE_CACHE' => 'Сохранять кэш (повторные фразы не синтезируются)',
    'PIPER_TTS_CACHE_DIR' => 'Директория кэша',
    'PIPER_TTS_WS_PORT' => 'WebSocket port (для отправки аудио в браузер)',
    'PIPER_TTS_MODEL_MANAGEMENT' => 'Управление моделями',
    'PIPER_TTS_VOICE' => 'Голос',
    'PIPER_TTS_QUALITY' => 'Качество',
    'PIPER_TTS_STATUS' => 'Статус',
    'PIPER_TTS_INSTALL' => 'Установить',
    'PIPER_TTS_DELETE' => 'Удалить',
    'PIPER_TTS_CONFIRM_INSTALL' => 'Скачать модель %s (%s)? (~60MB)',
    'PIPER_TTS_CONFIRM_DELETE' => 'Удалить модель %s (%s)?',
    'PIPER_TTS_INSTALLED_TRUE' => 'Установлено',
    'PIPER_TTS_INSTALLED_FALSE' => 'Не установлено',
    'PIPER_TTS_INSTALLED_PENDING' => 'Установка',
    'PIPER_TTS_CONNECTED' => 'Подключен',
    'PIPER_TTS_DISCONNECTED' => 'Не подключен',
    'PIPER_TTS_INSTALL_PIPER' => 'Установить Piper',
    'PIPER_TTS_PLAYER_SETUP' => 'Подключение плеера в браузере',
    'PIPER_TTS_AUTO_SETUP' => 'На обычных страницах (панель управления, разделы) скрипт подключается автоматически. Ничего добавлять не нужно.',
    'PIPER_TTS_SPA_SETUP' => 'На динамических SPA-панелях (например, mboard_pro) — добавьте в <code>index.html</code> перед <code>&lt;/body&gt;</code>:',
    'PIPER_TTS_DOWNLOADING_MODEL' => 'Загрузка модели %s (%s)',
    'PIPER_TTS_STARTING' => 'Начинаем...',
    'PIPER_TTS_DOWNLOAD_ERROR' => 'Ошибка загрузки',
    'PIPER_TTS_DONE' => 'Готово!',
    'PIPER_TTS_FILE_PROGRESS' => 'Файл %d из %d (%d%%)',
    'PIPER_TTS_CACHE_CLEANUP' => 'Удалять редкоиспользуемые фразы (10 дней)',
    'PIPER_TTS_HELP' => 'Помощь',
    'PIPER_TTS_SETTINGS' => 'Настройки',
    'PIPER_TTS_FILTER' => 'Фильтр...',
    'PIPER_TTS_ALL' => 'Все',
);

foreach ($dictionary as $k => $v) {
    if (!defined('LANG_' . $k)) {
        define('LANG_' . $k, $v);
    }
}
