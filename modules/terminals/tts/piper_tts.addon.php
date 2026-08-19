<?php

class piper_tts extends tts_addon
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
        $bin = '/usr/local/bin/piper';
        $model = '/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
        $ls = '0.95';
        $ss = '0.15';
        $row = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='piper_tts'");
        if (!empty($row['DATA'])) {
            $cfg = unserialize($row['DATA']);
            if (!empty($cfg['PIPER_BIN'])) $bin = $cfg['PIPER_BIN'];
            if (!empty($cfg['MODEL'])) $model = $cfg['MODEL'];
            if (!empty($cfg['LENGTH_SCALE'])) $ls = $cfg['LENGTH_SCALE'];
            if (!empty($cfg['SENTENCE_SILENCE'])) $ss = $cfg['SENTENCE_SILENCE'];
        }
        if (!is_executable($bin)) {
            DebMes('piper_tts: piper not executable', 'terminals');
            return false;
        }
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $phrase);
        $clean = preg_replace('/\s+/', ' ', trim($clean));
        $wav = tempnam(sys_get_temp_dir(), 'piper_') . '.wav';
        $cmd = 'printf %s ' . escapeshellarg($clean) . ' | ' .
            escapeshellarg($bin) .
            ' --model ' . escapeshellarg($model) .
            ' --length-scale ' . escapeshellarg($ls) .
            ' --sentence-silence ' . escapeshellarg($ss) .
            ' --noise-scale 0.667 --noise-w 0.8' .
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

    public function say($phrase, $level = 0)
    {
        return $this->playPhraseDirect($phrase);
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
