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
use mod_ailanguageteacher\local\ai\lmslabs;
use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\operation;
use moodle_exception;

/**
 * Creates scenes and phrases from a lesson draft.
 *
 * A draft LMS Labs AI delivered (already charged) is created free, once. Scenes written with the teacher's own AI
 * assistant are charged like LMS Labs drafts, 3 credits per scene, and created only after LMS Labs confirms.
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
            'draftid' => new external_value(
                PARAM_INT,
                'The delivered LMS Labs draft this comes from (0: own AI assistant)',
                VALUE_DEFAULT,
                0
            ),
            'discard' => new external_value(
                PARAM_BOOL,
                'The teacher confirmed abandoning an unfinished request',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Imports.
     *
     * @param int $cmid
     * @param string $draft
     * @param int $draftid
     * @param bool $discard
     * @return array
     */
    public static function execute(int $cmid, string $draft, int $draftid = 0, bool $discard = false): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'draft' => $draft, 'draftid' => $draftid, 'discard' => $discard]
        );
        [, , $instance, $context] = self::load_cm($params['cmid'], 'manage');
        $data = lesson::clean(lesson::parse($params['draft']));
        if (!$data['scenes']) {
            throw new moodle_exception('lessonempty', 'mod_ailanguageteacher');
        }
        if ($params['draftid']) {
            // A delivered LMS Labs draft: already paid for, created once, with no more scenes than it had.
            $op = $DB->get_record('ailanguageteacher_operation', ['id' => $params['draftid'],
                'ailanguageteacherid' => $instance->id, 'userid' => $USER->id, 'kind' => 'lesson', 'state' => 'complete']);
            $delivered = $op ? count(json_decode((string)$op->result, true)['scenes'] ?? []) : 0;
            if (!$op || count($data['scenes']) > $delivered) {
                throw new moodle_exception('draftused', 'mod_ailanguageteacher');
            }
            $result = lesson::import($instance, $data);
            $DB->update_record('ailanguageteacher_operation', (object)['id' => $op->id, 'state' => 'imported',
                'body' => '', 'result' => null]);
            return $result + ['charged' => 0, 'balance' => -1];
        }
        // The teacher's own AI assistant: 3 credits per scene, confirmed by LMS Labs before anything is created.
        require_capability('mod/ailanguageteacher:useai', $context);
        if (\mod_ailanguageteacher\local\credentials::find() === null) {
            throw new moodle_exception('ainotavailable', 'mod_ailanguageteacher');
        }
        $titles = [];
        foreach (array_values($data['scenes']) as $i => $scene) {
            $title = trim(\core_text::substr(str_replace(['<', '>'], '', (string)$scene['title']), 0, 200));
            // LMS Labs needs a title with text for every scene.
            $titles[] = $title !== '' ? $title : get_string('scenex', 'mod_ailanguageteacher', $i + 1);
        }
        $body = ['sceneCount' => count($data['scenes']), 'titles' => $titles];
        $op = operation::claim((int)$instance->id, (int)$USER->id, 'import', 0, $body, false, $params['discard']);
        $charge = (new lmslabs())->charge_import($body, $op);
        $transaction = $DB->start_delegated_transaction();
        $result = lesson::import($instance, $data);
        operation::complete($op, json_encode($charge));
        $transaction->allow_commit();
        lesson::log_ai((int)$instance->id, (int)$USER->id, 'import', 'ok');
        return $result + ['charged' => $charge['charged'], 'balance' => $charge['balance'] ?? -1];
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
            'charged' => new external_value(PARAM_INT, 'LMS Labs credits charged for these scenes'),
            'balance' => new external_value(PARAM_INT, 'LMS Labs credits left (-1: not reported)'),
        ]);
    }
}
