<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_ailanguageteacher\local\speech;

/**
 * Compares what a speech recogniser heard with the expected phrase and its accepted alternatives.
 *
 * This is the spoken-answer check for every speaking mode that produces text (the site speech service or the
 * browser's own recognition). It measures which words were recognised and how closely they match, not pronunciation
 * quality, and the interface says so. Recogniser confidence is never used as a score.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matcher {
    /**
     * Lower-cases and strips punctuation.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string {
        $text = \core_text::strtolower($text);
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Splits text into comparable units: words, or characters for languages written without spaces.
     *
     * @param string $text normalised text
     * @param bool $chars
     * @return string[]
     */
    public static function units(string $text, bool $chars): array {
        if ($text === '') {
            return [];
        }
        if ($chars) {
            return preg_split('//u', str_replace(' ', '', $text), -1, PREG_SPLIT_NO_EMPTY);
        }
        return explode(' ', $text);
    }

    /**
     * Edit distance between two lists.
     *
     * @param string[] $a
     * @param string[] $b
     * @return int
     */
    public static function distance(array $a, array $b): int {
        $n = count($a);
        $m = count($b);
        if (!$n) {
            return $m;
        }
        if (!$m) {
            return $n;
        }
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$m];
    }

    /**
     * Similarity of two texts, 0-100. Word lists are also compared letter by letter, so a near miss
     * ("mornin" for "morning") still earns most of the credit.
     *
     * @param string $heard
     * @param string $expected
     * @param bool $chars
     * @return float
     */
    public static function similarity(string $heard, string $expected, bool $chars): float {
        $h = self::normalise($heard);
        $e = self::normalise($expected);
        if ($e === '') {
            return 0.0;
        }
        if ($h === $e) {
            return 100.0;
        }
        $letters = function (string $t): array {
            return preg_split('//u', str_replace(' ', '', $t), -1, PREG_SPLIT_NO_EMPTY);
        };
        $el = $letters($e);
        $letterscore = 1 - self::distance($letters($h), $el) / max(count($el), count($letters($h)), 1);
        if ($chars) {
            return round(max(0, $letterscore) * 100, 2);
        }
        $ew = self::units($e, false);
        $hw = self::units($h, false);
        $wordscore = 1 - self::distance($hw, $ew) / max(count($ew), count($hw), 1);
        return round(max(0, 0.5 * $wordscore + 0.5 * $letterscore) * 100, 2);
    }

    /**
     * Best match of any heard transcript against the phrase and its alternatives.
     *
     * @param string[] $transcripts what the recogniser heard (best guess first)
     * @param string $phrase
     * @param string[] $alternatives
     * @param bool $chars
     * @return array [score, transcript that matched best, target it matched (the phrase or an alternative)]
     */
    public static function best(array $transcripts, string $phrase, array $alternatives, bool $chars): array {
        $best = 0.0;
        $heard = (string)($transcripts[0] ?? '');
        $matched = $phrase;
        $targets = array_merge([$phrase], $alternatives);
        foreach (array_slice($transcripts, 0, 5) as $transcript) {
            foreach ($targets as $target) {
                $score = self::similarity((string)$transcript, (string)$target, $chars);
                if ($score > $best) {
                    $best = $score;
                    $heard = (string)$transcript;
                    $matched = (string)$target;
                }
            }
        }
        return [$best, $heard, $matched];
    }

    /**
     * Which words (or characters, for languages written without spaces) of the expected text were recognised, in order.
     *
     * @param string $heard
     * @param string $expected
     * @param bool $chars
     * @return array ['found' => int, 'total' => int, 'units' => [['text' => string, 'ok' => bool], ...]]
     */
    public static function recognised(string $heard, string $expected, bool $chars): array {
        // Display units keep the expected text's own spelling; matching uses the normalised form.
        $display = [];
        if ($chars) {
            foreach (preg_split('//u', $expected, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if (self::normalise($ch) !== '') {
                    $display[] = $ch;
                }
            }
        } else {
            foreach (preg_split('/\s+/u', trim($expected), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (self::normalise($word) !== '') {
                    $display[] = $word;
                }
            }
        }
        $want = array_map(fn($u) => str_replace(' ', '', self::normalise($u)), $display);
        $got = self::units(self::normalise($heard), $chars);
        // Longest common subsequence: a unit counts when it was heard in the right order.
        $n = count($want);
        $m = count($got);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $want[$i] === $got[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $ok = array_fill(0, $n, false);
        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if ($want[$i] === $got[$j]) {
                $ok[$i] = true;
                $i++;
                $j++;
            } else if ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        $units = [];
        foreach ($display as $i => $text) {
            $units[] = ['text' => $text, 'ok' => $ok[$i]];
        }
        return ['found' => count(array_filter($ok)), 'total' => $n, 'units' => $units];
    }
}
