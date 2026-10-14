<?php

require_once dirname(__FILE__) . '/lib/dictionary.php';

class piper_tts extends module
{
    // Состояние установки движка определяется по журналу попытки, а не по
    // маркеру в /tmp: маркер от незапустившегося скрипта навсегда запирал
    // статус «Установка» и прятал кнопку. Значения — как в sanoTTS.
    const INSTALL_START_TIMEOUT = 45;
    const INSTALL_STALE_TIME = 300;
    const INSTALL_MAX_TIME = 1800;
    const INSTALL_DONE_MARK = '[PiperSetup] Done';
    const INSTALL_FAIL_MARK = '[PiperSetup] FAILED';

    // Версия правил предобработки текста (cleanText/preprocessText). Входит в
    // ключ кэша озвучки: при правке правил старые WAV перестают
    // переиспользоваться. Увеличивайте при изменениях preprocessText/cleanText.
    const TEXT_VERSION = '2';

    // Кнопка «Обновить Piper» показывается только когда обновление реально
    // есть или движок не запускается. Проверка версии на PyPI кэшируется
    // надолго, проверка работоспособности — на пару минут.
    const UPDATE_CHECK_TTL = 21600;   // 6 часов
    const UPDATE_RETRY_TTL = 900;     // 15 минут — если PyPI недоступен
    const HEALTH_TTL = 120;           // 2 минуты

    /** Версия схемы таблиц: при расхождении с настройками — dbInstall(). */
    const DB_VERSION = '2';

    function piper_tts()
    {
        $this->name = 'piper_tts';
        $this->title = 'Piper TTS';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p['view_mode'] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p['edit_mode'] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p['tab'] = $this->tab;
        }
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    function getConfig()
    {
        parent::getConfig();
        if (!isset($this->config['PIPER_BIN']) || trim($this->config['PIPER_BIN']) === '') {
            $this->config['PIPER_BIN'] = '/usr/local/bin/piper';
        }
        if (!isset($this->config['MODELS_DIR']) || trim($this->config['MODELS_DIR']) === '') {
            $this->config['MODELS_DIR'] = '/opt/piper/voices';
        }
        if (!isset($this->config['MODEL'])) {
            $this->config['MODEL'] = $this->isRemoteMode()
                ? 'ru_RU-irina-medium'
                : '/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
        }
        if (!isset($this->config['LENGTH_SCALE'])) {
            $this->config['LENGTH_SCALE'] = '0.95';
        }
        if (!isset($this->config['SENTENCE_SILENCE'])) {
            $this->config['SENTENCE_SILENCE'] = '0.15';
        }
        if (!isset($this->config['LEAD_SILENCE'])) {
            $this->config['LEAD_SILENCE'] = '1.0';
        }
        if (!isset($this->config['USE_CACHE'])) {
            $this->config['USE_CACHE'] = '1';
        }
        if (!isset($this->config['CACHE_DIR'])) {
            $this->config['CACHE_DIR'] = '/var/www/html/cms/cached/voice';
        }
        if (!isset($this->config['CACHE_CLEANUP'])) {
            $this->config['CACHE_CLEANUP'] = '0';
        }
        if (!isset($this->config['WS_PORT']) || !preg_match('/^\d+$/', (string)$this->config['WS_PORT'])) {
            $this->config['WS_PORT'] = defined('WEBSOCKETS_PORT') ? (string)WEBSOCKETS_PORT : '8001';
        }
    }

    /**
     * Порт, на котором реально слушает WebSocket-сервер ядра.
     * Источник истины — константа WEBSOCKETS_PORT: сервер занимает именно её,
     * и postToWebSocket() ходит на неё же. Настройка WS_PORT модуля является
     * лишь запасным вариантом на случай, если константа не определена.
     */
    private function getWsPort()
    {
        if (defined('WEBSOCKETS_PORT')) {
            return (int)WEBSOCKETS_PORT;
        }
        $port = (int)$this->config['WS_PORT'];
        return $port > 0 ? $port : 8001;
    }

    private function isWsAlive()
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('127.0.0.1', $this->getWsPort(), $errno, $errstr, 0.5);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private function getArch()
    {
        static $arch = null;
        if ($arch === null) {
            $arch = trim((string)shell_exec('uname -m'));
        }
        return $arch;
    }

    private function isArch64()
    {
        return in_array($this->getArch(), array('x86_64', 'amd64', 'aarch64'));
    }

    private function log($msg)
    {
        if (function_exists('DebMes')) DebMes('piper_tts: ' . $msg, 'piper_tts');
    }

    /** Доступна ли функция (не отключена в disable_functions). */
    private function functionEnabled($name)
    {
        if (!function_exists($name)) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
    }

    /** Часть пути к консольному PHP: под mod_php PHP_BINARY — это Apache, не php. */
    private function phpCli()
    {
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $candidates = array(
            '/usr/bin/php', '/usr/local/bin/php',
            '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2',
            '/usr/bin/php8.1', '/usr/bin/php8.0', '/usr/bin/php7.4',
        );
        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }
        $p = trim((string)@shell_exec('command -v php 2>/dev/null'));
        return $p !== '' ? $p : null;
    }

    private function haveTool($name)
    {
        return trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null')) !== '';
    }

    /** Файл с состоянием последней попытки установки. */
    private static function installStateFile()
    {
        return sys_get_temp_dir() . '/piper_tts-install.json';
    }

    /** Команда ручной установки — показывается, если фоновая не стартовала. */
    private function manualInstallCommand()
    {
        $script = dirname(__FILE__) . '/bin/install.php';
        $php = $this->phpCli();
        if ($php === null) $php = 'php';
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = @posix_getpwuid(posix_geteuid()))
            ? $pw['name'] : 'www-data';
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && $this->haveTool('sudo')) {
            return 'sudo -u ' . $user . ' ' . $php . ' ' . $script;
        }
        return $php . ' ' . $script;
    }

    /**
     * Состояние последней попытки установки: none|installing|done|failed
     * плюс текст журнала. Считается по журналу попытки.
     */
    private function installAttempt()
    {
        $info = @json_decode((string)@file_get_contents(self::installStateFile()), true);
        if (!is_array($info) || empty($info['run']) || empty($info['t'])) return array('none', '');
        $run = (string)$info['run'];
        $age = time() - (int)$info['t'];
        $logFile = $run . '/install.log';
        $log = (string)@file_get_contents($logFile);
        $launch = trim((string)@file_get_contents($run . '/launch.log'));

        if (strpos($log, self::INSTALL_DONE_MARK) !== false) return array('done', $log);
        if (strpos($log, self::INSTALL_FAIL_MARK) !== false) return array('failed', $log);

        $manual = "\n\nЗапустите установку в консоли сервера:\n\n    " . $this->manualInstallCommand() . "\n";
        if (trim($log) === '') {
            if ($age < self::INSTALL_START_TIMEOUT && $launch === '') return array('installing', '');
            $text = $launch !== ''
                ? "Установщик не стартовал:\n" . $launch
                : 'Установщик не стартовал и ничего не вывел.';
            return array('failed', $text . $manual);
        }
        $idle = time() - (int)@filemtime($logFile);
        if ($idle > self::INSTALL_STALE_TIME || $age > self::INSTALL_MAX_TIME) {
            return array('failed', rtrim($log) . "\n\n[прервано: установщик остановился без завершения — " .
                "вероятно, его прервал веб-сервер по тайм-ауту]" . ($launch !== '' ? "\n" . $launch : '') . $manual);
        }
        return array('installing', $log);
    }

    /** Путь к исполняемому файлу piper (символическая ссылка после установки). */
    private function piperBinary()
    {
        $bin = '/usr/local/bin/piper';
        if (!is_file($bin)) {
            $found = trim((string)@shell_exec('command -v piper 2>/dev/null'));
            if ($found !== '') $bin = $found;
        }
        return $bin;
    }

    /**
     * Версия установленного пакета piper-tts.
     *
     * Читается из метаданных venv (быстро, без запуска python) — каталог
     * .../lib/pythonX.Y/site-packages/piper_tts-<версия>.dist-info.
     * Если разобрать не удалось, спрашиваем сам venv. Пустая строка —
     * версию определить не получилось.
     */
    private function piperInstalledVersion()
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = '';
        $bin = $this->piperBinary();
        if (!is_file($bin)) return $cached;

        $real = (string)@readlink($bin);
        if ($real === '' || $real[0] !== '/' || !is_file($real)) {
            // Относительная или вложенная ссылка — резолвим через систему.
            $real = trim((string)@shell_exec('readlink -f ' . escapeshellarg($bin) . ' 2>/dev/null'));
        }
        if ($real === '') $real = $bin;
        $venv = dirname(dirname($real));

        $dirs = @glob($venv . '/lib/python*/site-packages/piper_tts-*.dist-info', GLOB_ONLYDIR);
        if (is_array($dirs) && count($dirs)) {
            if (preg_match('/piper_tts-(.+?)\.dist-info$/', basename($dirs[0]), $m)) {
                $cached = $m[1];
                return $cached;
            }
        }
        $py = $venv . '/bin/python';
        if (is_file($py)) {
            $v = trim((string)@shell_exec(escapeshellarg($py) . ' -c ' .
                escapeshellarg('import importlib.metadata as m; print(m.version("piper-tts"))') . ' 2>/dev/null'));
            if (preg_match('/^\d+(\.\d+)+/', $v)) $cached = $v;
        }
        return $cached;
    }

    /**
     * Последняя версия piper-tts в PyPI. Результат кэшируется в /tmp,
     * чтобы не дёргать PyPI на каждой отрисовке страницы. Пустая строка —
     * узнать не удалось (тогда обновление не навязываем).
     */
    private function piperLatestVersion()
    {
        $file = sys_get_temp_dir() . '/piper_tts-update.json';
        $data = @json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && isset($data['t'])) {
            $ttl = !empty($data['latest']) ? self::UPDATE_CHECK_TTL : self::UPDATE_RETRY_TTL;
            if ((time() - (int)$data['t']) < $ttl) {
                return isset($data['latest']) ? (string)$data['latest'] : '';
            }
        }

        $latest = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://pypi.org/pypi/piper-tts/json');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'MajorDoMo-piper_tts',
            ));
            $body = curl_exec($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '') {
                $json = @json_decode($body, true);
                if (isset($json['info']['version'])) $latest = (string)$json['info']['version'];
            }
        }
        @file_put_contents($file, json_encode(array('t' => time(), 'latest' => $latest)));
        return $latest;
    }

    /**
     * Работоспособность движка: файл piper есть, исполняется и запускается.
     * Результат кэшируется на HEALTH_TTL секунд.
     */
    private function piperHealth()
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $file = sys_get_temp_dir() . '/piper_tts-health.json';
        $data = @json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && isset($data['t']) && (time() - (int)$data['t']) < self::HEALTH_TTL) {
            $cached = array('ok' => !empty($data['ok']), 'reason' => isset($data['reason']) ? (string)$data['reason'] : '');
            return $cached;
        }

        $bin = $this->piperBinary();
        $res = array('ok' => false, 'reason' => '');
        if (!is_file($bin)) {
            $res['reason'] = 'не найден исполняемый файл piper';
        } elseif (!is_executable($bin)) {
            $res['reason'] = 'файл piper не исполняется';
        } elseif (!$this->functionEnabled('shell_exec')) {
            // Проверить запуск невозможно — не считаем движок сломанным.
            $res['ok'] = true;
        } else {
            $out = (string)@shell_exec(escapeshellarg($bin) . ' --help 2>&1');
            if (stripos($out, 'usage: piper') === false) {
                $res['reason'] = 'движок Piper не запускается';
            } else {
                $res['ok'] = true;
            }
        }
        $cached = $res;
        @file_put_contents($file, json_encode(array(
            't' => time(),
            'ok' => $res['ok'] ? 1 : 0,
            'reason' => $res['reason'],
        )));
        return $cached;
    }

    /**
     * Сводное состояние движка: установленная/доступная версия, признак
     * обновления и работоспособность. Только для локального режима.
     */
    private function piperEngineState()
    {
        $installed = $this->piperInstalledVersion();
        $latest = $this->piperLatestVersion();
        $health = $this->piperHealth();
        return array(
            'installed' => $installed,
            'latest' => $latest,
            'update' => ($installed !== '' && $latest !== '' && version_compare($latest, $installed, '>')),
            'healthy' => $health['ok'],
            'health_reason' => $health['reason'],
        );
    }

    private function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function fatal($message)
    {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:40px">';
        echo '<p style="color:red">' . $this->e($message) . '</p>';
        echo '<script>setTimeout(function(){window.location.href="' . $this->e('?action=piper_tts') . '"},1500);</script>';
        echo '</body></html>';
        exit;
    }

    /**
     * Белый список голоса и качества.
     *
     * Значения приходят из GET и подставляются в пути, которые уходят в mkdir/chown/rm,
     * поэтому произвольная строка недопустима: escapeshellarg защищает только от
     * инъекции в команду, но не от выхода за пределы MODELS_DIR через "..".
     */
    private function sanitizeVoice($voice)
    {
        $allowed = array('irina', 'denis', 'dmitri', 'ruslan', 'luka');
        return in_array($voice, $allowed, true) ? $voice : null;
    }

    private function sanitizeQuality($quality)
    {
        $allowed = array('x_low', 'low', 'medium', 'high', 'x_high');
        return in_array($quality, $allowed, true) ? $quality : null;
    }

    /**
     * Каталог модели внутри MODELS_DIR — вторая линия защиты после белого списка.
     */
    private function resolveModelDir($voice, $quality)
    {
        $base = realpath($this->config['MODELS_DIR']);
        if ($base === false) {
            return null;
        }
        $modelDir = $base . '/ru_RU-' . $voice . '-' . $quality;
        $real = realpath($modelDir);
        if ($real === false) {
            // Каталога ещё нет — проверяем нормализованный путь префиксом.
            if (strpos($modelDir, $base . '/') !== 0) {
                return null;
            }
            return $modelDir;
        }
        return (strpos($real, $base . '/') === 0) ? $real : null;
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    private function isRemoteMode()
    {
        $val = trim($this->config['PIPER_BIN']);
        if ($val === '') return false;
        if ($val[0] === '/' || $val[0] === '.' || strpos($val, '\\') !== false) return false;
        if (!preg_match('/[.:\d]/', $val)) return false;
        return true;
    }

    private function getRemoteAddr()
    {
        $addr = $this->config['PIPER_BIN'];
        if (strpos($addr, ':') === false) {
            $addr .= ':5000';
        }
        return $addr;
    }

    private function fetchRemoteVoices()
    {
        $addr = $this->getRemoteAddr();
        $url = "http://$addr/voices";
        $json = @file_get_contents($url);
        if (!$json) return array();
        $data = json_decode($json, true);
        if (!$data) return array();
        $models = array();
        $current = $this->config['MODEL'];
        foreach ($data as $name => $info) {
            $models[] = array(
                'VALUE' => $this->e($name),
                'TITLE' => $this->e($name),
                'SELECTED' => $name === $current ? 'selected' : '',
            );
        }
        return $models;
    }

    private function scanModels($dir)
    {
        $models = array();
        if (!is_dir($dir)) return $models;
        $files = glob($dir . '/*/*.onnx');
        if (!$files) $files = glob($dir . '/*.onnx');
        sort($files);
        $current = $this->config['MODEL'];
        foreach ($files as $f) {
            $name = basename(dirname($f)) . '/' . basename($f);
            $models[] = array(
                'VALUE' => $this->e($f),
                'TITLE' => $this->e($name),
                'SELECTED' => $f === $current ? 'selected' : '',
            );
        }
        return $models;
    }

    private function getAvailableModels()
    {
        $dir = $this->config['MODELS_DIR'];
        $available = array();

        $voices = array(
            'irina'  => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'denis'  => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'dmitri' => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'ruslan' => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'luka'   => array('quality' => 'medium', 'repo' => 'luka'),
        );

        foreach ($voices as $voice => $info) {
            $quality = $info['quality'];
            $modelDir = $dir . '/ru_RU-' . $voice . '-' . $quality;
            $modelFile = $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx';
            $configFile = $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx.json';
            $installed = file_exists($modelFile) && file_exists($configFile);
            $available[] = array(
                'VOICE' => $voice,
                'QUALITY' => $quality,
                'DIR' => $modelDir,
                'INSTALLED' => $installed ? '1' : '0',
            );
        }
        return $available;
    }

    private function getModelBaseUrl($voice, $quality)
    {
        if ($voice === 'luka') {
            return 'https://huggingface.co/superkeka/piper-tts-luka/resolve/main/ru/ru_RU/luka/' . $quality;
        }
        return 'https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/' . $voice . '/' . $quality;
    }

    function admin(&$out)
    {
        if (function_exists('DebMes')) DebMes("piper_tts: admin() called, action=" . $this->action . ", view_mode=" . $this->view_mode . ", GET_cmd=" . gr('cmd') . ", GET_voice=" . gr('voice'), 'piper_tts');
        $this->getConfig();

        if (gr('cmd') == 'check_piper_status') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $arch64 = $this->isArch64();
            if ($this->isRemoteMode()) {
                $addr = $this->getRemoteAddr();
                $ch = curl_init("http://$addr/voices");
                curl_setopt_array($ch, array(
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                ));
                curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $connect = ($http >= 200 && $http < 300) ? 'connected' : 'not_connected';
            } else {
                $connect = is_file('/usr/local/bin/piper') ? 'connected' : 'not_connected';
            }
            list($attempt, ) = $this->installAttempt();
            if ($attempt == 'installing') {
                $install = 'installing';
            } elseif (is_file('/usr/local/bin/piper')) {
                $install = 'installed';
            } elseif ($attempt == 'failed') {
                $install = 'failed';
            } else {
                $install = 'not_installed';
            }
            // Кнопка «Обновить Piper» появляется только если вышла новая версия
            // движка или локальный Piper перестал запускаться.
            $update = false;
            $healthy = true;
            if ($install == 'installed' && !$this->isRemoteMode()) {
                $state = $this->piperEngineState();
                $update = $state['update'];
                $healthy = $state['healthy'];
            }
            // Синтез и канал доставки — разные вещи: доступность Piper ничего
            // не говорит о том, дойдёт ли WAV до вкладки браузера.
            echo json_encode(array(
                'connect' => $connect,
                'install' => $install,
                'update' => $update,
                'healthy' => $healthy,
                'arch64' => $arch64,
                'ws' => $this->isWsAlive() ? 'connected' : 'not_connected',
                'ws_port' => $this->getWsPort(),
            ));
            exit;
        }

        if (gr('cmd') == 'install_log') {
            // Текст журнала текущей/последней попытки установки — его читает
            // окно установки, пока идёт фоновая сборка движка.
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain; charset=utf-8');
            list($attempt, $text) = $this->installAttempt();
            echo $attempt == 'none' ? '' : $text;
            exit;
        }

        if (gr('cmd') == 'install_piper') {
            $this->log('install_piper called');
            $run = $this->runPiperInstall();
            if ($run === null) {
                // Установщик стартовал в фоне — окно прочитает журнал оттуда.
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(array('ok' => true, 'inline' => false));
                exit;
            }
            // Фон не поднялся — ставим прямо в этом запросе. Ответ отдаём
            // сразу, чтобы браузер не ждал: прогресс окно читает из журнала.
            ignore_user_abort(true);
            @set_time_limit(0);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level()) ob_end_clean();
            $early = in_array(PHP_SAPI, array('apache2handler', 'fpm-fcgi', 'litespeed'), true);
            if ($early) {
                $body = json_encode(array('ok' => true, 'inline' => true));
                header('Content-Type: application/json');
                header('Connection: close');
                header('Content-Length: ' . strlen($body));
                echo $body;
                flush();
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                header('X-Accel-Buffering: no');
                echo "[PiperSetup] installing (" . PHP_SAPI . ")\n";
                flush();
            }
            $this->runInstallInline($run);
            exit;
        }

        if (gr('cmd') == 'install_model') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_model START", 'piper_tts');
            // Вкладка «Модели» скрыта в удалённом режиме, но команда оставалась
            // доступна напрямую из адресной строки.
            if ($this->isRemoteMode()) {
                $this->fatal(LANG_PIPER_TTS_REMOTE_MODE_ERROR);
            }
            $voice = $this->sanitizeVoice(gr('voice'));
            $quality = $this->sanitizeQuality(gr('quality'));
            if ($voice === null || $quality === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $modelDir = $this->resolveModelDir($voice, $quality);
            if ($modelDir === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $parentDir = dirname($modelDir);
            exec('chown -R www-data:www-data ' . escapeshellarg($parentDir) . ' 2>/dev/null');
            if (!is_dir($modelDir)) mkdir($modelDir, 0755, true);
            $baseUrl = $this->getModelBaseUrl($voice, $quality);
            $files = array(
                'ru_RU-' . $voice . '-' . $quality . '.onnx',
                'ru_RU-' . $voice . '-' . $quality . '.onnx.json',
            );
            set_time_limit(0);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center">';
            echo '<h2>' . sprintf(LANG_PIPER_TTS_DOWNLOADING_MODEL, htmlspecialchars($voice), htmlspecialchars($quality)) . '</h2>';
            echo '<div style="width:100%;background:#e9ecef;border-radius:4px;overflow:hidden;height:30px;margin:20px 0">';
            echo '<div id="b" style="width:0%;height:30px;background:#007bff;color:#fff;line-height:30px;font-size:14px">0%</div></div>';
            echo '<p id="s">' . LANG_PIPER_TTS_STARTING . '</p>';
            flush();

            $fileSizes = array_fill(0, count($files), 0);
            foreach ($files as $i => $f) {
                $ch = curl_init($baseUrl . '/' . $f);
                curl_setopt_array($ch, array(
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$fileSizes, $i) {
                        if (stripos($header, 'content-length:') === 0) {
                            $fileSizes[$i] = (int)trim(substr($header, 15));
                        }
                        return strlen($header);
                    },
                ));
                curl_exec($ch);
                curl_close($ch);
            }
            $totalBytes = array_sum($fileSizes);
            if ($totalBytes <= 0) $totalBytes = 1;

            $ok = true;
            $cumBytes = 0;
            $lastPct = -1;
            for ($i = 0; $i < count($files); $i++) {
                $url = $baseUrl . '/' . $files[$i];
                $dest = $modelDir . '/' . $files[$i];
                $fp = @fopen($dest, 'w');
                if (!$fp) { $ok = false; break; }
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($r, $dlSize, $dlNow, $ulSize, $ulNow) use ($totalBytes, $fileSizes, $i, &$cumBytes, &$lastPct) {
                        $fileBytes = $fileSizes[$i] > 0 ? $fileSizes[$i] : $dlSize;
                        $done = $cumBytes + ($fileBytes > 0 && $dlNow <= $fileBytes ? $dlNow : 0);
                        $overall = $totalBytes > 0 ? round($done / $totalBytes * 100) : 0;
                        if ($overall != $lastPct) {
                            $lastPct = $overall;
                            $msg = sprintf(LANG_PIPER_TTS_FILE_PROGRESS, $i+1, count($fileSizes), $overall);
                            echo '<script>document.getElementById("b").style.width="' . $overall . '%";document.getElementById("b").textContent="' . $overall . '%";document.getElementById("s").textContent="' . $msg . '";</script>';
                            flush();
                        }
                    },
                ));
                $res = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                fclose($fp);
                if ($res === false || !file_exists($dest) || filesize($dest) == 0) {
                    if (function_exists('DebMes')) DebMes("piper_tts: curl failed: $err", 'piper_tts');
                    $ok = false; break;
                }
                $cumBytes += filesize($dest);
            }
            exec('chown -R www-data:www-data ' . escapeshellarg($modelDir) . ' 2>/dev/null');
            if (!$ok) {
                exec('rm -rf ' . escapeshellarg($modelDir));
                echo '<p style="color:red">' . LANG_PIPER_TTS_DOWNLOAD_ERROR . '</p>';
                if (function_exists('DebMes')) DebMes("piper_tts: install FAILED", 'piper_tts');
            } else {
                echo '<p style="color:green;font-weight:bold">' . LANG_PIPER_TTS_DONE . '</p>';
                if (function_exists('DebMes')) DebMes("piper_tts: install OK", 'piper_tts');
            }
            echo '<script>setTimeout(function(){window.location.href="' . htmlspecialchars('?action=piper_tts') . '"},1000);</script>';
            echo '</body></html>';
            exit;
        }

        if (gr('cmd') == 'delete_model') {
            if (function_exists('DebMes')) DebMes("piper_tts: delete_model START", 'piper_tts');
            if ($this->isRemoteMode()) {
                $this->fatal(LANG_PIPER_TTS_REMOTE_MODE_ERROR);
            }
            $voice = $this->sanitizeVoice(gr('voice'));
            $quality = $this->sanitizeQuality(gr('quality'));
            if ($voice === null || $quality === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $modelDir = $this->resolveModelDir($voice, $quality);
            if ($modelDir === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            if (function_exists('DebMes')) DebMes("piper_tts: deleting $modelDir", 'piper_tts');
            if (is_dir($modelDir)) {
                exec('rm -rf ' . escapeshellarg($modelDir) . ' 2>&1', $rmOut, $rmRet);
                if ($rmRet !== 0) {
                    if (function_exists('DebMes')) DebMes("piper_tts: rm failed ($rmRet): " . implode("\n", $rmOut), 'piper_tts');
                } else {
                    if (function_exists('DebMes')) DebMes("piper_tts: deleted $modelDir", 'piper_tts');
                }
            }
            if ($this->config['MODEL'] === $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx') {
                $this->config['MODEL'] = $this->config['MODELS_DIR'] . '/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
                $this->saveConfig();
                if (function_exists('DebMes')) DebMes("piper_tts: reset active model to irina", 'piper_tts');
            }
            if (function_exists('DebMes')) DebMes("piper_tts: redirect after delete", 'piper_tts');
            $this->redirect('?action=piper_tts');
            exit;
        }

        // --- Словарь произношения (вкладка «Словарь», только из админки) ---
        $cmd = gr('cmd');
        if ($this->action == 'admin' && in_array($cmd, array('rules_list', 'rule_save', 'rule_delete', 'rule_toggle', 'rule_move'), true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache');
            echo json_encode($this->rulesCommand($cmd), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($this->action == 'admin' && $cmd == 'dict_export') {
            while (ob_get_level()) ob_end_clean();
            $body = PiperDictionary::export($this->loadDictRules());
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="piper_dict_' . date('Ymd') . '.txt"');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit;
        }

        // ▶ в словаре: синтез фразы выбранным голосом (с учётом правил).
        if ($this->action == 'admin' && $cmd == 'test_say') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            $text = $this->grB64('t64');
            if (trim($text) === '') {
                echo json_encode(array('ok' => false, 'error' => 'пустой текст'));
                exit;
            }
            $this->applyTestOverrides();
            $dir = rtrim($this->config['CACHE_DIR'], '/');
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            // Имя зависит от фразы, правил и параметров синтеза: повторное
            // прослушивание переиспользует файл, а смена словаря/голоса даёт новый.
            $key = md5(implode('|', array(
                $text,
                PiperDictionary::stamp($this->activeDictRules()),
                $this->config['MODEL'],
                $this->config['LENGTH_SCALE'],
                $this->config['SENTENCE_SILENCE'],
                self::TEXT_VERSION,
            )));
            $path = $dir . '/piper_dict_' . $key . '.wav';
            if (!is_file($path) || filesize($path) == 0) {
                $this->synthesizeToFile($text, $path);
            }
            if (!is_file($path) || filesize($path) == 0) {
                echo json_encode(array('ok' => false, 'error' => 'синтез не удался'));
                exit;
            }
            // Отдаём корнево-относительный путь: BASE_URL на сервере может быть
            // настроен на 127.0.0.1, и тогда браузер пользователя его не откроет.
            $url = str_replace(rtrim(ROOT, '/'), '', $path);
            if ($url === '' || $url[0] !== '/') $url = '/' . ltrim($url, '/');
            echo json_encode(array('ok' => true, 'audio' => $url));
            exit;
        }

        // «Разбор» в словаре: какие свои правила сработали и как фраза будет прочитана.
        if ($this->action == 'admin' && $cmd == 'test_explain') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache');
            $this->applyTestOverrides();
            echo json_encode($this->explainText($this->grB64('t64')), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $isRemote = $this->isRemoteMode();
        $out['IS_REMOTE'] = $isRemote ? '1' : '';
        $out['PIPER_BIN'] = $this->e($this->config['PIPER_BIN']);

        if ($isRemote) {
            $model = $this->config['MODEL'];
            if (strpos($model, '/') !== false) {
                $base = basename($model, '.onnx');
                $this->config['MODEL'] = $base;
                $this->saveConfig();
            }
            $out['REMOTE_MODELS'] = $this->fetchRemoteVoices();
        } else {
            $out['MODELS_DIR'] = $this->e($this->config['MODELS_DIR']);
            $out['MODELS'] = $this->scanModels($this->config['MODELS_DIR']);
            $out['AVAILABLE_MODELS'] = $this->getAvailableModels();
        }

        $out['LENGTH_SCALE'] = $this->e($this->config['LENGTH_SCALE']);
        $out['SENTENCE_SILENCE'] = $this->e($this->config['SENTENCE_SILENCE']);
        $out['USE_CACHE'] = $this->config['USE_CACHE'] ? 'checked' : '';
        $out['CACHE_DIR'] = $this->e($this->config['CACHE_DIR']);
        $out['CACHE_CLEANUP'] = $this->config['CACHE_CLEANUP'] ? 'checked' : '';
        $out['WS_PORT'] = $this->e($this->getWsPort());

        $out['ARCH_64'] = $this->isArch64() ? '1' : '';
        $out['WS_ALIVE'] = $this->isWsAlive() ? '1' : '';

        if ($isRemote) {
            $addr = $this->getRemoteAddr();
            $ch = curl_init("http://$addr/voices");
            curl_setopt_array($ch, array(
                CURLOPT_TIMEOUT => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ));
            curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $out['CONNECT_STATUS'] = ($http >= 200 && $http < 300) ? '1' : '0';
        } else {
            $out['CONNECT_STATUS'] = is_file('/usr/local/bin/piper') ? '1' : '0';
        }

        list($attempt, ) = $this->installAttempt();
        $installedVersion = '';
        $latestVersion = '';
        $healthReason = '';
        $update = false;
        $healthy = true;
        if ($attempt == 'installing') {
            $out['INSTALL_STATUS'] = '2';
        } elseif (is_file('/usr/local/bin/piper')) {
            $out['INSTALL_STATUS'] = '1';
            if (!$isRemote) {
                $state = $this->piperEngineState();
                $installedVersion = $state['installed'];
                $latestVersion = $state['latest'];
                $healthReason = $state['health_reason'];
                $update = $state['update'];
                $healthy = $state['healthy'];
            }
        } elseif ($attempt == 'failed') {
            $out['INSTALL_STATUS'] = '3';
        } else {
            $out['INSTALL_STATUS'] = '0';
        }
        $out['INSTALL_UPDATE'] = !empty($update) ? '1' : '';
        $out['INSTALL_HEALTHY'] = !empty($healthy) ? '1' : '';
        $out['INSTALL_VERSION'] = $this->e($installedVersion);
        $out['INSTALL_LATEST'] = $this->e($latestVersion);
        $out['INSTALL_HEALTH_REASON'] = $this->e($healthReason);
        $out['MANUAL_INSTALL'] = $this->e($this->manualInstallCommand());

        $tab = gr('tab');
        if (!$tab) $tab = 'settings';
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_MODELS'] = ($tab == 'models') ? '1' : '0';
        $out['TAB_DICT'] = ($tab == 'dict') ? '1' : '0';
        if ($tab == 'dict') {
            // Итог импорта (после возврата назад на вкладку).
            $out['IMPORT_DONE'] = gr('imported') !== '' ? '1' : '';
            $out['IMPORT_ADDED'] = (int)gr('imported');
            $out['IMPORT_SKIPPED'] = (int)gr('skipped');
            $out['IMPORT_ERRORS'] = $this->e(base64_decode(strtr((string)gr('ierr'), '-_', '+/')) ?: '');
            $out['IMPORT_FAILED'] = $this->e(base64_decode(strtr((string)gr('ifail'), '-_', '+/')) ?: '');
        }
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
        $out['VERSION'] = '1.2.4';

        if ($this->view_mode == 'dict_import') {
            $this->redirect('?action=piper_tts&tab=dict&' . $this->importDict());
            exit;
        }

        if ($this->view_mode == 'update_settings') {
            $piperBin = gr('piper_bin', $this->config['PIPER_BIN']);
            $this->config['PIPER_BIN'] = trim($piperBin) !== '' ? $piperBin : '/usr/local/bin/piper';
            $isRemote = $this->isRemoteMode();
            if (!$isRemote) {
                $this->config['MODELS_DIR'] = gr('models_dir', $this->config['MODELS_DIR']);
                if (trim($this->config['MODELS_DIR']) === '') {
                    $this->config['MODELS_DIR'] = '/opt/piper/voices';
                }
            }
            $this->config['MODEL'] = gr('model', $this->config['MODEL']);
            $this->config['LENGTH_SCALE'] = gr('length_scale', $this->config['LENGTH_SCALE']);
            $this->config['SENTENCE_SILENCE'] = gr('sentence_silence', $this->config['SENTENCE_SILENCE']);
            $this->config['USE_CACHE'] = gr('use_cache', 0) ? 1 : 0;
            $this->config['CACHE_DIR'] = gr('cache_dir', $this->config['CACHE_DIR']);
            $this->config['CACHE_CLEANUP'] = gr('cache_cleanup', 0) ? 1 : 0;
            $this->config['WS_PORT'] = gr('ws_port', $this->config['WS_PORT']);
            $this->saveConfig();
            $this->redirect('?action=piper_tts&tab=' . $tab);
        }
    }

    function usual(&$out)
    {
        $this->admin($out);
    }

    public static function pluralize($n, $forms)
    {
        $n = abs((int)$n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        return $forms[2];
    }

    public static function numberToText($n)
    {
        $n = (int)$n;
        if ($n === 0) return 'ноль';
        $units = array('', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
        $unitsFem = array('', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
        $teens = array('десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать');
        $tens = array('', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто');
        $hundreds = array('', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот');
        $result = '';
        if ($n >= 1000) {
            $th = (int)($n / 1000);
            $result .= $unitsFem[$th] . ' тысяча ';
            $n %= 1000;
        }
        if ($n >= 100) {
            $result .= $hundreds[(int)($n / 100)] . ' ';
            $n %= 100;
        }
        if ($n >= 20) {
            $result .= $tens[(int)($n / 10)] . ' ';
            $n %= 10;
        } elseif ($n >= 10) {
            $result .= $teens[$n - 10] . ' ';
            $n = 0;
        }
        if ($n > 0) {
            $result .= $units[$n] . ' ';
        }
        return trim($result);
    }

    /**
     * Число в родительном падеже — для оборотов «от … до …».
     * «8» → «восьми», «11» → «одиннадцати», «2000» → «двух тысяч».
     */
    public static function numberToTextGenitive($n)
    {
        $n = (int)$n;
        if ($n === 0) return 'нуля';
        if ($n < 0) return 'минус ' . self::numberToTextGenitive(-$n);
        if ($n >= 1000000000) return (string)$n;
        $r = array();
        if ($n >= 1000000) {
            $m = (int)($n / 1000000);
            $r[] = self::genBelow1000($m, false) . ' ' . self::pluralize($m, array('миллиона', 'миллионов', 'миллионов'));
            $n %= 1000000;
        }
        if ($n >= 1000) {
            $th = (int)($n / 1000);
            $r[] = self::genBelow1000($th, true) . ' ' . self::pluralize($th, array('тысячи', 'тысяч', 'тысяч'));
            $n %= 1000;
        }
        if ($n > 0) {
            $r[] = self::genBelow1000($n, false);
        }
        return trim(implode(' ', $r));
    }

    /** 1..999 в родительном падеже; $fem — женский род (для «тысяча»). */
    private static function genBelow1000($n, $fem)
    {
        $units = array('', 'одного', 'двух', 'трёх', 'четырёх', 'пяти', 'шести', 'семи', 'восьми', 'девяти');
        if ($fem) $units[1] = 'одной';
        $teens = array('десяти', 'одиннадцати', 'двенадцати', 'тринадцати', 'четырнадцати', 'пятнадцати', 'шестнадцати', 'семнадцати', 'восемнадцати', 'девятнадцати');
        $tens = array('', '', 'двадцати', 'тридцати', 'сорока', 'пятидесяти', 'шестидесяти', 'семидесяти', 'восьмидесяти', 'девяноста');
        $hundreds = array('', 'ста', 'двухсот', 'трёхсот', 'четырёхсот', 'пятисот', 'шестисот', 'семисот', 'восьмисот', 'девятисот');
        $r = '';
        if ($n >= 100) { $r .= $hundreds[(int)($n / 100)] . ' '; $n %= 100; }
        if ($n >= 20) { $r .= $tens[(int)($n / 10)] . ' '; $n %= 10; }
        elseif ($n >= 10) { $r .= $teens[$n - 10] . ' '; $n = 0; }
        if ($n > 0) { $r .= $units[$n] . ' '; }
        return trim($r);
    }

    /** Число из оборота «от … до …»: «+8» → «плюс восьми», «-3» → «минус трёх». */
    private static function rangeNumber($s)
    {
        $sign = '';
        if (isset($s[0]) && ($s[0] === '+' || $s[0] === '-' || $s[0] === '−')) {
            $sign = ($s[0] === '+') ? 'плюс ' : 'минус ';
            $s = substr($s, 1);
        }
        if (strpos($s, ',') !== false || strpos($s, '.') !== false) {
            $parts = preg_split('/[.,]/', $s, 2);
            $whole = (int)$parts[0];
            $frac = isset($parts[1]) ? $parts[1] : '';
            if ($frac === '5') {
                if ($whole === 0) return $sign . 'половины';
                if ($whole === 1) return $sign . 'полутора';
                return $sign . self::numberToTextGenitive($whole) . ' с половиной';
            }
            return $sign . $s; // прочие дроби разберёт общее правило ниже
        }
        return $sign . self::numberToTextGenitive((int)$s);
    }

    public static function expandDecimal($str)
    {
        if (!preg_match('/^(\d+)[.,](\d+)$/', $str, $m)) {
            return $str;
        }
        $whole = (int)$m[1];
        $frac = $m[2];
        $fracInt = (int)$frac;
        if ($fracInt === 0) {
            return self::numberToText($whole);
        }
        $wholeText = self::numberToText($whole);
        if (strlen($frac) === 1 && $fracInt === 5) {
            return $wholeText . ' с половиной';
        }
        if (strlen($frac) === 1) {
            $fracText = self::numberToText($fracInt);
            $fracWord = self::pluralize($fracInt, array('десятая', 'десятых', 'десятых'));
            return $wholeText . ' целых ' . $fracText . ' ' . $fracWord;
        }
        return $wholeText . ' целых ' . self::numberToText($fracInt);
    }

    /**
     * Число и единица измерения в правильной форме.
     * Целые — как есть: «5 метров», «1 метр»; дробные — с родительным
     * единственного числа: «1,5 метра», «0,5 метра» → «полметра».
     * $forms: [«метр», «метра», «метров»].
     */
    public static function numberWithUnit($num, $forms)
    {
        $num = str_replace(',', '.', $num);
        if (strpos($num, '.') === false) {
            return $num . ' ' . self::pluralize((int)$num, $forms);
        }
        $dot = strpos($num, '.');
        $whole = (int)substr($num, 0, $dot);
        $frac = substr($num, $dot + 1);
        if ($frac === '5') {
            if ($whole === 0) return 'пол' . $forms[1];
            if ($whole === 1) return 'полтора ' . $forms[1];
        }
        return self::expandDecimal($num) . ' ' . $forms[1];
    }

    /**
     * Числовая нормализация, которая обязана идти ДО словаря: иначе правило
     * «м/с → метров в секунду» перехватывает «1,5 м/с» и ломает склонение
     * («полтора метра в секунду»), а «UTC → по гринвичу» отрезает знак
     * смещения («UTC+3» → «по гринвичу 3»). Замены без числа (например, просто
     * «м/с») остаются в preprocessText — ПОСЛЕ словаря, чтобы пользователь мог
     * переопределить чтение своим правилом.
     */
    private static function preprocessNumbers($text)
    {
        // «~» перед числом — «примерно»: «~86%» → «примерно 86 процентов».
        $text = preg_replace("/[\x7E\x{FF5E}\x{223C}]\s*(?=[+-]?\d)/u", 'примерно ', $text);

        // Составные единицы: число + единица в правильной форме.
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*км\s*\/\s*ч/ui', function($m) {
            return self::numberWithUnit($m[1], array('километр', 'километра', 'километров')) . ' в час';
        }, $text);
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*м\s*\/\s*с/ui', function($m) {
            return self::numberWithUnit($m[1], array('метр', 'метра', 'метров')) . ' в секунду';
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*°\s*C/ui', function($m) {
            return self::numberWithUnit($m[1], array('градус', 'градуса', 'градусов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*%/u', function($m) {
            return self::numberWithUnit($m[1], array('процент', 'процента', 'процентов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\s+рт\.?\s*ст\.?/ui', function($m) {
            return self::numberWithUnit($m[1], array('миллиметр', 'миллиметра', 'миллиметров')) . ' ртутного столба';
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\b/u', function($m) {
            return self::numberWithUnit($m[1], array('миллиметр', 'миллиметра', 'миллиметров'));
        }, $text);

        // Смещение UTC: «UTC+3» → «по москве». Именно до словаря.
        $tzPos = array(
            0 => 'гринвичу', 1 => 'центральноевропейскому', 2 => 'калининграду',
            3 => 'москве', 4 => 'самаре', 5 => 'екатеринбургу',
            6 => 'омску', 7 => 'красноярску', 8 => 'иркутску',
            9 => 'якутску', 10 => 'владивостоку', 11 => 'магадану', 12 => 'камчатке',
        );
        $tzNeg = array(
            1 => 'азорам', 2 => 'бразилии', 3 => 'аргентине',
            4 => 'нью-йорку', 5 => 'чикаго', 6 => 'денверу',
            7 => 'лос-анджелесу', 8 => 'анкориджу', 9 => 'гавайям',
            10 => 'острову пасхи',
        );
        $text = preg_replace_callback('/\bUTC([+-]\d{1,2})\b/u', function($m) use ($tzPos, $tzNeg) {
            $offset = (int)$m[1];
            if ($offset >= 0 && isset($tzPos[$offset])) return 'по ' . $tzPos[$offset];
            if ($offset < 0 && isset($tzNeg[-$offset])) return 'по ' . $tzNeg[-$offset];
            return 'UTC' . $m[1];
        }, $text);

        return $text;
    }

    public static function preprocessText($text)
    {
        // Числа и единицы — до словаря (preprocessNumbers). Повторный вызов
        // здесь безвреден: шаблоны уже не совпадают.
        $text = self::preprocessNumbers($text);

        // Без числа: «м/с» → «метров в секунду» (если словарь не задал своё).
        $text = preg_replace('/км\s*\/\s*ч/ui', 'километров в час', $text);
        $text = preg_replace('/м\s*\/\s*с/ui', 'метров в секунду', $text);

        // Знак номера: «№5» → «номер 5».
        $text = preg_replace('/\s*№\s*/u', ' номер ', $text);

        // Тире между числами — это диапазон: «5-10» → «от 5 до 10».
        $text = preg_replace('/(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)/u', 'от ${1} до ${2}', $text);

        // Тире между словами (обязательно с пробелами) — тоже диапазон.
        // Именно дефис/короткое тире: длинное «—» в русском обычно разделяет
        // части фразы, поэтому его не трогаем.
        $text = preg_replace('/(\p{L}+)\s+[-–]\s+(\p{L}+)/u', 'от ${1} до ${2}', $text);

        // «=»: между числами — «равно», иначе — «равняется». Родственные знаки.
        $text = preg_replace('/(\d+(?:[.,]\d+)?)\s*=\s*(\d+(?:[.,]\d+)?)/u', '${1} равно ${2}', $text);
        $text = preg_replace('/\s*=\s*/u', ' равняется ', $text);
        $text = str_replace(array('≠', '≤', '≥'), array(' не равно ', ' меньше или равно ', ' больше или равно '), $text);

        // «+» и «−» перед числом: «+5» → «плюс 5», «-3» → «минус 3».
        // Буква перед знаком («UTC+3», «UTC-5») не трогается — эти смещения
        // разбираются ниже отдельно.
        // Многоточие между числами — тоже диапазон: «1...3» → «от 1 до 3».
        // Знак числа учитывается, чтобы работало «+8…+11».
        $text = preg_replace('/([+-]?\d+(?:[.,]\d+)?)\s*(?:\.{3}|…)\s*([+-]?\d+(?:[.,]\d+)?)/u', 'от ${1} до ${2}', $text);

        // Числа в обороте «от … до …» — в родительном падеже (после всех диапазонов):
        // «от 8 до 11» → «от восьми до одиннадцати», «от +8 до +11» → «от плюс восьми до плюс одиннадцати».
        $text = preg_replace_callback('/(?<![\p{L}\p{N}])от\s+(?:(плюс|минус)\s+)?([+-]?\d+(?:[.,]\d+)?)\s+до\s+(?:(плюс|минус)\s+)?([+-]?\d+(?:[.,]\d+)?)(\s*°)?/u', function($m) {
            $sa = ($m[1] !== '') ? $m[1] . ' ' : '';
            $sb = ($m[3] !== '') ? $m[3] . ' ' : '';
            $out = 'от ' . $sa . self::rangeNumber($m[2]) . ' до ' . $sb . self::rangeNumber($m[4]);
            if (!empty($m[5])) {
                $v = $m[4];
                if (isset($v[0]) && ($v[0] === '+' || $v[0] === '-' || $v[0] === '−')) $v = substr($v, 1);
                $whole = (int)str_replace(',', '.', $v);
                $out .= ' ' . self::pluralize($whole, array('градус', 'градуса', 'градусов'));
            }
            return $out;
        }, $text);

        $text = preg_replace('/(?<![\p{L}])[+]\s*(?=\d)/u', ' плюс ', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])[-−]\s*(?=\d)/u', ' минус ', $text);

        // «±» разбираем после минуса, чтобы «плюс-минус» не стал диапазоном.
        $text = str_replace('±', ' плюс-минус ', $text);

        // Одиночный «°» без буквы C: «+7°» → «+7 градусов».
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*°/u', function($m) {
            return self::numberWithUnit($m[1], array('градус', 'градуса', 'градусов'));
        }, $text);

        // «C»/«С» сразу после числа (без знака градуса): «23C» → «23 градуса».
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*[CС](?![\p{L}\p{N}])/u', function($m) {
            return self::numberWithUnit($m[1], array('градус', 'градуса', 'градусов'));
        }, $text);

        $text = preg_replace_callback('/\b(\d+)[.,](\d+)\b/u', function($m) {
            return self::expandDecimal($m[1] . '.' . $m[2]);
        }, $text);

        $text = preg_replace('/\bUTC(?![-+])/u', 'по гринвичу', $text);
        $text = preg_replace('/\bGMT\b/u', 'по гринвичу', $text);
        $text = preg_replace('/\bMSK\b/u', 'по москве', $text);
        $text = preg_replace('/\bEDT\b/u', 'по нью-йорку', $text);
        $text = preg_replace('/\bEST\b/u', 'по нью-йорку', $text);
        $text = preg_replace('/\bPDT\b/u', 'по лос-анджелесу', $text);
        $text = preg_replace('/\bPST\b/u', 'по лос-анджелесу', $text);
        $text = preg_replace('/\bCDT\b/u', 'по чикаго', $text);
        $text = preg_replace('/\bCST\b/u', 'по чикаго', $text);
        $text = preg_replace('/\bMDT\b/u', 'по денверу', $text);
        $text = preg_replace('/\bMST\b/u', 'по денверу', $text);
        $text = preg_replace('/\bAKDT\b/u', 'по анкориджу', $text);
        $text = preg_replace('/\bAKST\b/u', 'по анкориджу', $text);
        $text = preg_replace('/\bHADT\b/u', 'по гавайям', $text);
        $text = preg_replace('/\bHAST\b/u', 'по гавайям', $text);
        $text = preg_replace('/\bCET\b/u', 'по центральноевропейскому', $text);
        $text = preg_replace('/\bCEST\b/u', 'по центральноевропейскому', $text);
        $text = preg_replace('/\bEET\b/u', 'по восточноевропейскому', $text);
        $text = preg_replace('/\bEEST\b/u', 'по восточноевропейскому', $text);
        $text = preg_replace('/\bBST\b/u', 'по лондону', $text);
        $text = preg_replace('/\bIST\b/u', 'по индии', $text);
        $text = preg_replace('/\bJST\b/u', 'по токио', $text);
        $text = preg_replace('/\bKST\b/u', 'по сеулу', $text);
        $text = preg_replace('/\bAWST\b/u', 'по перту', $text);
        $text = preg_replace('/\bACST\b/u', 'по дарвину', $text);
        $text = preg_replace('/\bAEST\b/u', 'по сиднею', $text);

        // Убираем задвоенные пробелы, появившиеся при подстановке слов вместо знаков.
        $text = trim(preg_replace('/[ \t]{2,}/u', ' ', $text));

        return $text;
    }

    /**
     * Очистка текста перед синтезом.
     *
     * Piper строит просодию по пунктуации, поэтому знаки препинания сохраняются:
     * прежняя фильтрация [^\p{L}\p{N}\s] выбрасывала всё, и речь становилась
     * монотонной — без пауз между предложениями и без вопросительной интонации.
     * Удаляются только символы, которые несут для движка никакой информации
     * (кавычки, скобки, служебные знаки, управляющие коды).
     */
    public static function cleanText($message)
    {
        return self::stripText(self::preprocessText($message));
    }

    /**
     * Финальная очистка текста: переводы строк, служебные символы, пробелы.
     * Вынесена из cleanText отдельно, чтобы словарь применялся ПОСЛЕ встроенной
     * нормализации чисел и единиц (см. synthesizeToFile): иначе правило
     * «м/с → метров в секунду» перехватывает «1,5 м/с» и ломает склонение.
     */
    private static function stripText($text)
    {
        $clean = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string)$text);
        $clean = preg_replace('/[^\p{L}\p{N}\s.,!?;:\x{2013}\x{2014}\x{2019}\x{201C}\x{201D}\x{2026}()-]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);
        return trim($clean);
    }

    /**
     * Разбивает текст на предложения по знакам конца.
     * Используется только в удалённом режиме, где сервер не умеет sentence_silence.
     */
    private function splitSentences($text)
    {
        $parts = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return array($text);
        }
        $parts = array_values(array_filter(array_map('trim', $parts), function($p) {
            return $p !== '';
        }));
        return count($parts) > 0 ? $parts : array($text);
    }

    private function requestRemoteSynthesis($text, $path)
    {
        $addr = $this->getRemoteAddr();
        // Сервер piper1-gpl принимает length_w_scale, а не noise_w: имя noise_w
        // он молча игнорирует, и ширина фонем оставалась на значении по умолчанию.
        $payload = json_encode(array(
            'text' => $text,
            'voice' => $this->config['MODEL'],
            'length_scale' => (float)$this->config['LENGTH_SCALE'],
            'noise_scale' => 0.667,
            'length_w_scale' => 0.8,
        ));
        CreateDir(dirname($path));
        $fp = @fopen($path, 'w');
        if ($fp === false) {
            if (function_exists('DebMes')) DebMes("piper_tts: cannot open $path for writing", 'piper_tts');
            return false;
        }
        $ch = curl_init("http://$addr/");
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FILE => $fp,
        ));
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($res === false && function_exists('DebMes')) {
            DebMes("piper_tts: remote synthesis failed: $err", 'piper_tts');
        }
        return $res !== false && file_exists($path) && filesize($path) > 0;
    }

    /**
     * Удалённый сервер не поддерживает паузу между предложениями (это аргумент
     * его запуска, а не поле JSON-запроса), поэтому пауза собирается локально:
     * предложения синтезируются по отдельности и склеиваются тишиной нужной
     * длины. Наивная отправка sentence_silence в payload не давала ничего.
     */
    private function synthesizeRemoteWithSilence($text, $path)
    {
        $silence = (float)$this->config['SENTENCE_SILENCE'];
        $sentences = $this->splitSentences($text);

        if ($silence <= 0 || count($sentences) < 2) {
            return $this->requestRemoteSynthesis($text, $path);
        }

        $workDir = $path . '.parts';
        CreateDir($workDir);
        $parts = array();
        $ok = true;
        foreach ($sentences as $i => $sentence) {
            $partFile = $workDir . '/part' . $i . '.wav';
            if (!$this->requestRemoteSynthesis($sentence, $partFile)) {
                $ok = false;
                break;
            }
            $parts[] = $partFile;
        }

        if (!$ok || count($parts) < 2) {
            $this->removeDir($workDir);
            return $this->requestRemoteSynthesis($text, $path);
        }

        $gap = $workDir . '/gap.wav';
        $samples = (int)round(22050 * $silence);
        exec('ffmpeg -y -f lavfi -i anullsrc=r=22050:cl=mono -t ' .
            escapeshellarg((string)$silence) . ' -c:a pcm_s16le ' . escapeshellarg($gap) . ' 2>/dev/null');
        if (!file_exists($gap)) {
            $this->removeDir($workDir);
            return $this->requestRemoteSynthesis($text, $path);
        }

        $listFile = $workDir . '/concat.txt';
        $lines = '';
        foreach ($parts as $partFile) {
            $lines .= "file '" . str_replace("'", "'\\''", $partFile) . "'\n";
        }
        // Пауза добавляется между предложениями, но не после последнего.
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $lines .= "file '" . str_replace("'", "'\\''", $gap) . "'\n";
        }
        file_put_contents($listFile, $lines);

        exec('ffmpeg -y -f concat -safe 0 -i ' . escapeshellarg($listFile) .
            ' -c copy ' . escapeshellarg($path) . ' 2>/dev/null');
        $this->removeDir($workDir);

        if (file_exists($path) && filesize($path) > 0) {
            return true;
        }
        return $this->requestRemoteSynthesis($text, $path);
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Пауза перед началом фразы. Без неё первые миллисекунды WAV теряются на
     * стороне проигрывателя: файл стартует, но начало уже проигнорировано.
     * Настраивается строкой LEAD_SILENCE в таблице settings, 0 отключает.
     */
    private function getLeadSilence()
    {
        if (isset($this->config['LEAD_SILENCE']) && trim((string)$this->config['LEAD_SILENCE']) !== '') {
            return max(0.0, (float)$this->config['LEAD_SILENCE']);
        }
        return 1.0;
    }

    // ------------------------------------------------------------------
    // Словарь произношения (таблица piper_dict, lib/dictionary.php)
    // ------------------------------------------------------------------

    function dbInstall($data = '')
    {
        $data = <<<EOD
 piper_dict: ID int(10) unsigned NOT NULL auto_increment
 piper_dict: LANG varchar(16) NOT NULL DEFAULT 'ru'
 piper_dict: MATCH_TYPE varchar(10) NOT NULL DEFAULT 'word'
 piper_dict: PATTERN varchar(255) NOT NULL DEFAULT ''
 piper_dict: REPLACEMENT varchar(255) NOT NULL DEFAULT ''
 piper_dict: CASE_SENSITIVE int(3) NOT NULL DEFAULT 0
 piper_dict: ACTIVE int(3) NOT NULL DEFAULT 1
 piper_dict: PRIORITY int(10) NOT NULL DEFAULT 0
 piper_dict: NOTE varchar(255) NOT NULL DEFAULT ''
 piper_dict: UPDATED datetime
 piper_dict: INDEX (LANG)
EOD;
        parent::dbInstall($data);
    }

    /**
     * Таблица словаря: модуль мог обновиться без повторной установки — тогда
     * создаём/дополняем её здесь, один раз за запрос. Проверяем и фактически:
     * DB_VERSION мог быть записан старой версией, где таблица называлась иначе
     * (piper_tts_rules), из-за чего правила «пропадали».
     */
    private function ensureDictTable()
    {
        static $done = false;
        if ($done) return true;
        if (!function_exists('SQLSelect')) return false;
        $need = !isset($this->config['DB_VERSION']) || $this->config['DB_VERSION'] !== self::DB_VERSION
            || !$this->dictTableExists('piper_dict');
        if ($need) {
            try {
                $this->dbInstall('');
            } catch (Throwable $e) {
                $this->log('dbInstall: ' . $e->getMessage());
                return false;
            }
            try {
                $this->migrateLegacyDictRules();
            } catch (Throwable $e) {
                $this->log('dict migrate: ' . $e->getMessage());
            }
            try {
                $this->seedDefaultDictRules();
            } catch (Throwable $e) {
                $this->log('dict seed: ' . $e->getMessage());
            }
            $this->config['DB_VERSION'] = self::DB_VERSION;
            if (method_exists($this, 'saveConfig')) $this->saveConfig();
        }
        $done = true;
        return true;
    }

    /** Есть ли таблица. SHOW TABLES не ошибается на отсутствующей, в отличие от SELECT. */
    private function dictTableExists($name)
    {
        try {
            $r = SQLSelect("SHOW TABLES LIKE '" . DBSafe($name) . "'");
        } catch (Throwable $e) {
            return false;
        }
        return !empty($r);
    }

    /**
     * Правила из старой таблицы piper_tts_rules (до переименования в piper_dict,
     * ещё без LANG) переносим, если piper_dict пуста. Старую таблицу не трогаем.
     */
    private function migrateLegacyDictRules()
    {
        if (!$this->dictTableExists('piper_tts_rules') || !$this->dictTableExists('piper_dict')) return;
        try {
            $c = SQLSelectOne('SELECT COUNT(*) AS CNT FROM piper_dict');
        } catch (Throwable $e) {
            return;
        }
        if (!empty($c['CNT'])) return;
        try {
            $rows = SQLSelect('SELECT * FROM piper_tts_rules ORDER BY PRIORITY, ID');
        } catch (Throwable $e) {
            return;
        }
        if (!is_array($rows) || !$rows) return;
        $n = 0;
        foreach ($rows as $r) {
            $rec = array(
                'LANG' => 'ru',
                'MATCH_TYPE' => isset($r['MATCH_TYPE']) ? $r['MATCH_TYPE'] : 'word',
                'PATTERN' => isset($r['PATTERN']) ? $r['PATTERN'] : '',
                'REPLACEMENT' => isset($r['REPLACEMENT']) ? $r['REPLACEMENT'] : '',
                'CASE_SENSITIVE' => (int)(isset($r['CASE_SENSITIVE']) ? $r['CASE_SENSITIVE'] : 0),
                'ACTIVE' => (int)(isset($r['ACTIVE']) ? $r['ACTIVE'] : 1),
                'PRIORITY' => (int)(isset($r['PRIORITY']) ? $r['PRIORITY'] : 0),
                'NOTE' => isset($r['NOTE']) ? $r['NOTE'] : '',
                'UPDATED' => date('Y-m-d H:i:s'),
            );
            SQLInsert('piper_dict', $rec);
            $n++;
        }
        if ($n) $this->log('dict: перенесено из piper_tts_rules: ' . $n);
    }

    /**
     * Примеры правил словаря, создаваемые при первой установке таблицы (только
     * если она пуста). Строка: PATTERN, REPLACEMENT, MATCH_TYPE, CASE_SENSITIVE,
     * ACTIVE, LANG, NOTE — как в экспорте словаря.
     */
    private static function defaultDictRules()
    {
        return array(
            array('км/ч', 'километров в час', 'text', 0, 1, 'ru', ''),
            array('м/с', 'метров в секунду', 'text', 0, 1, 'ru', ''),
            array('UTC', 'по гринвичу', 'word', 0, 1, 'ru', ''),
            array('GMT', 'по гринвичу', 'word', 0, 1, 'ru', ''),
            array('MSK', 'по москве', 'word', 0, 1, 'ru', ''),
            array('EDT', 'по нью-йорку', 'word', 0, 1, 'ru', ''),
            array('EST', 'по нью-йорку', 'word', 0, 1, 'ru', ''),
            array('PDT', 'по лос-анджелесу', 'word', 0, 1, 'ru', ''),
            array('PST', 'по лос-анджелесу', 'word', 0, 1, 'ru', ''),
            array('CDT', 'по чикаго', 'word', 0, 1, 'ru', ''),
            array('CST', 'по чикаго', 'word', 0, 1, 'ru', ''),
            array('MDT', 'по денверу', 'word', 0, 1, 'ru', ''),
            array('MST', 'по денверу', 'word', 0, 1, 'ru', ''),
            array('AKDT', 'по анкориджу', 'word', 0, 1, 'ru', ''),
            array('AKST', 'по анкориджу', 'word', 0, 1, 'ru', ''),
            array('HADT', 'по гавайям', 'word', 0, 1, 'ru', ''),
            array('HAST', 'по гавайям', 'word', 0, 1, 'ru', ''),
            array('CET', 'по центральноевропейскому', 'word', 0, 1, 'ru', ''),
            array('CEST', 'по центральноевропейскому', 'word', 0, 1, 'ru', ''),
            array('EET', 'по восточноевропейскому', 'word', 0, 1, 'ru', ''),
            array('EEST', 'по восточноевропейскому', 'word', 0, 1, 'ru', ''),
            array('BST', 'по лондону', 'word', 0, 1, 'ru', ''),
            array('IST', 'по индии', 'word', 0, 1, 'ru', ''),
            array('JST', 'по токио', 'word', 0, 1, 'ru', ''),
            array('KST', 'по сеулу', 'word', 0, 1, 'ru', ''),
            array('AWST', 'по перту', 'word', 0, 1, 'ru', ''),
            array('ACST', 'по дарвину', 'word', 0, 1, 'ru', ''),
            array('AEST', 'по сиднею', 'word', 0, 1, 'ru', ''),
            array('*', '', 'text', 0, 1, 'ru', ''),
            array('#', '', 'text', 0, 1, 'ru', ''),
            array('@', '', 'text', 0, 1, 'ru', ''),
            array('$', '', 'text', 0, 1, 'ru', ''),
            array('^', '', 'text', 0, 1, 'ru', ''),
            array('&', '', 'text', 0, 1, 'ru', ''),
            array('\\', '', 'text', 0, 1, 'ru', ''),
            array('/', '', 'text', 0, 1, 'ru', ''),
            array('|', '', 'text', 0, 1, 'ru', ''),
            array('_', '', 'text', 0, 1, 'ru', ''),
            array('"', '', 'text', 0, 1, 'ru', ''),
            array('«', '', 'text', 0, 1, 'ru', ''),
            array('»', '', 'text', 0, 1, 'ru', ''),
            array('<', '', 'text', 0, 1, 'ru', ''),
            array('>', '', 'text', 0, 1, 'ru', ''),
            array('[', '', 'text', 0, 1, 'ru', ''),
            array(']', '', 'text', 0, 1, 'ru', ''),
            array('{', '', 'text', 0, 1, 'ru', ''),
            array('}', '', 'text', 0, 1, 'ru', ''),
            array('МСК', 'по москве', 'word', 0, 1, 'ru', ''),
        );
    }

    /**
     * Заполняет пустую таблицу словаря примерами (первая установка). Существующие
     * правила не трогает: при непустой таблице сразу выходит.
     */
    private function seedDefaultDictRules()
    {
        if (!$this->dictTableExists('piper_dict')) return;
        try {
            $c = SQLSelectOne('SELECT COUNT(*) AS CNT FROM piper_dict');
        } catch (Throwable $e) {
            return;
        }
        if (!empty($c['CNT'])) return;
        $now = date('Y-m-d H:i:s');
        $prio = 0;
        $n = 0;
        foreach (self::defaultDictRules() as $r) {
            $rec = array(
                'LANG' => $r[5],
                'MATCH_TYPE' => $r[2],
                'PATTERN' => $r[0],
                'REPLACEMENT' => $r[1],
                'CASE_SENSITIVE' => (int)$r[3],
                'ACTIVE' => (int)$r[4],
                'PRIORITY' => $prio++,
                'NOTE' => $r[6],
                'UPDATED' => $now,
            );
            try {
                SQLInsert('piper_dict', $rec);
                $n++;
            } catch (Throwable $e) {
                $this->log('dict seed: ' . $e->getMessage());
            }
        }
        if ($n) $this->log('dict: добавлено примеров правил: ' . $n);
    }


    /** Языки, для которых есть вкладка словаря. */
    public static function dictRulesLanguages()
    {
        return array('ru');
    }

    /** Язык текущего голоса — префикс имени модели (ru_RU-irina-medium → ru). */
    private function dictLang()
    {
        $b = basename((string)$this->config['MODEL'], '.onnx');
        if (preg_match('/^([a-z]{2,8})[_-]/i', $b, $m)) {
            return strtolower($m[1]);
        }
        return 'ru';
    }

    /** Все правила (для вкладки) или только включённые для языка $lang — по порядку применения. */
    private function loadDictRules($lang = null, $activeOnly = false)
    {
        if (!$this->ensureDictTable()) return array();
        $w = array();
        if ($lang !== null) $w[] = "(LANG='" . DBSafe($lang) . "' OR LANG='')";
        if ($activeOnly) $w[] = 'ACTIVE=1';
        try {
            $rows = SQLSelect('SELECT * FROM piper_dict' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY PRIORITY, ID');
        } catch (Throwable $e) {
            $this->log('dict: ' . $e->getMessage());
            return array();
        }
        return is_array($rows) ? $rows : array();
    }

    /** Включённые правила для языка текущего голоса (на время запроса — из памяти). */
    private $dictCache = null;

    private function activeDictRules()
    {
        if ($this->dictCache === null) {
            $lang = $this->dictLang();
            if (!in_array($lang, self::dictRulesLanguages(), true)) {
                $this->dictCache = array();
            } else {
                $this->dictCache = $this->loadDictRules($lang, true);
            }
        }
        return $this->dictCache;
    }

    /** Правила словаря — перед очисткой и синтезом: ими можно перекрыть чтение. */
    private function applyDictionaryRules($text, &$hits = null)
    {
        $rules = $this->activeDictRules();
        $hits = array();
        return $rules ? PiperDictionary::apply($text, $rules, $hits) : $text;
    }

    /**
     * Значения со страницы настроек для кнопок «Проверить»/«Разбор»: голос и
     * параметры синтеза могут быть ещё не сохранены, но тест должен их учесть.
     */
    private function applyTestOverrides()
    {
        if (($v = gr('model')) !== '') $this->config['MODEL'] = $v;
        if (($v = gr('length_scale')) !== '') $this->config['LENGTH_SCALE'] = $v;
        if (($v = gr('sentence_silence')) !== '') $this->config['SENTENCE_SILENCE'] = $v;
    }

    /**
     * «Разбор» фразы для вкладки «Словарь»: какие свои правила сработали и как
     * фраза будет прочитана — с учётом очистки и автозамен чисел и единиц.
     * Возвращает структуру для JSON (piperExplainRender в dictionary.html).
     */
    private function explainText($text)
    {
        $hits = array();
        // Числа и единицы — до словаря (как в synthesizeToFile): иначе правила
        // «м/с → метров в секунду» и «UTC → по гринвичу» перехватывают числа и
        // знак смещения («UTC+3») и ломают их правильное чтение. Остальные
        // правила (диапазоны, знаки, часовые пояса без смещения) — после словаря.
        $base = self::preprocessNumbers($text);
        $after = $this->applyDictionaryRules($base, $hits);
        $byId = array();
        foreach ($this->activeDictRules() as $r) $byId[(int)$r['ID']] = $r;
        $fired = array();
        foreach ($hits as $id => $n) {
            if (!isset($byId[$id])) continue;
            $fired[] = array(
                'id' => (int)$id,
                'pattern' => (string)$byId[$id]['PATTERN'],
                'replacement' => (string)$byId[$id]['REPLACEMENT'],
                'type' => (string)$byId[$id]['MATCH_TYPE'],
                'count' => (int)$n,
            );
        }
        $clean = self::cleanText($after);
        $chunks = array();
        if ($clean !== '') {
            foreach ($this->splitSentences($clean) as $c) {
                $chunks[] = array('text' => $c);
            }
        }
        return array(
            'ok' => true,
            'original' => (string)$text,
            'base' => $base,
            'user' => $after,
            'rules' => $fired,
            'chunks' => $chunks,
        );
    }

    private function dictRuleRow($r)
    {
        return array(
            'id' => (int)$r['ID'],
            'lang' => (string)$r['LANG'],
            'type' => (string)$r['MATCH_TYPE'],
            'pattern' => (string)$r['PATTERN'],
            'replacement' => (string)$r['REPLACEMENT'],
            'cs' => (int)$r['CASE_SENSITIVE'] === 1,
            'active' => (int)$r['ACTIVE'] === 1,
            'note' => (string)$r['NOTE'],
            'valid' => PiperDictionary::validate($r['MATCH_TYPE'], $r['PATTERN'], $r['REPLACEMENT']) === null,
        );
    }

    /** Разбор текста из запроса в base64url (адресная строка ломает UTF-8). */
    private function grB64($name)
    {
        $v = strtr((string)gr($name, ''), '-_', '+/');
        $pad = strlen($v) % 4;
        if ($pad) $v .= str_repeat('=', 4 - $pad);
        $d = base64_decode($v, true);
        return $d === false ? '' : $d;
    }

    /** Поле формы в base64url → UTF-8 текст; не base64 — пустая строка. */
    private static function grText($name)
    {
        $v = (string)gr($name);
        if ($v === '') return '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $v)) return '';
        $d = base64_decode(strtr($v, '-_', '+/'), true);
        return ($d !== false && preg_match('//u', $d)) ? $d : '';
    }

    /** Команды JSON вкладки «Словарь» (AJAX из шаблона dictionary.html). */
    private function rulesCommand($cmd)
    {
        if (!$this->ensureDictTable()) return array('ok' => false, 'error' => 'нет таблицы piper_dict');
        $id = (int)gr('id');
        $rec = $id > 0 ? SQLSelectOne('SELECT * FROM piper_dict WHERE ID=' . $id) : array();
        if ($id > 0 && !$rec && $cmd != 'rules_list' && $cmd != 'rule_save') return array('ok' => false, 'error' => 'правило не найдено');
        switch ($cmd) {
            case 'rule_save':
                $type = (string)gr('type');
                if (!in_array($type, PiperDictionary::types(), true)) $type = 'word';
                list($pattern, $replacement) = PiperDictionary::normalize($type, self::grText('p'), self::grText('r'));
                $err = PiperDictionary::validate($type, $pattern, $replacement);
                if ($err !== null) return array('ok' => false, 'error' => $err);
                $lang = (string)gr('lang');
                if (!in_array($lang, self::dictRulesLanguages(), true)) $lang = 'ru';
                $dup = SQLSelectOne("SELECT ID FROM piper_dict WHERE LANG='" . DBSafe($lang) . "' AND MATCH_TYPE='" . DBSafe($type) .
                    "' AND BINARY PATTERN='" . DBSafe($pattern) . "' AND ID<>" . $id);
                if ($dup) return array('ok' => false, 'error' => 'такое правило уже есть');
                $r = $rec ? $rec : array();
                $r['LANG'] = $lang;
                $r['MATCH_TYPE'] = $type;
                $r['PATTERN'] = $pattern;
                $r['REPLACEMENT'] = $replacement;
                $r['CASE_SENSITIVE'] = gr('cs') ? 1 : 0;
                $r['NOTE'] = PiperDictionary::clean(self::grText('n'));
                $r['UPDATED'] = date('Y-m-d H:i:s');
                if ($rec) {
                    SQLUpdate('piper_dict', $r);
                } else {
                    $max = SQLSelectOne('SELECT MAX(PRIORITY) AS M FROM piper_dict');
                    $r['PRIORITY'] = (isset($max['M']) ? (int)$max['M'] : 0) + 10;
                    $r['ACTIVE'] = 1;
                    $r['ID'] = SQLInsert('piper_dict', $r);
                }
                break;
            case 'rule_delete':
                SQLExec('DELETE FROM piper_dict WHERE ID=' . $id);
                break;
            case 'rule_toggle':
                $rec['ACTIVE'] = gr('active') ? 1 : 0;
                SQLUpdate('piper_dict', $rec);
                break;
            case 'rule_move':
                // Порядок применения: меняемся местами с соседом сверху/снизу.
                $all = $this->loadDictRules();
                foreach ($all as $k => $r) {
                    $all[$k]['PRIORITY'] = ($k + 1) * 10;
                }
                foreach ($all as $k => $r) {
                    if ((int)$r['ID'] !== $id) continue;
                    $j = gr('dir') === 'up' ? $k - 1 : $k + 1;
                    if (isset($all[$j])) {
                        $t = $all[$k]['PRIORITY'];
                        $all[$k]['PRIORITY'] = $all[$j]['PRIORITY'];
                        $all[$j]['PRIORITY'] = $t;
                    }
                    break;
                }
                foreach ($all as $r) SQLExec('UPDATE piper_dict SET PRIORITY=' . (int)$r['PRIORITY'] . ' WHERE ID=' . (int)$r['ID']);
                break;
        }
        $rules = array();
        foreach ($this->loadDictRules() as $r) $rules[] = $this->dictRuleRow($r);
        return array('ok' => true, 'rules' => $rules);
    }

    /** Импорт файла словаря (форма вкладки «Словарь»): строка параметров для возврата. */
    private function importDict()
    {
        $b64 = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $f = isset($_FILES['dict_file']) ? $_FILES['dict_file'] : null;
        if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) {
            return 'imported=0&ifail=' . $b64('файл не получен');
        }
        if ($f['size'] > 2 * 1048576) return 'imported=0&ifail=' . $b64('файл больше 2 МБ');
        if (!$this->ensureDictTable()) return 'imported=0&ifail=' . $b64('нет таблицы piper_dict');
        list($rules, $errors) = PiperDictionary::parse((string)file_get_contents($f['tmp_name']), $this->dictLang());
        if (gr('import_mode') === 'replace') {
            if (!$rules) return 'imported=0&ifail=' . $b64('в файле нет правил — замена отменена');
            SQLExec('DELETE FROM piper_dict');
        }
        $have = array();
        foreach ($this->loadDictRules() as $r) $have[$r['LANG'] . "\x1F" . $r['MATCH_TYPE'] . "\x1F" . $r['PATTERN']] = true;
        $max = SQLSelectOne('SELECT MAX(PRIORITY) AS M FROM piper_dict');
        $prio = isset($max['M']) ? (int)$max['M'] : 0;
        $added = 0;
        $skipped = 0;
        foreach ($rules as $r) {
            $k = $r['LANG'] . "\x1F" . $r['MATCH_TYPE'] . "\x1F" . $r['PATTERN'];
            if (isset($have[$k])) { $skipped++; continue; }
            $have[$k] = true;
            $prio += 10;
            $r['PRIORITY'] = $prio;
            $r['UPDATED'] = date('Y-m-d H:i:s');
            SQLInsert('piper_dict', $r);
            $added++;
        }
        $this->log("dict import: added $added, skipped $skipped, errors " . count($errors));
        return 'imported=' . $added . '&skipped=' . $skipped . ($errors ? '&ierr=' . $b64(implode("\n", array_slice($errors, 0, 20))) : '');
    }

    private function synthesizeToFile($message, $path)
    {
        // Порядок: числовая нормализация → словарь → остальная нормализация
        // и очистка. Числа с единицами и смещения UTC нормализуются ДО словаря:
        // иначе правило «м/с → метров в секунду» перехватывает «1,5 м/с» и
        // ломает склонение («полтора метра в секунду»), а «UTC → по гринвичу»
        // отрезает знак смещения. Само правило «м/с» остаётся в словаре и
        // работает для «м/с» без числа.
        $message = self::preprocessNumbers($message);
        $message = $this->applyDictionaryRules($message);
        $clean = self::cleanText($message);
        if ($clean === '') {
            return;
        }

        if ($this->isRemoteMode()) {
            $ok = $this->synthesizeRemoteWithSilence($clean, $path);
        } else {
            $bin = $this->config['PIPER_BIN'];
            $model = $this->config['MODEL'];
            $ls = $this->config['LENGTH_SCALE'];
            $ss = $this->config['SENTENCE_SILENCE'];
            $cmd = 'printf %s ' . escapeshellarg($clean) . ' | ' .
                escapeshellarg($bin) .
                ' --model ' . escapeshellarg($model) .
                ' --length-scale ' . escapeshellarg($ls) .
                ' --sentence-silence ' . escapeshellarg($ss) .
                ' --noise-scale 0.667 --noise-w 0.8' .
                ' --output-file ' . escapeshellarg($path);
            exec($cmd . ' 2>&1', $out, $ret);
            $ok = ($ret === 0 && file_exists($path));
        }

        if (!$ok) {
            if (function_exists('DebMes')) DebMes("piper_tts: synthesis failed for: $path", 'piper_tts');
            return;
        }

        // Расширение .tmp ffmpeg не распознаёт: «Error initializing the muxer».
        // Из-за этого шаг молча пропускался, в кэш попадал сырой выход Piper
        // с пиком 0.0 dB — клиппинг срезал атаку первого слога, и начало фразы
        // звучало как съеденное. Формат задаём явно, поломку больше не глотаем.
        $tmpPath = $path . '.tmp.wav';
        $filters = array('loudnorm=I=-16:LRA=7:TP=-1.5');
        $lead = $this->getLeadSilence();
        if ($lead > 0) {
            $filters[] = 'adelay=' . (int)round($lead * 1000);
        }
        // loudnorm считает внутри на 192 кГц и без -ar протаскивает эту частоту
        // в результат: файл вырастает в десять раз. Возвращаем частоту исходника,
        // потому что у голосов разных классов она разная (16 и 22.05 кГц).
        $srcRate = 22050;
        $probe = @shell_exec('ffprobe -v error -select_streams a:0 -show_entries stream=sample_rate' .
            ' -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        if (is_string($probe) && (int)trim($probe) > 0) {
            $srcRate = (int)trim($probe);
        }
        exec('ffmpeg -y -i ' . escapeshellarg($path) .
            ' -af ' . escapeshellarg(implode(',', $filters)) .
            ' -ar ' . $srcRate . ' -f wav -c:a pcm_s16le ' .
            escapeshellarg($tmpPath) . ' 2>&1', $ffOut, $ffRet);
        if ($ffRet === 0 && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            rename($tmpPath, $path);
        } else {
            @unlink($tmpPath);
            if (function_exists('DebMes')) {
                DebMes("piper_tts: post-processing failed for: $path (ffmpeg rc=$ffRet) " .
                    trim(implode(' ', $ffOut)), 'piper_tts');
            }
        }
    }

    private function cacheKeyPart($value)
    {
        $value = (string)$value;
        return strlen($value) . ':' . $value;
    }

    private function getCacheKey($message)
    {
        // Ключ обязан зависеть от всех параметров синтеза: иначе после смены
        // голоса или скорости на повторе той же фразы отдаётся WAV,
        // синтезированный со старыми настройками.
        // Каждое поле с префиксом длины — конкатенация однозначна для любых
        // строк, поэтому склейка разных полей не даёт коллизий.
        return $this->cacheKeyPart($message)
            . $this->cacheKeyPart($this->config['MODEL'])
            . $this->cacheKeyPart($this->config['LENGTH_SCALE'])
            . $this->cacheKeyPart($this->config['SENTENCE_SILENCE'])
            . $this->cacheKeyPart($this->getLeadSilence())
            . $this->cacheKeyPart('dict=' . PiperDictionary::stamp($this->activeDictRules()))
            . $this->cacheKeyPart('text=' . self::TEXT_VERSION);
    }

    function processSubscription($event, &$details)
    {
        $this->getConfig();

        $message = '';
        if (isset($details['MESSAGE'])) {
            $message = $details['MESSAGE'];
        } elseif (isset($details['message'])) {
            $message = $details['message'];
        }

        if (empty($message)) {
            return;
        }

        $destination = '';
        if (isset($details['destination'])) {
            $destination = $details['destination'];
        }

        $cacheDir = $this->config['CACHE_DIR'];
        $useCache = (int)$this->config['USE_CACHE'] === 1;
        $md5 = md5($this->getCacheKey($message));
        $wavFile = $cacheDir . '/piper_tts_' . $md5 . '.wav';

        CreateDir($cacheDir);

        if (!$useCache || !file_exists($wavFile)) {
            $this->synthesizeToFile($message, $wavFile);
        } elseif ((int)$this->config['CACHE_CLEANUP'] === 1) {
            @touch($wavFile);
        }

        if ((int)$this->config['CACHE_CLEANUP'] === 1) {
            $this->cleanupCache();
        }

        if (!file_exists($wavFile)) {
            if (function_exists('DebMes')) DebMes("piper_tts: wav not found after synthesis", 'piper_tts');
            return;
        }

        // CACHE_DIR — это путь в файловой системе, а DOCUMENT_ROOT в CLI-контексте
        // существует, но пуст, из-за чего в браузер уходил абсолютный путь ФС.
        // Опорой служит ROOT ядра (он равен DOC_ROOT в конфигурации MajorDomo).
        $root = defined('ROOT') ? rtrim(ROOT, '/') : '';
        if ($root !== '' && strpos($wavFile, $root . '/') === 0) {
            $webPath = substr($wavFile, strlen($root));
        } else {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
                ? rtrim($_SERVER['DOCUMENT_ROOT'], '/')
                : '/var/www/html';
            $webPath = str_replace($docRoot, '', $wavFile);
        }
        $webPath = str_replace('\\', '/', $webPath);
        $url = '/' . ltrim($webPath, '/');

        if ($event == 'SAYTO' && $destination !== '') {
            $level = isset($details['level']) ? $details['level'] : (isset($details['IMPORTANCE']) ? $details['IMPORTANCE'] : 0);
            processSubscriptionsSafe('SAY_CACHED_READY', array(
                'level'    => $level,
                'filename' => $wavFile,
                'event'    => 'SAYTO',
                'destination' => $destination,
                'message'  => $message,
            ));
            if (function_exists('DebMes')) {
                DebMes("piper_tts: SAY_CACHED_READY sent dest=$destination url=$url", 'piper_tts');
            }
            return;
        }

        $result = postToWebSocket("PIPER_TTS", array(
            'COMMAND' => 'PlayAudio',
            'URL' => $url,
        ), "PostEvent");

        if (function_exists('DebMes')) {
            DebMes("piper_tts: postToWebSocket result=" . ($result === false ? 'false' : 'ok') .
                " ws_port=" . $this->getWsPort() . " url=$url", 'piper_tts');
        }
    }

    private function cleanupCache()
    {
        $dir = $this->config['CACHE_DIR'];
        if (!is_dir($dir)) return;
        $maxAge = 864000; // 10 days
        $now = time();
        foreach (glob($dir . '/piper_tts_*.wav') as $f) {
            if ($now - filemtime($f) > $maxAge) {
                @unlink($f);
            }
        }
    }

    /**
     * Запуск установки движка. Фон — как в sanoTTS: каждая попытка получает
     * свой каталог с job.json, журналом и launch.log, а состояние читается из
     * этих файлов. Возвращает null, если установщик стартовал в фоне, или путь
     * к каталогу попытки — тогда установку нужно выполнить прямо в запросе.
     */
    private function runPiperInstall()
    {
        list($attempt, ) = $this->installAttempt();
        if ($attempt == 'installing') return null;

        // Сбрасываем кэш проверки движка, чтобы после установки/обновления
        // версия и работоспособность перепроверились заново.
        @unlink(sys_get_temp_dir() . '/piper_tts-health.json');
        @unlink(sys_get_temp_dir() . '/piper_tts-update.json');

        // Файлы с фиксированными именами в /tmp могли остаться от прошлых
        // запусков с владельцем root, и запись из-под www-data тогда молча
        // не удавалась. Свой каталог на попытку это исключает.
        $run = sys_get_temp_dir() . '/piper_tts_install_' . date('Ymd_His') . '_' . getmypid();
        if (!@mkdir($run, 0777)) {
            $this->log("cannot create $run");
            return null;
        }
        @chmod($run, 0777);

        $base = '/opt/piper';
        $modelsDir = trim((string)$this->config['MODELS_DIR']);
        if ($modelsDir === '') $modelsDir = $base . '/voices';
        $voice = 'irina-medium';
        $log = $run . '/install.log';
        $job = array('base' => $base, 'models_dir' => $modelsDir, 'voice' => $voice, 'log' => $log);
        file_put_contents($run . '/job.json', json_encode($job));
        file_put_contents(self::installStateFile(), json_encode(array('t' => time(), 'run' => $run, 'base' => $base)));

        $php = $this->phpCli();
        $script = dirname(__FILE__) . '/bin/install.php';
        if ($php !== null && $this->functionEnabled('exec')) {
            @unlink($log);
            exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job=' . escapeshellarg($run . '/job.json') .
                ' < /dev/null > ' . escapeshellarg($run . '/launch.log') . ' 2>&1 &');
            // Убеждаемся, что процесс стартовал; нет — ставим прямо здесь.
            for ($i = 0; $i < 30; $i++) {
                clearstatcache();
                if (@filesize($log) > 0) {
                    $this->log("install started in background: $run (base=$base, php=$php)");
                    return null;
                }
                usleep(100000);
            }
            $this->log("background install did not start (" . trim((string)@file_get_contents($run . '/launch.log')) . "), running inline");
            @file_put_contents($run . '/launch.log', '');
        }
        $this->log("install inline: $run (base=$base)");
        return $run;
    }

    /** Установка внутри текущего запроса: установщик пишет в свой журнал. */
    private function runInstallInline($run)
    {
        $php = $this->phpCli();
        $script = dirname(__FILE__) . '/bin/install.php';
        if ($php === null || !$this->functionEnabled('exec')) {
            $this->log('cannot run inline install (no php cli or exec disabled)');
            file_put_contents($run . '/install.log', "\n[PiperSetup] FAILED: нет консольного PHP или exec отключён\n", FILE_APPEND);
            return false;
        }
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job=' . escapeshellarg($run . '/job.json') .
            ' < /dev/null > ' . escapeshellarg($run . '/launch.log') . ' 2>&1', $out, $rc);
        return $rc === 0;
    }

    function install($data = '')
    {
        $log = function ($msg) {
            if (function_exists('DebMes')) DebMes("piper_tts: $msg", 'piper_tts');
        };

        subscribeToEvent($this->name, 'SAY', '', 110);
        subscribeToEvent($this->name, 'SAYTO', '', 110);
        // SAYREPLY не нужен: sayReply() сам вызывает sayTo() или say(), а затем
        // шлёт SAYREPLY. Обе подписки обрабатывали одну и ту же фразу, и
        // браузерный плеер произносил ответ дважды. Снимаем и у прежних версий.
        unsubscribeFromEvent($this->name, 'SAYREPLY');

        // --- Общий modules/prepend.php (загрузчик) ---
        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        if (!file_exists($loaderPath)) {
            // На PHP 8.1+ mysqli по умолчанию работает в режиме MYSQLI_REPORT_STRICT
            // и бросает mysqli_sql_exception, поэтому проверка "if (!$link)" больше
            // не срабатывает: без try/catch падение БД превращается в фатальную
            // ошибку на каждом HTTP-запросе.
            $loaderContent = <<<'PHP'
<?php
if (PHP_SAPI === 'cli') return;

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) return;
include_once $configFile;

$link = null;
try {
    $link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (!$link) return;

    $result = mysqli_query($link, "SELECT NAME FROM project_modules WHERE HIDDEN=0");
    if (!$result) return;

    while ($row = mysqli_fetch_assoc($result)) {
        $prepend = __DIR__ . '/' . $row['NAME'] . '/prepend.php';
        if (file_exists($prepend)) {
            include_once $prepend;
        }
    }
} catch (Throwable $e) {
    return;
} finally {
    if ($link instanceof mysqli) {
        @mysqli_close($link);
    }
}
PHP;
            file_put_contents($loaderPath, $loaderContent);
            $log('install: created modules/prepend.php');
        }

        // --- .htaccess ---
        if (file_exists($htaccessPath)) {
            $htContent = file_get_contents($htaccessPath);

            // Удалить старую прямую строку Piper, если осталась
            $oldPiper = 'php_value auto_prepend_file ' . ROOT . 'modules/piper_tts/prepend.php';
            $htContent = str_replace(array($oldPiper . "\r\n", $oldPiper . "\n", $oldPiper), '', $htContent);

            // Добавить строку с общим загрузчиком, если её нет
            $line = 'php_value auto_prepend_file ' . $loaderPath;
            if (strpos($htContent, 'modules/prepend.php') === false) {
                $htContent = $line . "\n" . $htContent;
                $log('install: added auto_prepend_file to .htaccess');
            }

            file_put_contents($htaccessPath, $htContent);
        }

        parent::install();
    }

    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'SAYREPLY');

        if (function_exists('SQLDropTable')) @SQLDropTable('piper_dict');

        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        // Проверить, установлен ли Vosk (тоже использует общий загрузчик)
        $voskStillInstalled = false;
        try {
            $res = SQLSelect("SELECT ID FROM project_modules WHERE NAME='vosk' AND HIDDEN=0");
            if (is_array($res) && count($res) > 0) {
                $voskStillInstalled = true;
            }
        } catch (Throwable $e) {
            // При недоступной БД считаем, что Vosk ещё стоит, и ничего не удаляем:
            // это безопаснее, чем снести загрузчик, который может быть нужен.
            $voskStillInstalled = true;
        }

        if (!$voskStillInstalled) {
            if (file_exists($htaccessPath)) {
                $htContent = file_get_contents($htaccessPath);
                $line = 'php_value auto_prepend_file ' . $loaderPath;
                $htContent = str_replace(array($line . "\r\n", $line . "\n", $line), '', $htContent);
                $htContent = preg_replace('/\n{3,}/', "\n\n", $htContent);
                file_put_contents($htaccessPath, $htContent);
            }
            if (file_exists($loaderPath)) {
                @unlink($loaderPath);
            }
        }

        parent::uninstall();
    }
}
