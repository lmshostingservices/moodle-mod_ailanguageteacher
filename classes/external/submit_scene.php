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
 * Submits one Test stage (match or listen) of a scene.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_scene extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'sceneid' => new external_value(PARAM_INT, 'Scene id'),
            'stage' => new external_value(PARAM_ALPHA, 'match or listen'),
            'answers' => new external_multiple_structure(new external_single_structure([
                'item' => new external_value(PARAM_ALPHANUM, 'Pin token (match) or listening token (listen)'),
                'answer' => new external_value(PARAM_ALPHANUM, 'Phrase token (match) or pin token (listen)', VALUE_DEFAULT, ''),
            ]), 'Answers', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Submits.
     *
     * @param int $attemptid
     * @param int $sceneid
     * @param string $stage
     * @param array $answers
     * @return array
     */
    public static function execute(int $attemptid, int $sceneid, string $stage, array $answers = []): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid,
            'sceneid' => $sceneid, 'stage' => $stage, 'answers' => $answers]);
        [, , $instance, , $attempt] = self::load_attempt($params['attemptid']);
        $count = learning::submit_scene($instance, $attempt, $params['sceneid'], $params['stage'], $params['answers']);
        return ['recorded' => $count];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_INT, 'Answers recorded'),
        ]);
    }
}
