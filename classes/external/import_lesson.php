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
use mod_ailanguageteacher\local\lesson;

/**
 * Creates scenes and phrases from a lesson draft (AI output or JSON pasted by the teacher).
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_lesson extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'draft' => new external_value(PARAM_TEXT, 'Lesson draft JSON'),
        ]);
    }

    /**
     * Imports.
     *
     * @param int $cmid
     * @param string $draft
     * @return array
     */
    public static function execute(int $cmid, string $draft): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'draft' => $draft]);
        [, , $instance] = self::load_cm($params['cmid'], 'manage');
        return lesson::import($instance, lesson::parse($params['draft']));
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scenes' => new external_value(PARAM_INT, 'Scenes created'),
            'phrases' => new external_value(PARAM_INT, 'Phrases created'),
        ]);
    }
}
