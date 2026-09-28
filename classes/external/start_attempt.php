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
 * Starts a Practice or Test attempt.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_attempt extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'kind' => new external_value(PARAM_ALPHA, 'practice or test'),
            'canlisten' => new external_value(PARAM_BOOL, 'The browser can play or speak audio', VALUE_DEFAULT, true),
            'canspeak' => new external_value(PARAM_BOOL, 'The browser can record or recognise speech', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Starts the attempt.
     *
     * @param int $cmid
     * @param string $kind
     * @param bool $canlisten
     * @param bool $canspeak
     * @return array
     */
    public static function execute(int $cmid, string $kind, bool $canlisten = true, bool $canspeak = true): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'kind' => $kind,
            'canlisten' => $canlisten, 'canspeak' => $canspeak]);
        [$course, $cm, $instance, $context] = self::load_cm($params['cmid'], 'attempt');
        return learning::start_attempt(
            $instance,
            $cm,
            $course,
            $context,
            $params['kind'],
            (int)$USER->id,
            $params['canlisten'],
            $params['canspeak']
        );
    }

    /**
     * Phrase card fields.
     *
     * @return array
     */
    public static function detail_fields(): array {
        return [
            'phraseid' => new external_value(PARAM_INT, 'Phrase id'),
            'text' => new external_value(PARAM_TEXT, 'Phrase'),
            'romanisation' => new external_value(PARAM_TEXT, 'Romanisation'),
            'translation' => new external_value(PARAM_TEXT, 'Meaning in the learners language'),
            'usagenote' => new external_value(PARAM_TEXT, 'When to use it (plain text, may contain line breaks)'),
            'example' => new external_value(PARAM_TEXT, 'Example sentence'),
            'exampletrans' => new external_value(PARAM_TEXT, 'Example meaning'),
            'audio' => new external_value(PARAM_URL, 'Audio URL, empty when it must be created or spoken by the browser'),
            'hasexample' => new external_value(PARAM_BOOL, 'Has an example'),
        ];
    }

    /**
     * Scene fields shared by all modes.
     *
     * @return array
     */
    public static function scene_fields(): array {
        return [
            'id' => new external_value(PARAM_INT, 'Scene id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'situation' => new external_value(PARAM_TEXT, 'Situation'),
            'context' => new external_value(PARAM_TEXT, 'What is happening (plain text, may contain line breaks)'),
            'image' => new external_value(PARAM_URL, 'Picture URL'),
            'width' => new external_value(PARAM_INT, 'Picture width'),
            'height' => new external_value(PARAM_INT, 'Picture height'),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'kind' => new external_value(PARAM_ALPHA, 'Kind'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number'),
            'timelimit' => new external_value(PARAM_INT, 'Time limit in seconds'),
            'stages' => new external_multiple_structure(new external_value(PARAM_ALPHA, 'Test stage')),
            'total' => new external_value(PARAM_INT, 'Items'),
            'scenes' => new external_multiple_structure(new external_single_structure(self::scene_fields() + [
                'pins' => new external_multiple_structure(new external_single_structure([
                    'token' => new external_value(PARAM_ALPHANUM, 'Pin token'),
                    'x' => new external_value(PARAM_FLOAT, 'X percent'),
                    'y' => new external_value(PARAM_FLOAT, 'Y percent'),
                    'number' => new external_value(PARAM_INT, 'Number'),
                ])),
                'chips' => new external_multiple_structure(new external_single_structure([
                    'token' => new external_value(PARAM_ALPHANUM, 'Phrase token'),
                    'text' => new external_value(PARAM_TEXT, 'Phrase'),
                    'romanisation' => new external_value(PARAM_TEXT, 'Romanisation'),
                    'color' => new external_value(PARAM_TEXT, 'Colour'),
                    'translation' => new external_value(PARAM_TEXT, 'Meaning (Practice hints only)', VALUE_OPTIONAL),
                ])),
                'answers' => new external_multiple_structure(new external_single_structure([
                    'pin' => new external_value(PARAM_ALPHANUM, 'Pin token'),
                    'chip' => new external_value(PARAM_ALPHANUM, 'Phrase token'),
                ])),
                'details' => new external_multiple_structure(new external_single_structure(self::detail_fields() + [
                    'token' => new external_value(PARAM_ALPHANUM, 'Phrase token'),
                    'state' => new external_value(PARAM_ALPHA, 'Progress state'),
                    'successes' => new external_value(PARAM_INT, 'Successful speaking tries'),
                    'speaktries' => new external_value(PARAM_INT, 'Speaking tries'),
                    'bestscore' => new external_value(PARAM_INT, 'Best speaking score, -1 none'),
                    'hints' => new external_value(PARAM_INT, 'Hints used'),
                ])),
                'listen' => new external_multiple_structure(new external_single_structure([
                    'token' => new external_value(PARAM_ALPHANUM, 'Listening item token'),
                    'text' => new external_value(PARAM_TEXT, 'Text for the browser voice, empty when audio is served'),
                    'audio' => new external_value(PARAM_URL, 'Audio URL'),
                ])),
                'speak' => new external_multiple_structure(new external_single_structure([
                    'token' => new external_value(PARAM_ALPHANUM, 'Speaking item token'),
                    'pin' => new external_value(PARAM_ALPHANUM, 'Pin token to highlight'),
                    'prompt' => new external_value(PARAM_TEXT, 'Cue in the learners language'),
                ])),
            ])),
        ]);
    }
}
