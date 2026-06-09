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
        if (!isset($this->config['PIPER_BIN'])) {
            $this->config['PIPER_BIN'] = '/usr/local/bin/piper';
        }
        if (!isset($this->config['MODELS_DIR'])) {
            $this->config['MODELS_DIR'] = '/opt/piper/voices';
        }
        if (!isset($this->config['MODEL'])) {
            $this->config['MODEL'] = '/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
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
        $voices = array('irina', 'ruslan', 'denis', 'dmitri');
        $qualities = array('medium');
        $dir = $this->config['MODELS_DIR'];
        $available = array();

        foreach ($voices as $voice) {
            foreach ($qualities as $quality) {
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
        }
        return $available;
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
            $marker = '/tmp/piper-tts-installing';
            if (is_file($marker)) {
                $status = 'installing';
            } elseif (is_file('/usr/local/bin/piper')) {
                $status = 'installed';
            } else {
                $status = 'not_installed';
            }
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(array('status' => $status));
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
            if (!is_dir($modelDir)) mkdir($modelDir, 0755, true);
            $baseUrl = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/' . $voice . '/' . $quality;
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
            if (!is_dir($modelDir)) mkdir($modelDir, 0755, true);
            $baseUrl = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/' . $voice . '/' . $quality;
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

        $out['PIPER_BIN'] = $this->config['PIPER_BIN'];
        $out['MODELS_DIR'] = $this->config['MODELS_DIR'];
        $out['MODELS'] = $this->scanModels($this->config['MODELS_DIR']);
        $out['AVAILABLE_MODELS'] = $this->getAvailableModels();
        $out['LENGTH_SCALE'] = $this->config['LENGTH_SCALE'];
        $out['SENTENCE_SILENCE'] = $this->config['SENTENCE_SILENCE'];
        $out['USE_CACHE'] = $this->config['USE_CACHE'] ? 'checked' : '';
        $out['CACHE_DIR'] = $this->config['CACHE_DIR'];
        $out['CACHE_CLEANUP'] = $this->config['CACHE_CLEANUP'] ? 'checked' : '';
        $out['WS_PORT'] = $this->config['WS_PORT'];
        $marker = '/tmp/piper-tts-installing';
        if (is_file($marker)) {
            $out['PIPER_STATUS'] = '2';
        } elseif (is_file('/usr/local/bin/piper')) {
            $out['PIPER_STATUS'] = '1';
        } else {
            $out['PIPER_STATUS'] = '0';
        }

        if ($this->view_mode == 'update_settings') {
            $this->config['PIPER_BIN'] = gr('piper_bin', $this->config['PIPER_BIN']);
            $this->config['MODELS_DIR'] = gr('models_dir', $this->config['MODELS_DIR']);
            $this->config['MODEL'] = gr('model', $this->config['MODEL']);
            $this->config['LENGTH_SCALE'] = gr('length_scale', $this->config['LENGTH_SCALE']);
            $this->config['SENTENCE_SILENCE'] = gr('sentence_silence', $this->config['SENTENCE_SILENCE']);
            $this->config['USE_CACHE'] = gr('use_cache', 0) ? 1 : 0;
            $this->config['CACHE_DIR'] = gr('cache_dir', $this->config['CACHE_DIR']);
            $this->config['CACHE_CLEANUP'] = gr('cache_cleanup', 0) ? 1 : 0;
            $this->config['WS_PORT'] = gr('ws_port', $this->config['WS_PORT']);
            $this->saveConfig();
            $this->redirect('?action=piper_tts');
        }
    }

    function usual(&$out)
    {
        $this->admin($out);
    }

    private function synthesizeToFile($message, $path)
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        $script = '/usr/local/bin/mdm-piper-tts';
        if (is_executable($script)) {
            $model = $this->config['MODEL'];
            $ls = $this->config['LENGTH_SCALE'];
            $ss = $this->config['SENTENCE_SILENCE'];
            $cmd = $script . ' ' . escapeshellarg($clean) . ' ' . escapeshellarg($path) .
                ' ' . escapeshellarg($model) .
                ' --length-scale ' . escapeshellarg($ls) .
                ' --sentence-silence ' . escapeshellarg($ss);
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

    function install($data = '')
    {
        $log = function ($msg) {
            if (function_exists('DebMes')) DebMes("piper_tts: $msg", 'piper_tts');
        };

        // --- Проверка архитектуры ---
        $arch = trim((string)shell_exec('uname -m'));
        if (!in_array($arch, ['x86_64', 'amd64', 'aarch64'])) {
            $log("install: unsupported architecture '$arch' (x86_64/amd64/aarch64 required) — module not installed");
            return;
        }
        $log("install: arch=$arch OK");

        subscribeToEvent($this->name, 'SAY', '', 110);
        subscribeToEvent($this->name, 'SAYREPLY', '', 110);

        // --- Директории (через sudo — www-data не имеет прав на /opt) ---
        exec('sudo mkdir -p /opt/piper/voices /tmp/piper-tts 2>&1', $out, $rc);
        if ($rc === 0) {
            $log("install: created dirs (rc=$rc)");
        } else {
            $log("install: mkdir failed rc=$rc: " . implode(' ', $out));
        }
        exec('sudo chmod 01777 /tmp/piper-tts 2>/dev/null');
        exec('sudo chown www-data:www-data /opt/piper/voices 2>/dev/null');
        exec('sudo chmod -R a+rX /opt/piper/voices 2>/dev/null');

        // --- Wrapper-скрипт ---
        $wrapper = '/usr/local/bin/mdm-piper-tts';
        if (!is_file($wrapper)) {
            $content = <<<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
PIPER_BIN="${PIPER_BIN:-/usr/local/bin/piper}"
MODEL="${PIPER_MODEL:-/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx}"
LENGTH_SCALE="${PIPER_LENGTH_SCALE:-1.0}"
SENTENCE_SILENCE="${PIPER_SENTENCE_SILENCE:-0.15}"
NOISE_SCALE="${PIPER_NOISE_SCALE:-0.667}"
NOISE_W="${PIPER_NOISE_W:-0.8}"
TEXT="${1:-}"; OUTPUT="${2:-}"
if [ -z "$TEXT" ] || [ "$TEXT" = "-" ]; then TEXT="$(cat)"; fi
if [ -z "$OUTPUT" ]; then echo "Usage: mdm-piper-tts <text> <output_wav> [model] [--flags...]" >&2; exit 1; fi
[ -n "$TEXT" ] || exit 1
if [ -n "${3:-}" ] && [ "${3:0:1}" != "-" ]; then MODEL="$3"; shift 3; else shift 2; fi
EXTRA=()
while [ $# -gt 0 ]; do
  case "$1" in
    --length-scale) LENGTH_SCALE="$2"; shift 2 ;;
    --sentence-silence) SENTENCE_SILENCE="$2"; shift 2 ;;
    --noise-scale) NOISE_SCALE="$2"; shift 2 ;;
    --noise-w) NOISE_W="$2"; shift 2 ;;
    *) EXTRA+=("$1"); shift ;;
  esac
done
mkdir -p "$(dirname "$OUTPUT")"
printf '%s' "$TEXT" | "$PIPER_BIN" --model "$MODEL" --length-scale "$LENGTH_SCALE" --sentence-silence "$SENTENCE_SILENCE" --noise-scale "$NOISE_SCALE" --noise-w "$NOISE_W" "${EXTRA[@]}" --output-file "$OUTPUT" 2>/dev/null
if command -v ffmpeg >/dev/null 2>&1; then
  TMP="${OUTPUT}.tmp"
  ffmpeg -y -i "$OUTPUT" -af "loudnorm=I=-16:LRA=7:TP=-1.5" "$TMP" 2>/dev/null && mv "$TMP" "$OUTPUT" || rm -f "$TMP"
fi
WRAPPER;
            if (@file_put_contents($wrapper, $content) !== false) {
                @chmod($wrapper, 0755);
                $log('install: created mdm-piper-tts');
            } else {
                @file_put_contents(ROOT . 'modules/piper_tts/mdm-piper-tts', $content);
                @chmod(ROOT . 'modules/piper_tts/mdm-piper-tts', 0755);
                $log('install: saved mdm-piper-tts to module dir (copy to /usr/local/bin/ as root)');
            }
        }

        // --- .htaccess для prepend.php ---
        $htaccess = ROOT . '.htaccess';
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            $line = 'php_value auto_prepend_file ' . ROOT . 'modules/piper_tts/prepend.php';
            if (strpos($content, 'piper_tts/prepend.php') === false) {
                file_put_contents($htaccess, $line . "\n" . $content);
                $log('install: added prepend to .htaccess');
            }
        }

        // --- Удаляем orphaned записи из БД (остались после неполного uninstall) ---
        SQLExec("DELETE FROM project_modules WHERE NAME='" . $this->name . "'");
        @unlink(ROOT . 'cms/modules_installed/' . $this->name . '.installed');
        if (file_exists(ROOT . 'cms/modules_installed/' . $this->name . '.files')) {
          @unlink(ROOT . 'cms/modules_installed/' . $this->name . '.files');
        }

        parent::install();

        // --- Установка Piper (в фоне) ---
        if (!is_file('/usr/local/bin/piper')) {
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
chown -R www-data:www-data /opt/piper/piper1-gpl
cd /opt/piper/piper1-gpl
echo "[PiperSetup] Creating venv..."
sudo -u www-data python3 -m venv .venv
echo "[PiperSetup] Installing piper (this may take a while)..."
sudo -u www-data .venv/bin/pip install --upgrade pip -q
sudo -u www-data .venv/bin/pip install .
echo "[PiperSetup] Linking piper..."
ln -sf /opt/piper/piper1-gpl/.venv/bin/piper /usr/local/bin/piper
echo "[PiperSetup] Done"
rm -f /tmp/piper-tts-installing
SETUP;
            file_put_contents($setupScript, $scriptContent);
            chmod($setupScript, 0755);
            exec('nohup sudo bash ' . escapeshellarg($setupScript) . ' < /dev/null > /dev/null 2>&1 &');
            $log("install: piper setup started in background — see $setupLog");
        } else {
            $log('install: piper OK');
        }
        if (!is_dir('/opt/piper/voices/ru_RU-irina-medium') || !is_file('/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx')) {
            $log('install: downloading irina-medium model in background');
            $dler = '/tmp/piper_dl_irina.sh';
            file_put_contents($dler, <<<DL
#!/usr/bin/env bash
exec >> /tmp/piper_dl_irina.log 2>&1
echo "[ModelDL] start \$(date)"
mkdir -p /opt/piper/voices/ru_RU-irina-medium
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx"
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx.json \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx.json"
chown -R www-data:www-data /opt/piper/voices/ru_RU-irina-medium
echo "[ModelDL] done \$(date)"
DL
            );
            chmod($dler, 0755);
            exec('nohup sudo bash ' . escapeshellarg($dler) . ' < /dev/null > /dev/null 2>&1 &');
        } else {
            $log('install: irina-medium model already present');
        }
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
