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

use moodle_exception;

/**
 * Checks learner recordings in PHP before anything is sent to a speech service.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wav {
    /** @var int Sample rate required, in Hz. */
    public const RATE = 16000;

    /** @var float Longest recording accepted, in seconds (provisional until the LMS Labs contract confirms it). */
    public const MAX_SECONDS = 8.0;

    /** @var float Shortest recording accepted, in seconds. */
    public const MIN_SECONDS = 0.2;

    /** @var int Largest file accepted: 8 s of 16 kHz 16-bit mono plus a small allowance for headers. */
    public const MAX_BYTES = 256000 + 1024;

    /**
     * Validates a recording and returns its length.
     *
     * @param string $bytes
     * @return float duration in seconds
     * @throws moodle_exception badrecording when it is not a short 16 kHz, 16-bit, mono PCM WAV
     */
    public static function validate(string $bytes): float {
        $size = strlen($bytes);
        if ($size < 44 || $size > self::MAX_BYTES || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw new moodle_exception('badrecording', 'mod_ailanguageteacher');
        }
        $format = null;
        $datasize = null;
        $pos = 12;
        while ($pos + 8 <= $size) {
            $id = substr($bytes, $pos, 4);
            $len = unpack('V', substr($bytes, $pos + 4, 4))[1];
            if ($id === 'fmt ' && $len >= 16 && $pos + 8 + 16 <= $size) {
                $format = unpack('vcode/vchannels/Vrate/Vbyterate/vblock/vbits', substr($bytes, $pos + 8, 16));
            } else if ($id === 'data') {
                $datasize = min($len, $size - $pos - 8);
                break;
            }
            $pos += 8 + $len + ($len % 2);
        }
        if (
            !$format || $datasize === null || $format['code'] !== 1 || $format['channels'] !== 1
                || $format['rate'] !== self::RATE || $format['bits'] !== 16
        ) {
            throw new moodle_exception('badrecording', 'mod_ailanguageteacher');
        }
        $seconds = $datasize / (self::RATE * 2);
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS + 0.05) {
            throw new moodle_exception('badrecording', 'mod_ailanguageteacher');
        }
        return $seconds;
    }
}
