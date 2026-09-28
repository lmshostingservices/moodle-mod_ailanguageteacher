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

namespace mod_ailanguageteacher\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_ailanguageteacher\local\learning;

/**
 * Privacy provider for mod_ailanguageteacher.
 *
 * Learner recordings are never stored in Moodle. Teacher-initiated phrase text is sent server-to-server
 * to LMS Labs for speech synthesis; lesson briefs are sent for teacher-requested drafting.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables holding learner data, each with ailanguageteacherid and userid. */
    protected const USER_TABLES = ['ailanguageteacher_attempt', 'ailanguageteacher_progress', 'ailanguageteacher_speech',
        'ailanguageteacher_ailog', 'ailanguageteacher_operation'];

    /**
     * Describes stored and transferred personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('ailanguageteacher_attempt', [
            'userid' => 'privacy:metadata:attempt:userid',
            'attempt' => 'privacy:metadata:attempt:attempt',
            'kind' => 'privacy:metadata:attempt:kind',
            'state' => 'privacy:metadata:attempt:state',
            'timestart' => 'privacy:metadata:attempt:timestart',
            'timefinish' => 'privacy:metadata:attempt:timefinish',
            'correct' => 'privacy:metadata:attempt:correct',
            'total' => 'privacy:metadata:attempt:total',
            'grade' => 'privacy:metadata:attempt:grade',
            'duration' => 'privacy:metadata:attempt:duration',
        ], 'privacy:metadata:attempt');
        $collection->add_database_table('ailanguageteacher_response', [
            'phraseid' => 'privacy:metadata:response:phraseid',
            'stage' => 'privacy:metadata:response:stage',
            'answerid' => 'privacy:metadata:response:answerid',
            'score' => 'privacy:metadata:response:score',
            'correct' => 'privacy:metadata:response:correct',
            'tries' => 'privacy:metadata:response:tries',
            'hints' => 'privacy:metadata:response:hints',
            'timecreated' => 'privacy:metadata:response:timecreated',
        ], 'privacy:metadata:response');
        $collection->add_database_table('ailanguageteacher_progress', [
            'userid' => 'privacy:metadata:progress:userid',
            'phraseid' => 'privacy:metadata:progress:phraseid',
            'state' => 'privacy:metadata:progress:state',
            'matchtries' => 'privacy:metadata:progress:matchtries',
            'hints' => 'privacy:metadata:progress:hints',
            'hintlevel' => 'privacy:metadata:progress:hintlevel',
            'speaktries' => 'privacy:metadata:progress:speaktries',
            'successes' => 'privacy:metadata:progress:successes',
            'bestscore' => 'privacy:metadata:progress:bestscore',
            'lastscore' => 'privacy:metadata:progress:lastscore',
            'timestudied' => 'privacy:metadata:progress:timestudied',
            'timemastered' => 'privacy:metadata:progress:timemastered',
            'timemodified' => 'privacy:metadata:progress:timemodified',
        ], 'privacy:metadata:progress');
        $collection->add_database_table('ailanguageteacher_speech', [
            'userid' => 'privacy:metadata:speech:userid',
            'phraseid' => 'privacy:metadata:speech:phraseid',
            'kind' => 'privacy:metadata:speech:kind',
            'provider' => 'privacy:metadata:speech:provider',
            'score' => 'privacy:metadata:speech:score',
            'recognised' => 'privacy:metadata:speech:recognised',
            'passed' => 'privacy:metadata:speech:passed',
            'timecreated' => 'privacy:metadata:speech:timecreated',
        ], 'privacy:metadata:speech');
        $collection->add_database_table('ailanguageteacher_ailog', [
            'userid' => 'privacy:metadata:ailog:userid',
            'action' => 'privacy:metadata:ailog:action',
            'status' => 'privacy:metadata:ailog:status',
            'timecreated' => 'privacy:metadata:ailog:timecreated',
        ], 'privacy:metadata:ailog');
        $collection->add_database_table('ailanguageteacher_operation', [
            'userid' => 'privacy:metadata:operation:userid',
            'body' => 'privacy:metadata:operation:body',
            'result' => 'privacy:metadata:operation:result',
        ], 'privacy:metadata:operation');
        $collection->add_external_location_link('lms-labs.com', [
            'brief' => 'privacy:metadata:operation:body',
            'text' => 'privacy:metadata:operation:body',
        ], 'privacy:metadata:external');
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');
        return $collection;
    }

    /**
     * Contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        foreach (self::USER_TABLES as $table) {
            $sql = "SELECT ctx.id
                      FROM {context} ctx
                      JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {{$table}} t ON t.ailanguageteacherid = cm.instance
                     WHERE t.userid = :userid";
            $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'modname' => 'ailanguageteacher',
                'userid' => $userid]);
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        foreach (self::USER_TABLES as $table) {
            $sql = "SELECT t.userid
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      JOIN {{$table}} t ON t.ailanguageteacherid = cm.instance
                     WHERE cm.id = :cmid";
            $userlist->add_from_sql('userid', $sql, ['modname' => 'ailanguageteacher', 'cmid' => $context->instanceid]);
        }
    }

    /**
     * Exports user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('ailanguageteacher', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $aid = (int)$cm->instance;
            $attempts = [];
            foreach (
                $DB->get_records(
                    'ailanguageteacher_attempt',
                    ['ailanguageteacherid' => $aid, 'userid' => $userid],
                    'kind, attempt'
                ) as $a
            ) {
                $sql = 'SELECT r.id, r.stage, p.text AS phrase, c.text AS chosen, r.score, r.correct, r.tries, r.hints,
                               r.timecreated
                          FROM {ailanguageteacher_response} r
                     LEFT JOIN {ailanguageteacher_phrase} p ON p.id = r.phraseid
                     LEFT JOIN {ailanguageteacher_phrase} c ON c.id = r.answerid
                         WHERE r.attemptid = :attemptid
                      ORDER BY r.id';
                $responses = [];
                foreach ($DB->get_records_sql($sql, ['attemptid' => $a->id]) as $r) {
                    $responses[] = (object)[
                        'stage' => $r->stage,
                        'phrase' => $r->phrase,
                        'chosen' => $r->chosen,
                        'score' => $r->score,
                        'correct' => transform::yesno($r->correct),
                        'tries' => $r->tries,
                        'hints' => $r->hints,
                        'time' => transform::datetime($r->timecreated),
                    ];
                }
                $attempts[] = (object)[
                    'attempt' => $a->attempt,
                    'kind' => $a->kind,
                    'state' => $a->state,
                    'timestart' => transform::datetime($a->timestart),
                    'timefinish' => $a->timefinish ? transform::datetime($a->timefinish) : '-',
                    'correct' => $a->correct,
                    'total' => $a->total,
                    'grade' => $a->grade,
                    'duration' => $a->duration,
                    'responses' => $responses,
                ];
            }
            $progress = [];
            $sql = 'SELECT pr.*, p.text AS phrase
                      FROM {ailanguageteacher_progress} pr
                 LEFT JOIN {ailanguageteacher_phrase} p ON p.id = pr.phraseid
                     WHERE pr.ailanguageteacherid = :aid AND pr.userid = :userid
                  ORDER BY pr.id';
            foreach ($DB->get_records_sql($sql, ['aid' => $aid, 'userid' => $userid]) as $row) {
                $progress[] = (object)[
                    'phrase' => $row->phrase,
                    'state' => $row->state,
                    'matchtries' => $row->matchtries,
                    'hints' => $row->hints,
                    'hintlevel' => $row->hintlevel,
                    'speaktries' => $row->speaktries,
                    'successes' => $row->successes,
                    'bestscore' => $row->bestscore,
                    'lastscore' => $row->lastscore,
                    'timestudied' => $row->timestudied ? transform::datetime($row->timestudied) : '-',
                    'timemastered' => $row->timemastered ? transform::datetime($row->timemastered) : '-',
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }
            $speech = [];
            $sql = 'SELECT s.*, p.text AS phrase
                      FROM {ailanguageteacher_speech} s
                 LEFT JOIN {ailanguageteacher_phrase} p ON p.id = s.phraseid
                     WHERE s.ailanguageteacherid = :aid AND s.userid = :userid
                  ORDER BY s.id';
            foreach ($DB->get_records_sql($sql, ['aid' => $aid, 'userid' => $userid]) as $row) {
                $speech[] = (object)[
                    'phrase' => $row->phrase,
                    'kind' => $row->kind,
                    'provider' => $row->provider,
                    'score' => $row->score,
                    'recognised' => $row->recognised,
                    'passed' => transform::yesno($row->passed),
                    'time' => transform::datetime($row->timecreated),
                ];
            }
            $ailog = [];
            $operations = [];
            foreach ($DB->get_records('ailanguageteacher_operation',
                    ['ailanguageteacherid' => $aid, 'userid' => $userid], 'id') as $row) {
                $operations[] = (object)['kind' => $row->kind, 'state' => $row->state,
                    'body' => $row->body, 'result' => $row->result,
                    'time' => transform::datetime($row->timecreated)];
            }
            foreach (
                $DB->get_records(
                    'ailanguageteacher_ailog',
                    ['ailanguageteacherid' => $aid, 'userid' => $userid],
                    'id'
                ) as $row
            ) {
                $ailog[] = (object)['action' => $row->action, 'status' => $row->status,
                    'time' => transform::datetime($row->timecreated)];
            }
            if (!$attempts && !$progress && !$speech && !$ailog && !$operations) {
                continue;
            }
            $contextdata = helper::get_context_data($context, $user);
            $contextdata->attempts = $attempts;
            $contextdata->progress = $progress;
            $contextdata->speaking = $speech;
            $contextdata->aigeneration = $ailog;
            $contextdata->outboundrequests = $operations;
            writer::with_context($context)->export_data([], $contextdata);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('ailanguageteacher', $context->instanceid);
        if ($cm) {
            learning::delete_all_user_data((int)$cm->instance);
            $DB->delete_records('ailanguageteacher_ailog', ['ailanguageteacherid' => $cm->instance]);
            $DB->delete_records('ailanguageteacher_operation', ['ailanguageteacherid' => $cm->instance]);
        }
    }

    /**
     * Deletes one user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('ailanguageteacher', $context->instanceid);
            if ($cm) {
                learning::delete_user_data_for_user((int)$cm->instance, $userid);
                $GLOBALS['DB']->delete_records('ailanguageteacher_operation',
                    ['ailanguageteacherid' => $cm->instance, 'userid' => $userid]);
            }
        }
    }

    /**
     * Deletes data for several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('ailanguageteacher', $context->instanceid);
        if (!$cm) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            learning::delete_user_data_for_user((int)$cm->instance, (int)$userid);
            $GLOBALS['DB']->delete_records('ailanguageteacher_operation',
                ['ailanguageteacherid' => $cm->instance, 'userid' => (int)$userid]);
        }
    }
}
