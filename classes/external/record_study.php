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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\learning;

/**
 * Records the phrases a learner studied.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_study extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'phraseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Phrase id'),
                'Phrases opened',
                VALUE_DEFAULT,
                []
            ),
            'complete' => new external_value(PARAM_BOOL, 'Every scene has been seen', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Records.
     *
     * @param int $cmid
     * @param array $phraseids
     * @param bool $complete
     * @return array
     */
    public static function execute(int $cmid, array $phraseids = [], bool $complete = false): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'phraseids' => $phraseids,
            'complete' => $complete]);
        [$course, $cm, $instance, $context] = self::load_cm($params['cmid'], 'attempt');
        learning::record_study($instance, $cm, $course, $context, (int)$USER->id, array_slice(
            $params['phraseids'],
            0,
            500
        ), $params['complete']);
        return ['studied' => learning::has_studied((int)$instance->id, (int)$USER->id)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'studied' => new external_value(PARAM_BOOL, 'Study finished'),
        ]);
    }
}
