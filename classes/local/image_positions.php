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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_ailanguageteacher\local;

/**
 * Optional image coordinates. Teachers must still check every label.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class image_positions {
    /**
     * Validates the complete ordered header without coercing strings to numbers.
     *
     * @param string $header compact JSON
     * @param int $count requested anchors
     * @return array|null validated points or no usable header
     */
    public static function decode(string $header, int $count): ?array {
        if ($count < 1 || $count > 6 || strlen($header) > 2048) {
            return null;
        }
        $points = json_decode($header, true);
        if (!is_array($points) || array_keys($points) !== range(0, $count - 1)) {
            return null;
        }
        foreach ($points as $point) {
            if ($point === null) {
                continue;
            }
            if (!is_array($point) || count($point) !== 2) {
                return null;
            }
            foreach (['x', 'y'] as $axis) {
                $value = $point[$axis] ?? null;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 100) {
                    return null;
                }
            }
        }
        return $points;
    }

    /**
     * Applies detected positions only to matching, unconfirmed phrases.
     *
     * @param \stdClass $scene scene record
     * @param array $anchors exact descriptions sent
     * @param string $header optional response header
     * @return void
     */
    public static function apply(\stdClass $scene, array $anchors, string $header): void {
        global $DB;
        $points = self::decode($header, count($anchors));
        if ($points === null) {
            return;
        }
        $phrases = manager::get_phrases([(int)$scene->id])[$scene->id] ?? [];
        foreach ($anchors as $index => $anchor) {
            $point = $points[$index];
            if ($point === null) {
                continue;
            }
            $group = array_values(array_filter($phrases, fn($p) => empty($p->distractor)
                && (string)$p->anchor === $anchor && (int)$p->placed !== 1));
            foreach ($group as $j => $phrase) {
                $y = max(2, min(98, $point['y'] + ($j - (count($group) - 1) / 2) * 14));
                // Preserve a teacher confirmation or edit made while the picture was being generated.
                $DB->execute('UPDATE {ailanguageteacher_phrase} SET x = ?, y = ?, placed = ?
                    WHERE id = ? AND sceneid = ? AND placed <> 1 AND anchor = ? AND distractor = 0',
                    [$point['x'], $y, manager::GUESSED, $phrase->id, $scene->id, $anchor]);
            }
        }
    }
}
