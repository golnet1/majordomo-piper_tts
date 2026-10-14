<?php

/**
 * Аддон TTS для terminals.
 *
 * Имя класса обязано совпадать с TTS_TYPE терминала, а terminals берёт TTS_TYPE
 * из имени файла аддона (modules/terminals/terminals_edit.inc.php) и грузит его
 * как "new <TTS_TYPE>". Поэтому аддон не может называться piper_tts: модуль
 * уже объявляет class piper_tts, и в одном процессе обработки SAY оба класса
 * были бы загружены вместе — PHP падает с "Cannot redeclare class piper_tts".
 */
class piper_tts_addon extends tts_addon
{
    private $cfg = null;

    private function loadConfig()
    {
        if ($this->cfg !== null) {
            return $this->cfg;
        }
        $this->cfg = array(
            'PIPER_BIN'        => '/usr/local/bin/piper',
            'MODEL'            => 'ru_RU-irina-medium',
            'LENGTH_SCALE'     => '0.95',
            'SENTENCE_SILENCE' => '0.15',
        );
        $row = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='piper_tts'");
        if (!empty($row['DATA'])) {
            $cfg = @unserialize($row['DATA']);
            if (is_array($cfg)) {
                foreach ($this->cfg as $k => $v) {
                    if (!empty($cfg[$k])) {
                        $this->cfg[$k] = $cfg[$k];
                    }
                }
            }
        }
        return $this->cfg;
    }

    private function isRemoteMode($bin)
    {
        $bin = trim($bin);
        if ($bin === '') return false;
        if ($bin[0] === '/' || $bin[0] === '.' || strpos($bin, '\\') !== false) return false;
        return (bool)preg_match('/[.:\d]/', $bin);
    }

    private function haveCmd($cmd)
    {
        static $cache = array();
        if (isset($cache[$cmd])) return $cache[$cmd];
        $out = '';
        safe_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', 1, $out);
        $cache[$cmd] = trim($out) !== '';
        return $cache[$cmd];
    }

    /**
     * Нормализация текста (числа, единицы, часовые пояса) и очистка пунктуации
     * берутся из основного модуля, чтобы терминалы и браузер звучали одинаково.
     */
    private function prepare($phrase)
    {
        $file = DIR_MODULES . 'piper_tts/piper_tts.class.php';
        if (file_exists($file)) {
            include_once $file;
            if (class_exists('piper_tts', false)) {
                $text = piper_tts::cleanText($phrase);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return trim(preg_replace('/\s+/u', ' ', (string)$phrase));
    }

    private function synthesizeLocal($cfg, $text, $wav)
    {
        $cmd = 'printf %s ' . escapeshellarg($text) . ' | ' .
            escapeshellarg($cfg['PIPER_BIN']) .
            ' --model ' . escapeshellarg($cfg['MODEL']) .
            ' --length-scale ' . escapeshellarg($cfg['LENGTH_SCALE']) .
            ' --sentence-silence ' . escapeshellarg($cfg['SENTENCE_SILENCE']) .
            ' --noise-scale 0.667 --noise-w 0.8' .
            ' --output-file ' . escapeshellarg($wav) . ' 2>/dev/null';
        safe_exec($cmd, 1, $out);
        return file_exists($wav) && filesize($wav) > 0;
    }

    private function synthesizeRemote($cfg, $text, $wav)
    {
        $addr = $cfg['PIPER_BIN'];
        if (strpos($addr, ':') === false) {
            $addr .= ':5000';
        }
        $payload = json_encode(array(
            'text' => $text,
            'voice' => $cfg['MODEL'],
            'length_scale' => (float)$cfg['LENGTH_SCALE'],
            'noise_scale' => 0.667,
            'length_w_scale' => 0.8,
        ));
        $fp = fopen($wav, 'w');
        if (!$fp) return false;
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
        curl_close($ch);
        fclose($fp);
        return $res !== false && file_exists($wav) && filesize($wav) > 0;
    }

    private function playFile($file)
    {
        if ($this->haveCmd('paplay')) {
            safe_exec('paplay ' . escapeshellarg($file), 1, $out);
            return true;
        }
        if ($this->haveCmd('aplay')) {
            safe_exec('aplay -q ' . escapeshellarg($file), 1, $out);
            return true;
        }
        if ($this->haveCmd('ffplay')) {
            safe_exec('ffplay -nodisp -autoexit -loglevel quiet ' . escapeshellarg($file), 1, $out);
            return true;
        }
        return false;
    }

    private function playPhraseDirect($phrase)
    {
        $cfg = $this->loadConfig();
        $text = $this->prepare($phrase);
        if ($text === '') {
            return false;
        }

        $remote = $this->isRemoteMode($cfg['PIPER_BIN']);
        if (!$remote && !is_executable($cfg['PIPER_BIN'])) {
            DebMes('piper_tts: piper not executable', 'terminals');
            return false;
        }

        $base = tempnam(sys_get_temp_dir(), 'piper_');
        $wav = $base . '.wav';
        // tempnam уже создал файл; синтезатору нужен путь без .wav,
        // поэтому временный файл убираем сразу.
        @unlink($base);

        $ok = $remote
            ? $this->synthesizeRemote($cfg, $text, $wav)
            : $this->synthesizeLocal($cfg, $text, $wav);

        if (!$ok) {
            if (file_exists($wav)) @unlink($wav);
            DebMes('piper_tts: synthesis failed', 'terminals');
            return false;
        }

        $played = $this->playFile($wav);
        @unlink($wav);
        return $played;
    }

    /**
     * Параллельные события SAY приходят почти одновременно, и фразы начинают
     * звучать внахлёст. Блокировка сериализует воспроизведение: следующая фраза
     * стартует только после завершения предыдущей.
     */
    private function lockPlayback()
    {
        $fp = @fopen(sys_get_temp_dir() . '/piper_tts_play.lock', 'c');
        if ($fp) {
            @flock($fp, LOCK_EX);
        }
        return $fp;
    }

    private function unlockPlayback($fp)
    {
        if ($fp) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    public function say($phrase, $level = 0)
    {
        $lock = $this->lockPlayback();
        $result = $this->playPhraseDirect($phrase);
        $this->unlockPlayback($lock);
        return $result;
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        $lock = $this->lockPlayback();
        $result = $this->playCachedDirect($phrase, $cached_file);
        $this->unlockPlayback($lock);
        return $result;
    }

    private function playCachedDirect($phrase, $cached_file)
    {
        if ($cached_file !== '' && file_exists($cached_file) && $this->playFile($cached_file)) {
            return true;
        }
        return $this->playPhraseDirect($phrase);
    }
}
