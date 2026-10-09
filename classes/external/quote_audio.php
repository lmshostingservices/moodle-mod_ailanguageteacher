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

/**
 * Teacher-initiated speech synthesis.
 *
 * @package mod_ailanguageteacher
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_ailanguageteacher\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\audio;

/**
 * The current price of phrase voices (free: nothing is made, charged or reserved). With free remakes a voice made again
 * after an edit is often free; the teacher confirms the total before anything is made.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quote_audio extends base {
    /** @var int Most voices priced in one call. */
    public const MAX = 300;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phraseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Phrase id')),
            'variant' => new external_value(PARAM_ALPHA, 'normal, slow or example', VALUE_DEFAULT, 'normal'),
        ]);
    }

    /**
     * Prices the voices.
     *
     * @param int[] $phraseids
     * @param string $variant
     * @return array
     */
    public static function execute(array $phraseids, string $variant = 'normal'): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('phraseids', 'variant'));
        if (!in_array($params['variant'], audio::VARIANTS, true)) {
            throw new \moodle_exception('invalidvariant', 'mod_ailanguageteacher');
        }
        $out = [];
        // Price checks are free but not waited for forever: after 60 seconds the rest count as full price.
        $until = time() + 60;
        foreach (array_slice($params['phraseids'], 0, self::MAX) as $phraseid) {
            [, , $instance, $context, $phrase] = self::load_phrase((int)$phraseid);
            require_capability('mod/ailanguageteacher:useai', $context);
            $out[] = ['phraseid' => (int)$phraseid, 'credits' => time() < $until
                ? audio::quote($instance, $phrase, $params['variant'])
                : \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS];
        }
        return $out;
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'phraseid' => new external_value(PARAM_INT, 'Phrase id'),
            'credits' => new external_value(PARAM_INT, 'Current price: 0 (a free remake) or 5'),
        ]));
    }
}
