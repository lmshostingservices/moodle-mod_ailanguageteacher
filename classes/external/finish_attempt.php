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
 * Finishes and grades an attempt.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_attempt extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
        ]);
    }

    /**
     * Finishes.
     *
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $attemptid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        [$course, $cm, $instance, $context, $attempt] = self::load_attempt($params['attemptid']);
        return learning::finish_attempt($instance, $cm, $course, $context, $attempt);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'kind' => new external_value(PARAM_ALPHA, 'Kind'),
            'correct' => new external_value(PARAM_FLOAT, 'Correct'),
            'total' => new external_value(PARAM_INT, 'Total'),
            'percent' => new external_value(PARAM_FLOAT, 'Percent'),
            'duration' => new external_value(PARAM_INT, 'Seconds'),
            'attemptsleft' => new external_value(PARAM_INT, 'Test attempts left, -1 unlimited'),
            'best' => new external_value(PARAM_FLOAT, 'Best Test score'),
            'leaderboard' => new external_multiple_structure(new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'Rank'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'percent' => new external_value(PARAM_FLOAT, 'Percent'),
                'duration' => new external_value(PARAM_INT, 'Seconds'),
                'me' => new external_value(PARAM_BOOL, 'Current user'),
            ])),
            'review' => new external_multiple_structure(new external_single_structure([
                'title' => new external_value(PARAM_TEXT, 'Scene'),
                'image' => new external_value(PARAM_URL, 'Scene picture, empty when none'),
                'correct' => new external_value(PARAM_INT, 'Phrases matched correctly'),
                'total' => new external_value(PARAM_INT, 'Phrases'),
                'rows' => new external_multiple_structure(new external_single_structure([
                    'number' => new external_value(PARAM_INT, 'Number'),
                    'text' => new external_value(PARAM_TEXT, 'Phrase'),
                    'translation' => new external_value(PARAM_TEXT, 'Meaning'),
                    'match' => new external_value(PARAM_BOOL, 'Matched correctly'),
                    'chosen' => new external_value(PARAM_TEXT, 'Phrase placed there'),
                    'haslisten' => new external_value(PARAM_BOOL, 'Listening stage used'),
                    'listen' => new external_value(PARAM_BOOL, 'Listening correct'),
                    'hasspeak' => new external_value(PARAM_BOOL, 'Speaking stage used'),
                    'speak' => new external_value(PARAM_INT, 'Speaking score, -1 none'),
                    'speakpass' => new external_value(PARAM_BOOL, 'Speaking passed'),
                ])),
            ])),
            'practice' => new external_single_structure([
                'mastered' => new external_value(PARAM_INT, 'Phrases mastered'),
                'needspractice' => new external_value(PARAM_INT, 'Phrases to practise again'),
                'total' => new external_value(PARAM_INT, 'Phrases'),
                'avgscore' => new external_value(PARAM_INT, 'Average best speaking score, -1 none'),
                'hints' => new external_value(PARAM_INT, 'Hints used'),
            ]),
        ]);
    }
}
