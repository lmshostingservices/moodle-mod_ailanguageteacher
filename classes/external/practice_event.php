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
use mod_ailanguageteacher\local\learning;

/**
 * Records a Practice step for a phrase.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class practice_event extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'token' => new external_value(PARAM_ALPHANUM, 'Phrase token'),
            'action' => new external_value(PARAM_ALPHA, 'matched, hint or moveon'),
            'tries' => new external_value(PARAM_INT, 'Drops until correct', VALUE_DEFAULT, 1),
            'hintlevel' => new external_value(PARAM_INT, 'Hint level 1-4', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Records the step.
     *
     * @param int $attemptid
     * @param string $token
     * @param string $action
     * @param int $tries
     * @param int $hintlevel
     * @return array
     */
    public static function execute(
        int $attemptid,
        string $token,
        string $action,
        int $tries = 1,
        int $hintlevel = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid, 'token' => $token,
            'action' => $action, 'tries' => $tries, 'hintlevel' => $hintlevel]);
        [$course, $cm, $instance, $context, $attempt] = self::load_attempt($params['attemptid']);
        return learning::practice_event(
            $instance,
            $cm,
            $course,
            $context,
            $attempt,
            $params['token'],
            $params['action'],
            $params['tries'],
            $params['hintlevel']
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => new external_value(PARAM_ALPHA, 'Progress state'),
            'successes' => new external_value(PARAM_INT, 'Successful speaking tries'),
        ]);
    }
}
