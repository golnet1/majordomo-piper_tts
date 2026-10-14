<?php
/**
 * Словарь произношения Piper: замены текста перед синтезом движком.
 *
 * Правило — строка таблицы piper_dict: LANG (язык голоса: ru; пусто — все),
 * MATCH_TYPE, PATTERN, REPLACEMENT, CASE_SENSITIVE, ACTIVE, PRIORITY (порядок
 * применения), NOTE.
 *
 * Типы:
 *   word  — слово или фраза целиком (по границам слов). «*» в конце —
 *           начало слова: «портфел*» ловит портфель, портфеля, портфелями;
 *           «*» в замене — остаток слова (если его нет, остаток дописывается).
 *   text  — любой кусок текста, в том числе внутри слова и знаки (°C, №).
 *   regex — регулярное выражение PCRE без ограничителей; в замене $1, $2…
 *
 * Без учёта регистра заглавная первая буква найденного переносится на замену:
 * «Портфель» → «П+ортфель». Ударение в замене — как в тексте: «+» перед
 * гласной, одна заглавная гласная или знак ударения (U+0301).
 *
 * Ни mbstring, ни iconv не нужны.
 */
class PiperDictionary
{
    const TYPES = 'word text regex';
    /** Символы слова для границ: буквы, комбинирующие знаки (ударение), цифры. */
    const W = '\p{L}\p{M}\p{N}';
    const MAX_LEN = 255;

    public static function types()
    {
        return explode(' ', self::TYPES);
    }

    /**
     * Строка без управляющих символов и табуляций. $trim = false — пробелы по
     * краям значимы (замена «°C» → « градусов» у типа «Любой текст»).
     */
    public static function clean($s, $trim = true)
    {
        $s = (string)$s;
        if (!preg_match('//u', $s)) return '';
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        return $trim ? trim($s) : $s;
    }

    /** Поля правила в сохраняемом виде: у «Слова» пробелы по краям не нужны, у остальных — значимы. */
    public static function normalize($type, $pattern, $replacement)
    {
        $keep = $type !== 'word';
        $pattern = self::clean($pattern, !$keep);
        $replacement = self::clean($replacement, !$keep);
        return array($pattern, $replacement);
    }

    /** Длина строки UTF-8 в символах. */
    public static function len($s)
    {
        return preg_match_all('/./su', (string)$s);
    }

    /**
     * Проверка правила перед сохранением. null — всё в порядке, иначе текст
     * ошибки.
     */
    public static function validate($type, $pattern, $replacement)
    {
        if (!in_array($type, self::types(), true)) return 'неизвестный тип правила';
        if (trim($pattern) === '') return 'пустое «Что»';
        if (self::len($pattern) > self::MAX_LEN || self::len($replacement) > self::MAX_LEN) return 'не длиннее ' . self::MAX_LEN . ' символов';
        if ($type === 'word') {
            $base = rtrim($pattern, '*');
            if ($base === '' || strpos($base, '*') !== false) return '«*» — только в конце слова';
            if (!preg_match('/^[' . self::W . ']/u', $base)) return 'слово должно начинаться с буквы или цифры (для знаков — тип «Любой текст»)';
        }
        // «+» в замене — отметка ударения: только перед гласной (д+ома), иначе
        // она прозвучит как есть.
        if (preg_match('/\+(?![аеёиоуыэюяАЕЁИОУЫЭЮЯ])/u', $replacement)) return '«+» ставится прямо перед ударной гласной: д+ома, +Яндекс';
        if ($type === 'regex') {
            $re = self::regexOf($pattern, false);
            set_error_handler(function () { return true; });
            $ok = @preg_match($re, '');
            restore_error_handler();
            if ($ok === false) return 'ошибка в регулярном выражении';
            if (@preg_match($re, '') === 1) return 'выражение совпадает с пустой строкой';
        }
        return null;
    }

    /** Регулярное выражение пользователя: ограничитель — символ, которого не бывает в тексте. */
    private static function regexOf($pattern, $cs)
    {
        return "\x01" . str_replace("\x01", '', $pattern) . "\x01u" . ($cs ? '' : 'i');
    }

    /**
     * Скомпилированное правило: array(regex, замена или callback, тип).
     * null — правило негодно (не применяется).
     */
    public static function compile(array $r)
    {
        $type = (string)$r['MATCH_TYPE'];
        $pat = (string)$r['PATTERN'];
        $rep = (string)$r['REPLACEMENT'];
        $cs = (int)$r['CASE_SENSITIVE'] === 1;
        if (self::validate($type, $pat, $rep) !== null) return null;
        $flags = 'u' . ($cs ? '' : 'i');
        $W = self::W;
        if ($type === 'regex') return array(self::regexOf($pat, $cs), $rep, 'regex');
        if ($type === 'text') {
            $re = '/' . preg_quote($pat, '/') . '/' . $flags;
            return array($re, function ($m) use ($rep, $cs) { return $cs ? $rep : PiperDictionary::matchCase($m[0], $rep); }, 'cb');
        }
        // word: границы слова, пробелы во фразе — любые пробельные.
        $prefix = substr($pat, -1) === '*';
        $base = rtrim($pat, '*');
        $q = preg_replace('/\s+/u', '\\s+', preg_quote(preg_replace('/\s+/u', ' ', $base), '/'));
        $re = '/(?<![' . $W . '])' . $q . ($prefix ? '([' . $W . ']*)' : '(?![' . $W . '])') . '/' . $flags;
        return array($re, function ($m) use ($rep, $cs, $prefix) {
            $out = $rep;
            if ($prefix) {
                $tail = isset($m[1]) ? $m[1] : '';
                $out = strpos($out, '*') !== false ? str_replace('*', $tail, $out) : $out . $tail;
            }
            return $cs ? $out : PiperDictionary::matchCase($m[0], $out);
        }, 'cb');
    }

    /**
     * Регистр найденного — на замену: «Портфель» → «П+ортфель». Слово целиком
     * заглавными (MQTT, °C) и замена, где уже есть заглавные, не трогаются.
     */
    public static function matchCase($found, $rep)
    {
        if (!preg_match('/^\P{L}*\p{Lu}/u', $found) || !preg_match('/\p{Ll}/u', $found)) return $rep;
        if (preg_match('/\p{Lu}/u', $rep)) return $rep;
        if (!preg_match('/^(\P{L}*)(\p{Ll})(.*)$/su', $rep, $m)) return $rep;
        return $m[1] . self::upper($m[2]) . $m[3];
    }

    /** Заглавная буква без mbstring (кириллица, латиница и т. п.). */
    public static function upper($ch)
    {
        if (function_exists('mb_strtoupper')) return mb_strtoupper($ch, 'UTF-8');
        static $map = null;
        if ($map === null) {
            $lo = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяєіїўґ';
            $up = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯЄІЇЎҐ';
            preg_match_all('/./u', $lo, $a);
            preg_match_all('/./u', $up, $b);
            $map = array_combine($a[0], $b[0]);
        }
        if (isset($map[$ch])) return $map[$ch];
        return strtoupper($ch);
    }

    /**
     * Применяет правила по порядку; $hits — сработавшие: ID => число замен.
     * Ошибка выполнения регулярного выражения (слишком сложное) — правило
     * пропускается, текст не портится.
     */
    public static function apply($text, array $rules, &$hits = null)
    {
        $hits = array();
        foreach ($rules as $r) {
            $c = self::compile($r);
            if ($c === null) continue;
            $n = 0;
            if ($c[2] === 'regex') {
                $res = @preg_replace($c[0], $c[1], $text, -1, $n);
            } else {
                $res = @preg_replace_callback($c[0], $c[1], $text, -1, $n);
            }
            if ($res === null) continue;
            if ($n > 0) {
                $hits[(int)$r['ID']] = $n;
                $text = $res;
            }
        }
        return $text;
    }

    /** Отпечаток набора правил — для ключа кэша. */
    public static function stamp(array $rules)
    {
        if (!$rules) return '';
        $p = array();
        foreach ($rules as $r) {
            $p[] = implode("\x1F", array($r['MATCH_TYPE'], $r['PATTERN'], $r['REPLACEMENT'], (int)$r['CASE_SENSITIVE']));
        }
        return substr(md5(implode("\x1E", $p)), 0, 12);
    }

    // ------------------------------------------------------------------
    // Импорт и экспорт: текстовый файл, по правилу на строку, поля через TAB
    // ------------------------------------------------------------------

    const EXPORT_HEADER = '# Piper: словарь произношения';

    public static function export(array $rules)
    {
        $lines = array(self::EXPORT_HEADER,
            '# что<TAB>как читать<TAB>тип (word|text|regex)<TAB>регистр (0|1)<TAB>вкл (0|1)<TAB>язык (ru, пусто — все)<TAB>комментарий',
            '# Достаточно двух первых полей: «слово<TAB>замена» — слово, без учёта регистра, для русского.');
        foreach ($rules as $r) {
            $lines[] = implode("\t", array(self::clean($r['PATTERN'], false), self::clean($r['REPLACEMENT'], false), $r['MATCH_TYPE'],
                (int)$r['CASE_SENSITIVE'], (int)$r['ACTIVE'], self::clean($r['LANG']), self::clean($r['NOTE'])));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Разбор файла импорта. Понимает свой формат (поля через TAB) и простые
     * списки «что = как читать» / «что<TAB>как читать». Возвращает
     * array(правила, ошибки), ошибка — «строка N: причина».
     */
    public static function parse($content, $defaultLang = 'ru')
    {
        $content = (string)$content;
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        if (!preg_match('//u', $content)) {
            // Не UTF-8 — вероятно, Windows-1251 (Блокнот, Excel).
            $content = self::cp1251($content);
        }
        $rules = array();
        $errors = array();
        foreach (preg_split('/\r\n|\r|\n/', $content) as $i => $line) {
            $no = $i + 1;
            if (trim($line) === '' || preg_match('/^\s*(#|\/\/)/', $line)) continue;
            if (strpos($line, "\t") !== false) {
                $f = explode("\t", $line);
            } elseif (preg_match('/^(.*?)\s+=\s+(.*)$/u', $line, $m) || preg_match('/^(.*?)\s*=\s*(.*)$/u', $line, $m)) {
                $f = array($m[1], $m[2]);
            } else {
                $errors[] = "строка $no: нет разделителя (TAB или =)";
                continue;
            }
            $f = array_pad($f, 7, '');
            $type = trim($f[2]) !== '' ? strtolower(trim($f[2])) : 'word';
            list($pat, $rep) = self::normalize($type, $f[0], $f[1]);
            $r = array(
                'PATTERN' => $pat,
                'REPLACEMENT' => $rep,
                'MATCH_TYPE' => $type,
                'CASE_SENSITIVE' => trim($f[3]) === '1' ? 1 : 0,
                'ACTIVE' => trim($f[4]) === '0' ? 0 : 1,
                'LANG' => preg_match('/^[a-z]{0,8}$/', trim($f[5])) ? (trim($f[5]) !== '' ? trim($f[5]) : $defaultLang) : $defaultLang,
                'NOTE' => self::clean($f[6]),
            );
            $err = self::validate($r['MATCH_TYPE'], $r['PATTERN'], $r['REPLACEMENT']);
            if ($err !== null) { $errors[] = "строка $no: $err"; continue; }
            $rules[] = $r;
        }
        return array($rules, $errors);
    }

    /** Windows-1251 → UTF-8 (без iconv). */
    public static function cp1251($s)
    {
        if (function_exists('iconv')) {
            $r = @iconv('CP1251', 'UTF-8//IGNORE', $s);
            if ($r !== false) return $r;
        }
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($s[$i]);
            if ($c < 0x80) { $out .= $s[$i]; continue; }
            if ($c >= 0xC0) { $u = 0x410 + ($c - 0xC0); }
            elseif ($c == 0xA8) { $u = 0x401; }
            elseif ($c == 0xB8) { $u = 0x451; }
            else { $u = 0xFFFD; }
            $out .= chr(0xC0 | ($u >> 6)) . chr(0x80 | ($u & 0x3F));
        }
        return $out;
    }
}
