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
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\audio;

/**
 * Creates one phrase voice with LMS Labs (5 credits when delivered). Teachers only, always an explicit action.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_audio extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phraseid' => new external_value(PARAM_INT, 'Phrase id'),
            'voice' => new external_value(PARAM_TEXT, 'Exact available voice name'),
            'variant' => new external_value(PARAM_ALPHA, 'normal, slow or example'),
            'discard' => new external_value(
                PARAM_BOOL,
                'The teacher confirmed abandoning an unfinished request',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Creates the voice.
     *
     * @param int $phraseid
     * @param string $voice exact catalogue voice name
     * @param string $variant normal, slow or example
     * @param bool $discard the teacher confirmed abandoning an unfinished request
     * @return array
     */
    public static function execute(int $phraseid, string $voice, string $variant, bool $discard = false): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact('phraseid', 'voice', 'variant', 'discard'));
        [, , $instance, $context, $phrase] = self::load_phrase($phraseid);
        require_capability('mod/ailanguageteacher:useai', $context);
        $names = array_column((new \mod_ailanguageteacher\local\speech\lmslabs())->voices($instance->targetlocale), 'name');
        if (!in_array($variant, audio::VARIANTS, true) || !in_array($voice, $names, true)) {
            throw new \moodle_exception('speechcatalogunavailable', 'mod_ailanguageteacher');
        }
        // A phrase said by someone of the other gender gets the activity's second voice, unless a request made with
        // the activity's voice is still unresolved: that one is asked about again (same key), never replaced.
        $matched = \mod_ailanguageteacher\local\voices::for_phrase($instance, $phrase, $names, $voice);
        if ($matched !== $voice && audio::pending_with_voice($instance, $phrase, $variant, $voice)) {
            $matched = $voice;
        }
        $voice = $matched;
        return ['url' => audio::create($instance, $context, $phrase, $variant, (int)$USER->id, $voice, $discard)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['url' => new external_value(PARAM_URL, 'Saved audio URL')]);
    }
}
