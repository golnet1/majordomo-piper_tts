<?php

class piper_tts extends module
{
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
        if (!isset($this->config['USE_CACHE'])) {
            $this->config['USE_CACHE'] = '1';
        }
        if (!isset($this->config['CACHE_DIR'])) {
            $this->config['CACHE_DIR'] = '/var/www/html/cms/cached/voice';
        }
        if (!isset($this->config['CACHE_CLEANUP'])) {
            $this->config['CACHE_CLEANUP'] = '0';
        }
        if (!isset($this->config['WS_PORT'])) {
            $this->config['WS_PORT'] = '8001';
        }
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
                'VALUE' => $name,
                'TITLE' => $name,
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
                'VALUE' => $f,
                'TITLE' => $name,
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

        if (gr('cmd') == 'install_progress') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $voice = preg_replace('/[^a-z]/', '', gr('voice'));
            $f = sys_get_temp_dir() . '/piper_progress_' . $voice;
            if (file_exists($f)) {
                echo file_get_contents($f);
            } else {
                echo '{"percent":0,"file":0,"total":2}';
            }
            exit;
        }

        if (gr('cmd') == 'check_piper_status') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $arch = trim((string)shell_exec('uname -m'));
            $arch64 = in_array($arch, ['x86_64', 'amd64', 'aarch64']);
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
            $marker = '/tmp/piper-tts-installing';
            if (is_file($marker)) {
                $install = 'installing';
            } elseif (is_file('/usr/local/bin/piper')) {
                $install = 'installed';
            } else {
                $install = 'not_installed';
            }
            echo json_encode(array('connect' => $connect, 'install' => $install, 'arch64' => $arch64));
            exit;
        }

        if (gr('cmd') == 'install_piper') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_piper called", 'piper_tts');
            $this->runPiperInstall();
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }

        if (gr('cmd') == 'install_start') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_start called, voice=" . gr('voice') . " quality=" . gr('quality'), 'piper_tts');
            header('Content-Type: application/json');
            session_write_close();
            set_time_limit(0);
            $voice = gr('voice');
            $quality = gr('quality');
            $modelDir = $this->config['MODELS_DIR'] . '/ru_RU-' . $voice . '-' . $quality;
            $pf = sys_get_temp_dir() . '/piper_progress_' . $voice;
            @unlink($pf);
            $parentDir = dirname($modelDir);
            exec('chown -R www-data:www-data ' . escapeshellarg($parentDir) . ' 2>/dev/null');
            if (!is_dir($modelDir)) mkdir($modelDir, 0755, true);
            $baseUrl = $this->getModelBaseUrl($voice, $quality);
            $files = array(
                'ru_RU-' . $voice . '-' . $quality . '.onnx',
                'ru_RU-' . $voice . '-' . $quality . '.onnx.json',
            );
            $total = count($files);
            $ok = true;
            for ($i = 0; $i < $total; $i++) {
                $url = $baseUrl . '/' . $files[$i];
                $dest = $modelDir . '/' . $files[$i];
                if (function_exists('DebMes')) DebMes("piper_tts: curl $url -> $dest", 'piper_tts');
                $fp = @fopen($dest, 'w');
                if (!$fp) {
                    if (function_exists('DebMes')) DebMes("piper_tts: fopen failed for $dest", 'piper_tts');
                    $ok = false; break;
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($r, $dlSize, $dlNow, $ulSize, $ulNow) use ($pf, $total, $i) {
                        $pct = $dlSize > 0 ? round($dlNow / $dlSize * 100) : 0;
                        $overall = round(($i * 100 + $pct) / $total);
                        @file_put_contents($pf, json_encode(array('percent' => $overall, 'file' => $i+1, 'total' => $total)));
                    },
                ));
                $res = curl_exec($ch);
                $err = curl_error($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                fclose($fp);
                if (function_exists('DebMes')) DebMes("piper_tts: curl done: ok=" . ($res !== false ? 'yes' : 'no') . " err=$err http=" . ($info ? $info['http_code'] : '?'), 'piper_tts');
                if ($res === false || !file_exists($dest) || filesize($dest) == 0) {
                    if (function_exists('DebMes')) DebMes("piper_tts: curl failed: $err", 'piper_tts');
                    $ok = false; break;
                }
            }
            exec('chown -R www-data:www-data ' . escapeshellarg($modelDir) . ' 2>/dev/null');
            if (!$ok) {
                exec('rm -rf ' . escapeshellarg($modelDir));
                @file_put_contents($pf, json_encode(array('percent' => -1, 'error' => 'download failed')));
                if (function_exists('DebMes')) DebMes("piper_tts: install_start FAILED", 'piper_tts');
            } else {
                @file_put_contents($pf, json_encode(array('percent' => 100, 'file' => $total, 'total' => $total)));
                if (function_exists('DebMes')) DebMes("piper_tts: install_start OK", 'piper_tts');
            }
            echo json_encode(array('ok' => $ok));
            exit;
        }

        if (gr('cmd') == 'install_model') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_model START", 'piper_tts');
            $voice = gr('voice');
            $quality = gr('quality');
            $modelDir = $this->config['MODELS_DIR'] . '/ru_RU-' . $voice . '-' . $quality;
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
            $voice = gr('voice');
            $quality = gr('quality');
            $modelDir = $this->config['MODELS_DIR'] . '/ru_RU-' . $voice . '-' . $quality;
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

        $isRemote = $this->isRemoteMode();
        $out['IS_REMOTE'] = $isRemote ? '1' : '';
        $out['PIPER_BIN'] = $this->config['PIPER_BIN'];

        if ($isRemote) {
            $model = $this->config['MODEL'];
            if (strpos($model, '/') !== false) {
                $base = basename($model, '.onnx');
                $this->config['MODEL'] = $base;
                $this->saveConfig();
            }
            $out['REMOTE_MODELS'] = $this->fetchRemoteVoices();
        } else {
            $out['MODELS_DIR'] = $this->config['MODELS_DIR'];
            $out['MODELS'] = $this->scanModels($this->config['MODELS_DIR']);
            $out['AVAILABLE_MODELS'] = $this->getAvailableModels();
        }

        $out['LENGTH_SCALE'] = $this->config['LENGTH_SCALE'];
        $out['SENTENCE_SILENCE'] = $this->config['SENTENCE_SILENCE'];
        $out['USE_CACHE'] = $this->config['USE_CACHE'] ? 'checked' : '';
        $out['CACHE_DIR'] = $this->config['CACHE_DIR'];
        $out['CACHE_CLEANUP'] = $this->config['CACHE_CLEANUP'] ? 'checked' : '';
        $out['WS_PORT'] = $this->config['WS_PORT'];

        $arch = trim((string)shell_exec('uname -m'));
        $arch64 = in_array($arch, ['x86_64', 'amd64', 'aarch64']);
        $out['ARCH_64'] = $arch64 ? '1' : '';

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

        $marker = '/tmp/piper-tts-installing';
        if (is_file($marker)) {
            $out['INSTALL_STATUS'] = '2';
        } elseif (is_file('/usr/local/bin/piper')) {
            $out['INSTALL_STATUS'] = '1';
        } else {
            $out['INSTALL_STATUS'] = '0';
        }

        $tab = gr('tab');
        if (!$tab) $tab = 'settings';
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_MODELS'] = ($tab == 'models') ? '1' : '0';
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
        $out['VERSION'] = '1.0.3';

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

    private function pluralize($n, $forms)
    {
        $n = abs((int)$n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        return $forms[2];
    }

    private function numberToText($n)
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

    private function expandDecimal($str)
    {
        if (!preg_match('/^(\d+)[.,](\d+)$/', $str, $m)) {
            return $str;
        }
        $whole = (int)$m[1];
        $frac = $m[2];
        $fracInt = (int)$frac;
        if ($fracInt === 0) {
            return $this->numberToText($whole);
        }
        $wholeText = $this->numberToText($whole);
        if (strlen($frac) === 1 && $fracInt === 5) {
            return $wholeText . ' с половиной';
        }
        if (strlen($frac) === 1) {
            $fracText = $this->numberToText($fracInt);
            $fracWord = $this->pluralize($fracInt, array('десятая', 'десятых', 'десятых'));
            return $wholeText . ' целых ' . $fracText . ' ' . $fracWord;
        }
        return $wholeText . ' целых ' . $this->numberToText($fracInt);
    }

    private function preprocessText($text)
    {
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*°\s*C/ui', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return $this->expandDecimal($num) . ' ' . $this->pluralize($whole, array('градус', 'градуса', 'градусов'));
            }
            return $m[1] . ' ' . $this->pluralize((int)$num, array('градус', 'градуса', 'градусов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*%/u', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return $this->expandDecimal($num) . ' ' . $this->pluralize($whole, array('процент', 'процента', 'процентов'));
            }
            return $m[1] . ' ' . $this->pluralize((int)$num, array('процент', 'процента', 'процентов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\s+рт\.?\s*ст\.?/ui', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return $this->expandDecimal($num) . ' ' . $this->pluralize($whole, array('миллиметр', 'миллиметра', 'миллиметров')) . ' ртутного столба';
            }
            return $m[1] . ' ' . $this->pluralize((int)$num, array('миллиметр', 'миллиметра', 'миллиметров')) . ' ртутного столба';
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\b/u', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return $this->expandDecimal($num) . ' ' . $this->pluralize($whole, array('миллиметр', 'миллиметра', 'миллиметров'));
            }
            return $m[1] . ' ' . $this->pluralize((int)$num, array('миллиметр', 'миллиметра', 'миллиметров'));
        }, $text);

        $text = preg_replace_callback('/\b(\d+)[.,](\d+)\b/u', function($m) {
            return $this->expandDecimal($m[1] . '.' . $m[2]);
        }, $text);

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

        return $text;
    }

    private function synthesizeToFile($message, $path)
    {
        $clean = $this->preprocessText($message);
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        if ($this->isRemoteMode()) {
            $addr = $this->getRemoteAddr();
            $url = "http://$addr/";
            $payload = json_encode(array(
                'text' => $clean,
                'voice' => $this->config['MODEL'],
                'length_scale' => (float)$this->config['LENGTH_SCALE'],
                'noise_scale' => 0.667,
                'noise_w' => 0.8,
            ));
            $cmd = 'curl -s -X POST -H "Content-Type: application/json" -d ' .
                escapeshellarg($payload) . ' -o ' . escapeshellarg($path) . ' ' .
                escapeshellarg($url);
            exec($cmd . ' 2>&1', $out, $ret);
        } else {
            $script = '/usr/local/bin/mdm-piper-tts';
            if (is_executable($script)) {
                $model = $this->config['MODEL'];
                $ls = $this->config['LENGTH_SCALE'];
                $ss = $this->config['SENTENCE_SILENCE'];
                $cmd = $script . ' --no-play --output-file ' . escapeshellarg($path) .
                    ' --model ' . escapeshellarg($model) .
                    ' --length-scale ' . escapeshellarg($ls) .
                    ' --sentence-silence ' . escapeshellarg($ss) .
                    ' -- ' . escapeshellarg($clean);
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
            }
            exec($cmd . ' 2>&1', $out, $ret);
        }

        if ($ret === 0 && file_exists($path)) {
            $tmpPath = $path . '.tmp';
            exec('ffmpeg -y -i ' . escapeshellarg($path) .
                ' -af "loudnorm=I=-16:LRA=7:TP=-1.5" ' .
                escapeshellarg($tmpPath) . ' 2>/dev/null');
            if (file_exists($tmpPath)) {
                rename($tmpPath, $path);
            }
        }
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

        $cacheDir = $this->config['CACHE_DIR'];
        $useCache = (int)$this->config['USE_CACHE'] === 1;
        $md5 = md5($message);
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

        if (file_exists($wavFile)) {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '/var/www/html';
            $webPath = str_replace($docRoot, '', $wavFile);
            $webPath = str_replace('\\', '/', $webPath);
            $url = '/' . ltrim($webPath, '/');

            $result = postToWebSocket("PIPER_TTS", array(
                'COMMAND' => 'PlayAudio',
                'URL' => $url,
            ), "PostEvent");

            if (function_exists('DebMes')) {
                DebMes("piper_tts: postToWebSocket result=" . ($result === false ? 'false' : 'ok') . " url=$url", 'piper_tts');
            }
        } else {
            if (function_exists('DebMes')) DebMes("piper_tts: wav not found after synthesis", 'piper_tts');
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

    private function runPiperInstall()
    {
        $setupScript = '/tmp/piper_install.sh';
        $setupLog = '/tmp/piper_install.log';
        $marker = '/tmp/piper-tts-installing';
        $arch = trim((string)shell_exec('uname -m'));
        file_put_contents($marker, '1');
        $scriptContent = <<<SETUP
#!/usr/bin/env bash
set -euo pipefail
exec > $setupLog 2>&1
echo "[PiperSetup] arch=$arch"
echo "[PiperSetup] Installing system deps..."
DEBIAN_FRONTEND=noninteractive apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl wget git libespeak-ng1 libstdc++6 python3-venv python3-dev
echo "[PiperSetup] Cloning piper1-gpl..."
mkdir -p /opt/piper
if [ ! -d /opt/piper/piper1-gpl ]; then
  git clone https://github.com/OHF-voice/piper1-gpl.git /opt/piper/piper1-gpl
fi
echo "[PiperSetup] Fixing ownership..."
  chown -R www-data:www-data /opt/piper
cd /opt/piper/piper1-gpl
echo "[PiperSetup] Creating venv..."
sudo -u www-data python3 -m venv .venv
echo "[PiperSetup] Installing piper (this may take a while)..."
sudo -u www-data .venv/bin/pip install --upgrade pip -q
sudo -u www-data .venv/bin/pip install .
echo "[PiperSetup] Linking piper..."
ln -sf /opt/piper/piper1-gpl/.venv/bin/piper /usr/local/bin/piper
echo "[PiperSetup] Downloading default voice irina-medium..."
mkdir -p /opt/piper/voices
mkdir -p /opt/piper/voices/ru_RU-irina-medium
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx"
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx.json \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx.json"
chown -R www-data:www-data /opt/piper/voices/ru_RU-irina-medium 2>/dev/null
  chown -R www-data:www-data /opt/piper/voices 2>/dev/null
echo "[PiperSetup] Done"
rm -f /tmp/piper-tts-installing
SETUP;
        file_put_contents($setupScript, $scriptContent);
        chmod($setupScript, 0755);
        exec('nohup sudo bash ' . escapeshellarg($setupScript) . ' < /dev/null > /dev/null 2>&1 &');
    }

    function install($data = '')
    {
        $log = function ($msg) {
            if (function_exists('DebMes')) DebMes("piper_tts: $msg", 'piper_tts');
        };

        subscribeToEvent($this->name, 'SAY', '', 110);
        subscribeToEvent($this->name, 'SAYREPLY', '', 110);

        // --- Директории ---
        exec('mkdir -p /tmp/piper-tts 2>&1', $out, $rc);
        if ($rc === 0) {
            $log("install: created tmp dir (rc=$rc)");
        } else {
            $log("install: mkdir failed rc=$rc: " . implode(' ', $out));
        }
        exec('sudo chmod 01777 /tmp/piper-tts 2>/dev/null');

        // --- .htaccess для prepend.php ---
        $prependPath = ROOT . 'modules/piper_tts/prepend.php';
        if (!file_exists($prependPath)) {
            $log("install: prepend.php not found at $prependPath, skipping .htaccess update");
        } else {
            $htaccess = ROOT . '.htaccess';
            if (file_exists($htaccess)) {
                $content = file_get_contents($htaccess);
                $line = 'php_value auto_prepend_file ' . $prependPath;
                if (strpos($content, 'piper_tts/prepend.php') === false) {
                    file_put_contents($htaccess, $line . "\n" . $content);
                    $log('install: added prepend to .htaccess');
                }
            }
        }

        // --- Удаляем orphaned записи из БД ---
        SQLExec("DELETE FROM project_modules WHERE NAME='" . $this->name . "'");
        @unlink(ROOT . 'cms/modules_installed/' . $this->name . '.installed');
        if (file_exists(ROOT . 'cms/modules_installed/' . $this->name . '.files')) {
          @unlink(ROOT . 'cms/modules_installed/' . $this->name . '.files');
        }

        parent::install();
    }

    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYREPLY');
        @unlink(ROOT . 'modules/piper_tts/prepend.php');
        $htaccess = ROOT . '.htaccess';
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            $line = 'php_value auto_prepend_file ' . ROOT . 'modules/piper_tts/prepend.php';
            $content = str_replace(array($line . "\r\n", $line . "\n", $line), '', $content);
            $content = preg_replace('/\n{3,}/', "\n\n", $content);
            file_put_contents($htaccess, $content);
        }
        parent::uninstall();
    }
}
