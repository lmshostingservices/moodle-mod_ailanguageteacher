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
 * Exact available voice choices.
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
use mod_ailanguageteacher\local\speech\lmslabs;

/** Exposes eligible live names, never credentials, to a teacher. */
class voice_choices extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['cmid' => new external_value(PARAM_INT, 'Course module id')]);
    }
    public static function execute(int $cmid): array {
        self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [, , $instance, $context] = self::load_cm($cmid, 'manage');
        require_capability('mod/ailanguageteacher:useai', $context);
        return ['voices' => array_column((new lmslabs())->voices($instance->targetlocale), 'name')];
    }
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['voices' => new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Full voice name'))]);
    }
}