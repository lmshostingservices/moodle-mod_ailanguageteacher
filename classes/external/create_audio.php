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

/** Teacher-only, explicit 1-credit synthesis action. */
class create_audio extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phraseid' => new external_value(PARAM_INT, 'Phrase id'),
            'voice' => new external_value(PARAM_TEXT, 'Exact available voice name'),
            'variant' => new external_value(PARAM_ALPHA, 'normal, slow or example'),
        ]);
    }

    public static function execute(int $phraseid, string $voice, string $variant): array {
        global $USER;
        self::validate_parameters(self::execute_parameters(), compact('phraseid', 'voice', 'variant'));
        [, , $instance, $context, $phrase] = self::load_phrase($phraseid);
        require_capability('mod/ailanguageteacher:useai', $context);
        if (!confirm_sesskey() || !in_array($variant, audio::VARIANTS, true) ||
                !in_array($voice, array_column((new \mod_ailanguageteacher\local\speech\lmslabs())
                    ->voices($instance->targetlocale), 'name'), true)) {
            throw new \moodle_exception('speechcatalogunavailable', 'mod_ailanguageteacher');
        }
        return ['url' => audio::create($instance, $context, $phrase, $variant, (int)$USER->id, $voice)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['url' => new external_value(PARAM_URL, 'Saved audio URL')]);
    }
}