<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_ailanguageteacher\local\byok;

/**
 * JavaScript's behaviour for the values the ported service code handles.
 *
 * JSON values are held as PHP decodes them without the associative flag: objects are \stdClass, arrays are lists,
 * and null stands for both null and a missing value.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class js {
    /** @var string JavaScript's whitespace (\s and String.prototype.trim()), for a /u character class. */
    const WS = '\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';

    /** @var string JavaScript's whitespace as UTF-8 byte sequences, for patterns without /u (ASCII-only /i). */
    const WSB = '(?:[\t-\r ]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

    /**
     * JSON.parse(): the value, or an exception like JSON.parse's SyntaxError.
     *
     * JSON.parse keeps an unpaired surrogate escape, which PHP refuses; it becomes U+FFFD here.
     *
     * @param string $text
     * @return mixed
     */
    public static function parse(string $text) {
        $value = json_decode($text, false, 100000);
        if (json_last_error() === JSON_ERROR_UTF16) {
            $value = json_decode(self::fix_surrogates($text), false, 100000);
        }
        if (json_last_error() !== JSON_ERROR_NONE || trim($text, " \t\n\r") === '') {
            throw new \RuntimeException('Unexpected token in JSON: ' . json_last_error_msg());
        }
        return self::numbers($value);
    }

    /**
     * Replace unpaired surrogate escapes with �, keeping pairs.
     *
     * @param string $text
     * @return string
     */
    protected static function fix_surrogates(string $text): string {
        return preg_replace_callback('/\\\\(\\\\|u[dD][89a-fA-F][0-9a-fA-F]{2}(?:\\\\u[0-9a-fA-F]{4})?)/', function ($m) {
            if ($m[1] === '\\') {
                return $m[0];
            }
            $high = hexdec(substr($m[1], 1, 4));
            if (strlen($m[1]) === 11) {
                $low = hexdec(substr($m[1], 7, 4));
                if ($high <= 0xDBFF && $low >= 0xDC00 && $low <= 0xDFFF) {
                    return $m[0];
                }
                return '\\ufffd' . substr($m[0], 6);
            }
            return '\\ufffd';
        }, $text);
    }

    /**
     * Numbers as JavaScript holds them: whole numbers beyond 2^53 lose precision as doubles.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function numbers($value) {
        if (is_int($value) && abs($value) > 9007199254740992) {
            return (float) $value;
        }
        if (is_array($value)) {
            return array_map([self::class, 'numbers'], $value);
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->$k = self::numbers($v);
            }
        }
        return $value;
    }

    /**
     * JSON.stringify().
     *
     * @param mixed $value
     * @return string
     */
    public static function stringify($value): string {
        if ($value === null || (is_float($value) && !is_finite($value))) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        }
        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(fn($v) => self::stringify($v), $value)) . ']';
        }
        $out = [];
        foreach (self::keys($value) as $key) {
            $out[] = self::stringify((string) $key) . ':' . self::stringify(is_array($value) ? $value[$key] : $value->$key);
        }
        return '{' . implode(',', $out) . '}';
    }

    /**
     * An object's keys in JavaScript's order: array-index keys ascending, then the rest as inserted.
     *
     * @param array|\stdClass $object
     * @return array
     */
    public static function keys($object): array {
        $keys = array_map('strval', array_keys(is_array($object) ? $object : get_object_vars($object)));
        $isindex = fn($k) => preg_match('/^(?:0|[1-9][0-9]{0,9})$/', $k) && (float) $k < 4294967295;
        $index = array_values(array_filter($keys, $isindex));
        $other = array_values(array_filter($keys, fn($k) => !in_array($k, $index, true)));
        usort($index, fn($a, $b) => (float) $a <=> (float) $b);
        return array_merge($index, $other);
    }

    /**
     * Number.prototype.toString().
     *
     * @param int|float $n
     * @return string
     */
    public static function number($n): string {
        if (is_float($n) && is_nan($n)) {
            return 'NaN';
        }
        if (is_float($n) && is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if (is_int($n)) {
            return (string) $n;
        }
        if ($n == 0) {
            return '0';
        }
        // The shortest digits that round-trip, as both PHP (serialize_precision -1) and JavaScript choose.
        $repr = var_export($n, true);
        $negative = $repr[0] === '-';
        $repr = ltrim($repr, '-');
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:E([+-]?\d+))?$/i', $repr, $m)) {
            $int = $m[1];
            $frac = $m[2] ?? '';
            $exp = (int) ($m[3] ?? 0);
            $digits = ltrim($int . $frac, '0');
            $point = strlen($int) + $exp - (strlen($int . $frac) - strlen(ltrim($int . $frac, '0')));
            $digits = rtrim($digits, '0');
            $k = strlen($digits);
            $n = $point;
            if ($k <= $n && $n <= 21) {
                $s = $digits . str_repeat('0', $n - $k);
            } else if (0 < $n && $n <= 21) {
                $s = substr($digits, 0, $n) . '.' . substr($digits, $n);
            } else if (-6 < $n && $n <= 0) {
                $s = '0.' . str_repeat('0', -$n) . $digits;
            } else {
                $e = $n - 1;
                $s = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
            }
            return ($negative ? '-' : '') . $s;
        }
        return (string) $n;
    }

    /**
     * String(value).
     *
     * @param mixed $value
     * @return string
     */
    public static function str($value): string {
        if ($value === null) {
            return 'undefined';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }
        if (is_array($value)) {
            return implode(',', array_map(fn($v) => $v === null ? '' : self::str($v), $value));
        }
        return '[object Object]';
    }

    /**
     * Whether a value is truthy.
     *
     * @param mixed $value
     * @return bool
     */
    public static function truthy($value): bool {
        if (is_float($value)) {
            return $value != 0 && !is_nan($value);
        }
        return !($value === null || $value === false || $value === 0 || $value === '');
    }

    /**
     * value[key] for a parsed JSON value (null when missing).
     *
     * @param mixed $value
     * @param string|int $key
     * @return mixed
     */
    public static function get($value, $key) {
        if ($value instanceof \stdClass) {
            return property_exists($value, (string) $key) ? $value->{(string) $key} : null;
        }
        if (is_array($value)) {
            if ($key === 'length') {
                return count($value);
            }
            return is_int($key) || ctype_digit((string) $key) ? ($value[(int) $key] ?? null) : null;
        }
        if (is_string($value)) {
            if ($key === 'length') {
                return self::len($value);
            }
            if (is_int($key) || ctype_digit((string) $key)) {
                $char = self::slice($value, (int) $key, (int) $key + 1);
                return $char === '' ? null : $char;
            }
        }
        return null;
    }

    /**
     * Number.isInteger().
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_integer($value): bool {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) == $value);
    }

    /**
     * String.prototype.trim().
     *
     * @param string $s
     * @return string
     */
    public static function trim(string $s): string {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/Du', '', $s);
    }

    /**
     * A string's length in UTF-16 code units.
     *
     * @param string $s
     * @return int
     */
    public static function len(string $s): int {
        return (int) (strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /**
     * String.prototype.slice() with a non-negative start, in UTF-16 code units.
     *
     * @param string $s
     * @param int $start
     * @param int|null $end
     * @return string
     */
    public static function slice(string $s, int $start, ?int $end = null): string {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $units = (int) (strlen($u) / 2);
        $end = $end === null ? $units : min($end, $units);
        if ($end <= $start) {
            return '';
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $out = mb_convert_encoding(substr($u, $start * 2, ($end - $start) * 2), 'UTF-8', 'UTF-16LE');
        mb_substitute_character($previous);
        return $out;
    }

    /**
     * String.prototype.toLowerCase().
     *
     * @param string $s
     * @return string
     */
    public static function lower(string $s): string {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * A JavaScript TypeError's message when a property of null or undefined is read.
     *
     * @param string $property
     * @return string
     */
    public static function cannot_read(string $property): string {
        return "Cannot read properties of undefined (reading '{$property}')";
    }
}
