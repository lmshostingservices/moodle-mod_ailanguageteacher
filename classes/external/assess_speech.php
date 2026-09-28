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
use moodle_exception;

/**
 * Scores the learner saying a phrase. The recording is scored and then discarded; only the scores are kept.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assess_speech extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'kind' => new external_value(PARAM_ALPHA, 'study, practice or test'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id (practice and test)', VALUE_DEFAULT, 0),
            'ref' => new external_value(PARAM_ALPHANUM, 'Phrase reference or token'),
            'audio' => new external_value(PARAM_BASE64, 'WAV recording, base64 (site speech service)', VALUE_DEFAULT, ''),
            'transcripts' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'What the browser heard'),
                'Browser recognition results, best first',
                VALUE_DEFAULT,
                []
            ),
            'selfcheck' => new external_value(PARAM_BOOL, 'Learner compared their own recording', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Scores.
     *
     * @param int $cmid
     * @param string $kind
     * @param int $attemptid
     * @param string $ref
     * @param string $audio
     * @param array $transcripts
     * @param bool $selfcheck
     * @return array
     */
    public static function execute(
        int $cmid,
        string $kind,
        int $attemptid,
        string $ref,
        string $audio = '',
        array $transcripts = [],
        bool $selfcheck = false
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'kind' => $kind,
            'attemptid' => $attemptid, 'ref' => $ref, 'audio' => $audio, 'transcripts' => $transcripts,
            'selfcheck' => $selfcheck]);
        [$course, $cm, $instance, $context] = self::load_cm($params['cmid'], 'attempt');
        $attempt = null;
        if ($params['kind'] !== 'study') {
            $attempt = learning::get_user_attempt($params['attemptid'], (int)$USER->id);
            if ((int)$attempt->ailanguageteacherid !== (int)$instance->id) {
                throw new moodle_exception('notyourattempt', 'mod_ailanguageteacher');
            }
        }
        $wav = $params['audio'] !== '' ? (string)base64_decode($params['audio'], true) : '';
        $transcripts = array_slice(array_values(array_filter(array_map('trim', $params['transcripts']))), 0, 5);
        return learning::assess(
            $instance,
            $cm,
            $course,
            $context,
            (int)$USER->id,
            $params['kind'],
            $attempt,
            $params['ref'],
            $wav,
            $transcripts,
            $params['selfcheck']
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'provider' => new external_value(PARAM_ALPHA, 'service, browser or self'),
            'status' => new external_value(PARAM_ALPHA, 'ok, nospeech or unintelligible'),
            'counted' => new external_value(PARAM_BOOL, 'The try counted (silence and unclear speech do not)'),
            'score' => new external_value(PARAM_INT, 'How closely the recognised words matched, 0-100, -1 none'),
            'recognised' => new external_value(PARAM_TEXT, 'What was recognised'),
            'found' => new external_value(PARAM_INT, 'Words (or characters) of the phrase recognised'),
            'total' => new external_value(PARAM_INT, 'Words (or characters) in the phrase'),
            'unit' => new external_value(PARAM_ALPHA, 'word or char: what found and total count'),
            'units' => new external_multiple_structure(new external_single_structure([
                'text' => new external_value(PARAM_TEXT, 'Word or character of the phrase'),
                'ok' => new external_value(PARAM_BOOL, 'It was recognised'),
            ])),
            'passed' => new external_value(PARAM_BOOL, 'Reached the pass score'),
            'passscore' => new external_value(PARAM_INT, 'Pass score'),
            'successes' => new external_value(PARAM_INT, 'Successful tries so far'),
            'required' => new external_value(PARAM_INT, 'Successful tries needed'),
            'mastered' => new external_value(PARAM_BOOL, 'Phrase mastered'),
            'canmoveon' => new external_value(PARAM_BOOL, 'Learner may move on without mastering'),
            'triesleft' => new external_value(PARAM_INT, 'Test tries left'),
        ]);
    }
}
