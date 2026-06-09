<?php

class pipertts extends tts_addon
{
    function __construct($terminal)
    {
        $this->title = 'Piper (локально)';
        parent::__construct($terminal);
    }

    private function haveCmd($cmd)
    {
        $out = '';
        safe_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', 1, $out);
        return trim($out) !== '';
    }

    private function playPhraseDirect($phrase)
    {
        $script = '/usr/local/bin/piper';
        if (!is_executable($script)) {
            DebMes('pipertts: piper not executable', 'terminals');
            return false;
        }
        $wav = tempnam(sys_get_temp_dir(), 'piper_') . '.wav';
        $cmd = 'printf %s ' . escapeshellarg($phrase) . ' | ' . escapeshellarg($script) .
            ' --model /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx' .
            ' --output-file ' . escapeshellarg($wav) . ' 2>/dev/null';
        safe_exec($cmd, 1, $out);
        if (!file_exists($wav)) return false;
        if ($this->haveCmd('paplay')) {
            safe_exec('paplay ' . escapeshellarg($wav), 1, $out);
        } elseif ($this->haveCmd('aplay')) {
            safe_exec('aplay -q ' . escapeshellarg($wav), 1, $out);
        }
        unlink($wav);
        return true;
    }

    /**
     * Озвучка через SAY_CACHED_READY (модуль Piper). Иначе — двойной звук.
     */
    public function say($phrase, $level = 0)
    {
        return true;
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        if ($cached_file === '' || !file_exists($cached_file)) {
            return $this->playPhraseDirect($phrase);
        }
        if (preg_match('/\.wav$/i', $cached_file)) {
            if ($this->haveCmd('paplay')) {
                safe_exec('paplay ' . escapeshellarg($cached_file), 1, $out);
                return true;
            }
            if ($this->haveCmd('aplay')) {
                safe_exec('aplay -q ' . escapeshellarg($cached_file), 1, $out);
                return true;
            }
        }
        if ($this->haveCmd('cvlc') && preg_match('/\.mp3$/i', $cached_file)) {
            safe_exec('cvlc --play-and-exit --quiet ' . escapeshellarg($cached_file), 1, $out);
            return true;
        }
        return $this->playPhraseDirect($phrase);
    }
}
