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

namespace mod_ailanguageteacher\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\audio;
use mod_ailanguageteacher\local\learning;
use moodle_exception;

/**
 * Returns the audio for a phrase, asking the site speech service for it once when needed.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_audio extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'ref' => new external_value(PARAM_ALPHANUM, 'Phrase reference (p<id>) or Test listening token'),
            'variant' => new external_value(PARAM_ALPHA, 'normal, slow or example', VALUE_DEFAULT, 'normal'),
            'attemptid' => new external_value(PARAM_INT, 'Test attempt id for listening tokens', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Returns the audio.
     *
     * @param int $cmid
     * @param string $ref
     * @param string $variant
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $cmid, string $ref, string $variant = 'normal', int $attemptid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'ref' => $ref,
            'variant' => $variant, 'attemptid' => $attemptid]);
        [, , $instance, $context] = self::load_cm($params['cmid'], 'view');
        if (!in_array($params['variant'], audio::VARIANTS, true)) {
            throw new moodle_exception('invalidvariant', 'mod_ailanguageteacher');
        }
        $attempt = null;
        if ($params['attemptid']) {
            $attempt = learning::get_user_attempt($params['attemptid'], (int)$USER->id);
            if (
                (int)$attempt->ailanguageteacherid !== (int)$instance->id || $attempt->kind !== 'test'
                    || $params['variant'] !== 'normal'
            ) {
                throw new moodle_exception('invalidphrase', 'mod_ailanguageteacher');
            }
        } else if (
            learning::test_in_progress($instance, (int)$USER->id)
                && !has_capability('mod/ailanguageteacher:manage', $context)
        ) {
            // During a Test, audio is only given out for the Test's own listening items.
            throw new moodle_exception('testinprogress', 'mod_ailanguageteacher');
        }
        $phrase = learning::resolve_phrase($instance, $params['ref'], $attempt, 'listen');
        return ['url' => audio::url($instance, $context, $phrase, $params['variant'])];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'Audio URL, empty when the browser should speak the text'),
        ]);
    }
}
