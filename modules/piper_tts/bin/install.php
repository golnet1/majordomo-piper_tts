<?php
/**
 * Установщик движка Piper для MajorDoMo.
 *
 * Запускается кнопкой «Установить Piper» в фоне (--job=<файл>) и вручную:
 *
 *     sudo -u www-data php modules/piper_tts/bin/install.php
 *
 * Движок ставится из колеса piper-tts с PyPI в отдельное окружение
 * (venv) — без git clone и без сборки: для Linux aarch64/x86_64 есть
 * готовые manylinux-колёса, поэтому установка не зависит от стабильности
 * github.com и занимает пару минут вместо десяти.
 *
 * Журнал ведётся построчно; последняя строка — «[PiperSetup] Done» или
 * «[PiperSetup] FAILED: причина». По ней модуль определяет результат.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$opt = getopt('', array('job:', 'base:', 'models-dir:', 'voice:', 'help'));
if (isset($opt['help'])) {
    echo "php install.php [--job=FILE] [--base=DIR] [--models-dir=DIR] [--voice=NAME]\n";
    echo "  --job       файл задания (JSON), создаётся модулем при фоновом запуске\n";
    echo "  --base      каталог движка, по умолчанию /opt/piper\n";
    echo "  --models-dir каталог голосов, по умолчанию /opt/piper/voices\n";
    echo "  --voice      голос по умолчанию, по умолчанию irina-medium\n";
    exit(0);
}

$base = isset($opt['base']) ? rtrim($opt['base'], '/') : '/opt/piper';
$modelsDir = isset($opt['models-dir']) ? rtrim($opt['models-dir'], '/') : $base . '/voices';
$voice = isset($opt['voice']) ? $opt['voice'] : 'irina-medium';
$logFile = null;
$job = array();

if (isset($opt['job'])) {
    $job = @json_decode((string)@file_get_contents($opt['job']), true);
    if (!is_array($job)) {
        fwrite(STDERR, "bad job file: {$opt['job']}\n");
        exit(2);
    }
    if (!empty($job['base'])) $base = rtrim($job['base'], '/');
    if (!empty($job['models_dir'])) $modelsDir = rtrim($job['models_dir'], '/');
    if (!empty($job['voice'])) $voice = $job['voice'];
    if (!empty($job['log'])) $logFile = $job['log'];
}
if ($logFile === null) {
    $logFile = sys_get_temp_dir() . '/piper_tts_install_' . date('Ymd_His') . '.log';
}

$LOG = @fopen($logFile, 'a');
if ($LOG === false) {
    fwrite(STDERR, "cannot open log: $logFile\n");
    exit(2);
}

function piperSay($msg)
{
    global $LOG;
    $line = '[PiperSetup] ' . $msg . "\n";
    @fwrite($LOG, $line);
    @fflush($LOG);
    echo $line;
}

/**
 * Запуск команды с трансляцией вывода (stdout+stderr) в журнал.
 * Возвращает код возврата.
 */
function piperRun($cmd)
{
    global $LOG;
    @fwrite($LOG, "\$ " . $cmd . "\n");
    @fflush($LOG);

    // stderr сливаем в stdout внутри команды и читаем один поток — так вывод
    // не теряется и не возникает взаимной блокировки на двух трубах.
    $descriptors = array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('pipe', 'w'),
    );
    $proc = @proc_open($cmd . ' 2>&1', $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        piperSay('не удалось запустить команду');
        return 127;
    }
    stream_set_blocking($pipes[1], false);
    while (!feof($pipes[1])) {
        $chunk = @fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') {
            @fwrite($LOG, $chunk);
            @fflush($LOG);
        } else {
            usleep(50000);
        }
    }
    @fclose($pipes[1]);
    $rc = @proc_close($proc);
    return $rc < 0 ? 0 : $rc;
}

function piperNeedTool($tool)
{
    $path = @trim((string)@shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null'));
    return $path;
}

/** Загрузка файла с повторами и докачкой (-C -). */
function piperDownload($url, $dest)
{
    @mkdir(dirname($dest), 0777, true);
    $tmp = $dest . '.part';
    $rc = piperRun('curl -fSL --retry 3 --retry-delay 2 --connect-timeout 20 -C - -o ' .
        escapeshellarg($tmp) . ' ' . escapeshellarg($url));
    if ($rc !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return false;
    }
    @rename($tmp, $dest);
    return true;
}

$isWindows = (DIRECTORY_SEPARATOR === '\\');
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
$sudo = $isRoot ? 'sudo' : (piperNeedTool('sudo') ? 'sudo' : '');

try {
    if ($isWindows) {
        throw new Exception('установка движка поддерживается только на Linux');
    }

    piperSay('start ' . date('Y-m-d H:i:s'));
    piperSay('user=' . get_current_user() . ' arch=' . @trim((string)@shell_exec('uname -m')) . ' base=' . $base);

    if (piperNeedTool('apt-get') === '' && piperNeedTool('curl') === '') {
        throw new Exception('не найдены apt-get/curl — установите вручную');
    }

    piperSay('установка системных зависимостей...');
    if (piperNeedTool('apt-get') !== '') {
        $apt = $sudo !== '' ? $sudo . ' ' : '';
        piperRun($apt . 'env DEBIAN_FRONTEND=noninteractive apt-get update -qq');
        $rc = piperRun($apt . 'env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ' .
            'curl ca-certificates python3 python3-venv');
        if ($rc !== 0) {
            piperSay('apt-get завершился с кодом ' . $rc . ' — продолжаем, если инструменты уже есть');
        }
    }

    $python = piperNeedTool('python3');
    if ($python === '') {
        throw new Exception('не найден python3');
    }

    // Каталог движка мог остаться от прежней установки/попытки с владельцем
    // root (например, после ручного запуска из консоли). Без правки владельца
    // www-data не может создать в нём venv —«Permission denied».
    $selfUser = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = @posix_getpwuid(posix_geteuid()))
        ? $pw['name'] : null;
    if (!is_dir($base)) {
        if ($sudo !== '') piperRun($sudo . ' mkdir -p ' . escapeshellarg($base));
        else @mkdir($base, 0777, true);
    }
    if (!is_writable($base) && $sudo !== '') {
        piperSay('исправляю права на ' . $base . '...');
        $owner = $selfUser !== null ? $selfUser : 'www-data';
        piperRun($sudo . ' chown -R ' . escapeshellarg($owner) . ' ' . escapeshellarg($base));
        piperRun($sudo . ' chmod -R u+rwX ' . escapeshellarg($base));
    }
    if (!is_writable($base)) {
        throw new Exception('каталог ' . $base . ' недоступен для записи (владелец: ' .
            (is_dir($base) ? @decoct(@fileperms($base) & 0777) : 'нет') . ')');
    }

    $venv = $base . '/venv';
    $venvPy = $venv . '/bin/python';

    if (!is_file($venvPy)) {
        piperSay('создание виртуального окружения...');
        @mkdir('/var/tmp', 0777, true);
        $rc = piperRun('env TMPDIR=/var/tmp ' . escapeshellarg($python) . ' -m venv ' . escapeshellarg($venv));
        if ($rc !== 0 || !is_file($venvPy)) {
            throw new Exception('не удалось создать venv (код ' . $rc . ')');
        }
    }

    piperSay('установка piper-tts из PyPI (это может занять несколько минут)...');
    $env = 'env TMPDIR=/var/tmp PIP_CACHE_DIR=/var/tmp/pipcache';
    piperRun($env . ' ' . escapeshellarg($venvPy) . ' -m pip install --upgrade pip -q');
    $rc = piperRun($env . ' ' . escapeshellarg($venvPy) . ' -m pip install --upgrade piper-tts');
    if ($rc !== 0) {
        throw new Exception('pip install piper-tts завершился с кодом ' . $rc);
    }

    $piperBin = $venv . '/bin/piper';
    if (!is_file($piperBin)) {
        throw new Exception('после установки не найден ' . $piperBin);
    }

    piperSay('создание ссылки /usr/local/bin/piper...');
    $link = $sudo !== '' ? $sudo . ' ' : '';
    piperRun($link . 'ln -sf ' . escapeshellarg($piperBin) . ' /usr/local/bin/piper');

    // Голос — необязательная часть: движок уже установлен и работоспособен,
    // а голоса можно догрузить во вкладке «Модели». Сбой загрузки голоса
    // не должен помечать всю установку как неудачную.
    piperSay('голос по умолчанию: ' . $voice);
    if (!is_dir($modelsDir)) {
        if ($sudo !== '') piperRun($sudo . ' mkdir -p ' . escapeshellarg($modelsDir));
        else @mkdir($modelsDir, 0777, true);
    }
    if (!is_writable($modelsDir) && $sudo !== '') {
        $owner = $selfUser !== null ? $selfUser : 'www-data';
        piperRun($sudo . ' chown -R ' . escapeshellarg($owner) . ' ' . escapeshellarg($modelsDir));
        piperRun($sudo . ' chmod -R u+rwX ' . escapeshellarg($modelsDir));
    }
    if (!is_writable($modelsDir)) {
        piperSay('  каталог голосов ' . $modelsDir . ' недоступен для записи — ' .
            'голос можно поставить позже во вкладке «Модели».');
    } else {
        $vParts = explode('-', $voice, 2);
        $vName = $vParts[0];
        $vQuality = (isset($vParts[1]) && $vParts[1] !== '') ? $vParts[1] : 'medium';
        $vBase = 'ru_RU-' . $vName . '-' . $vQuality;
        $voiceDir = $modelsDir . '/' . $vBase;
        @mkdir($voiceDir, 0777, true);
        // Структура на Hugging Face: .../ru/ru_RU/<голос>/<качество>/ru_RU-<голос>-<качество>.onnx
        if ($vName === 'luka') {
            $modelBase = 'https://huggingface.co/superkeka/piper-tts-luka/resolve/main/ru/ru_RU/luka/' . $vQuality;
        } else {
            $modelBase = 'https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/' .
                $vName . '/' . $vQuality;
        }
        $files = array($vBase . '.onnx', $vBase . '.onnx.json');
        $needDownload = false;
        foreach ($files as $f) {
            if (!is_file($voiceDir . '/' . $f) || filesize($voiceDir . '/' . $f) === 0) {
                $needDownload = true;
                break;
            }
        }
        if (!$needDownload) {
            piperSay('  голос ' . $vBase . ' уже загружен, пропускаю.');
        } else {
            foreach ($files as $f) {
                piperSay('  загрузка ' . $f);
                if (!piperDownload($modelBase . '/' . $f, $voiceDir . '/' . $f)) {
                    piperSay('  не удалось загрузить ' . $f . ' — голос можно поставить позже во вкладке «Модели».');
                }
            }
        }
    }

    if (!$isRoot && posix_geteuid() !== 0) {
        piperRun($link . 'chown -R www-data:www-data ' . escapeshellarg($base) . ' 2>/dev/null || true');
    } else {
        piperRun('chown -R www-data:www-data ' . escapeshellarg($base) . ' 2>/dev/null || true');
    }

    piperSay('Done');
    @fclose($LOG);
    exit(0);
} catch (Throwable $e) {
    piperSay('FAILED: ' . $e->getMessage());
    @fclose($LOG);
    exit(1);
}
