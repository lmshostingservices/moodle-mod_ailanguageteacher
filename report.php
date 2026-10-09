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

/**
 * Reports: learners' mastery, phrase difficulty and attempts.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use mod_ailanguageteacher\local\learning;
use mod_ailanguageteacher\local\manager;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'learners', PARAM_ALPHA);
$kind = optional_param('kind', 'test', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:viewreports', $context);
if (!in_array($tab, ['learners', 'phrases', 'attempts'], true)) {
    $tab = 'learners';
}
if (!in_array($kind, ['test', 'practice'], true)) {
    $kind = 'test';
}
$c = 'mod_ailanguageteacher';
$str = fn($k, $a = null) => get_string($k, $c, $a);

$baseurl = new moodle_url('/mod/ailanguageteacher/report.php', ['id' => $cm->id, 'tab' => $tab, 'kind' => $kind]);
$PAGE->set_url($baseurl, ['page' => $page]);
$PAGE->set_title(format_string($instance->name) . ': ' . $str('reports'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
if ($node = $PAGE->settingsnav->find('ailanguageteacher_reports', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

// Which learners this teacher may see: the chosen group, and only their own groups in separate-groups mode.
$groupmode = groups_get_activity_groupmode($cm);
$currentgroup = groups_get_activity_group($cm, true);
$allgroups = has_capability('moodle/site:accessallgroups', $context);
$visibleusers = null;
if ($groupmode && $currentgroup) {
    $visibleusers = array_keys(groups_get_members($currentgroup, 'u.id'));
} else if ($groupmode == SEPARATEGROUPS && !$allgroups) {
    $visibleusers = [];
    foreach (groups_get_all_groups($course->id, $USER->id, $cm->groupingid) as $group) {
        $visibleusers = array_merge($visibleusers, array_keys(groups_get_members($group->id, 'u.id')));
    }
    $visibleusers = array_values(array_unique($visibleusers));
}
$userfilter = function (string $column) use ($DB, $visibleusers): array {
    if ($visibleusers === null) {
        return ['', []];
    }
    if (!$visibleusers) {
        return [" AND 1 = 0", []];
    }
    [$insql, $params] = $DB->get_in_or_equal($visibleusers, SQL_PARAMS_NAMED, 'vu');
    return [" AND $column $insql", $params];
};
$canmanage = has_capability('mod/ailanguageteacher:manage', $context);

// Attempt management.
if ($action === 'delete' && $canmanage) {
    require_sesskey();
    $ids = optional_param_array('attemptids', [], PARAM_INT);
    if ($ids) {
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['aid'] = $instance->id;
        $attempts = $DB->get_records_select('ailanguageteacher_attempt', "id $insql AND ailanguageteacherid = :aid", $params);
        $users = [];
        foreach ($attempts as $a) {
            $DB->delete_records('ailanguageteacher_response', ['attemptid' => $a->id]);
            $DB->delete_records('ailanguageteacher_attempt', ['id' => $a->id]);
            $users[$a->userid] = true;
        }
        $completion = new completion_info($course);
        foreach (array_keys($users) as $uid) {
            ailanguageteacher_update_grades($instance, $uid);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $uid);
            }
        }
        redirect($baseurl, $str('attemptsdeleted', count($attempts)), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($baseurl);
}
if ($action === 'regrade' && $canmanage) {
    require_sesskey();
    ailanguageteacher_update_grades($instance);
    redirect($baseurl, $str('regraded'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$identity = \core_user\fields::for_identity($context, false)->with_name()->excluding('id');
$userselect = $identity->get_sql('u', true);
$identityfields = \core_user\fields::for_identity($context, false)->get_required_fields();
$fmtpct = fn($v) => $v === null ? '–' : round((float)$v) . '%';

$data = [
    'tabs' => [],
    'learners' => $tab === 'learners',
    'phrases' => $tab === 'phrases',
    'attempts' => $tab === 'attempts',
    'groupselector' => groups_print_activity_menu($cm, $baseurl, true),
    'identityheaders' => array_map(fn($f) => ['name' => \core_user\fields::get_display_name($f)], $identityfields),
];
foreach (['learners', 'phrases', 'attempts'] as $t) {
    $data['tabs'][] = ['name' => $str('report_' . $t), 'url' => (new moodle_url($baseurl, ['tab' => $t]))->out(false),
        'active' => $t === $tab];
}

$ready = manager::ready_scenes($instance, $context);
$phraseids = [];
$phrasemeta = [];
foreach ($ready as [$scene, $pins]) {
    foreach ($pins as $n => $p) {
        $phraseids[] = (int)$p->id;
        $phrasemeta[$p->id] = ['scene' => format_string($scene->title, true, ['context' => $context]),
            'number' => $n + 1, 'text' => format_string($p->text, true, ['context' => $context])];
    }
}
$totalphrases = count($phraseids);

if ($tab === 'learners') {
    [$esql, $eparams] = get_enrolled_sql($context, 'mod/ailanguageteacher:attempt', $currentgroup ?: 0, true);
    [$ufsql, $ufparams] = $userfilter('u.id');
    $from = "FROM {user} u JOIN ($esql) e ON e.id = u.id {$userselect->joins} WHERE u.deleted = 0 $ufsql";
    $params = $eparams + $ufparams + $userselect->params;
    $count = $DB->count_records_sql("SELECT COUNT(1) $from", $params);
    $sql = "SELECT u.id {$userselect->selects} $from ORDER BY u.lastname, u.firstname, u.id";

    // Report rows for one page of learners.
    $learnerrows = function (
        int $offset,
        int $limit
    ) use (
        $DB,
        $sql,
        $params,
        $instance,
        $identityfields,
        $fmtpct,
        $totalphrases,
        $course
): array {
        $users = $DB->get_records_sql($sql, $params, $offset, $limit);
        if (!$users) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'lu');
        $inparams['aid'] = $instance->id;
        $progress = $DB->get_records_sql(
            "SELECT userid, SUM(CASE WHEN state = :mastered THEN 1 ELSE 0 END) AS mastered,
                    SUM(CASE WHEN state = :needs THEN 1 ELSE 0 END) AS needs, SUM(hints) AS hints,
                    AVG(bestscore) AS avgscore, SUM(speaktries) AS speaktries, MAX(timemodified) AS lastseen
               FROM {ailanguageteacher_progress}
              WHERE ailanguageteacherid = :aid AND userid $insql
           GROUP BY userid",
            $inparams + ['mastered' => learning::STATE_MASTERED, 'needs' => learning::STATE_NEEDSPRACTICE]
        );
        $tests = $DB->get_records_sql(
            "SELECT userid, MAX(grade) AS best, COUNT(1) AS attempts
               FROM {ailanguageteacher_attempt}
              WHERE ailanguageteacherid = :aid AND userid $insql AND kind = :kind AND state = :state
           GROUP BY userid",
            $inparams + ['kind' => 'test', 'state' => 'finished']
        );
        $studied = $DB->get_records_sql(
            "SELECT userid, MIN(timefinish) AS studied
               FROM {ailanguageteacher_attempt}
              WHERE ailanguageteacherid = :aid AND userid $insql AND kind = :kind AND state = :state
           GROUP BY userid",
            $inparams + ['kind' => 'study', 'state' => 'finished']
        );
        $rows = [];
        foreach ($users as $u) {
            $p = $progress[$u->id] ?? null;
            $t = $tests[$u->id] ?? null;
            $rows[] = [
                'user' => $u,
                'fullname' => fullname($u),
                'profileurl' => (new moodle_url('/user/view.php', ['id' => $u->id, 'course' => $course->id]))->out(false),
                'identity' => array_map(fn($f) => ['value' => $u->$f ?? ''], $identityfields),
                'studied' => isset($studied[$u->id]),
                'mastered' => (int)($p->mastered ?? 0),
                'total' => $totalphrases,
                'masterpct' => $totalphrases ? round((int)($p->mastered ?? 0) / $totalphrases * 100) : 0,
                'tone' => \mod_ailanguageteacher\local\learning::bar_tone(
                    $totalphrases ? (int)($p->mastered ?? 0) / $totalphrases * 100 : 0,
                    $instance
                ),
                'needs' => (int)($p->needs ?? 0),
                'avgscore' => $fmtpct($p->avgscore ?? null),
                'speaktries' => (int)($p->speaktries ?? 0),
                'hints' => (int)($p->hints ?? 0),
                'testbest' => $t ? $fmtpct($t->best) : '–',
                'testattempts' => $t ? (int)$t->attempts : 0,
                'lastseen' => !empty($p->lastseen) ? userdate($p->lastseen, get_string(
                    'strftimedatetimeshort',
                    'langconfig'
                )) : '–',
            ];
        }
        return $rows;
    };
    if ($download) {
        $columns = ['fullname' => get_string('fullname')];
        foreach ($identityfields as $f) {
            $columns[$f] = \core_user\fields::get_display_name($f);
        }
        $columns += ['studied' => $str('col_studied'), 'mastered' => $str('col_mastered'), 'needs' => $str('col_needs'),
            'avgscore' => $str('col_avgscore'), 'speaktries' => $str('col_speaktries'), 'hints' => $str('col_hints'),
            'testbest' => $str('col_testbest'), 'lastseen' => $str('col_lastseen')];
        // Streams learners in chunks so large courses are never loaded at once.
        $generator = (function () use ($learnerrows, $identityfields) {
            for ($offset = 0; ($chunk = $learnerrows($offset, 500)); $offset += 500) {
                foreach ($chunk as $r) {
                    $line = ['fullname' => $r['fullname']];
                    foreach ($identityfields as $f) {
                        $line[$f] = $r['user']->$f ?? '';
                    }
                    yield $line + ['studied' => $r['studied'] ? get_string('yes') : get_string('no'),
                        'mastered' => $r['mastered'] . '/' . $r['total'], 'needs' => $r['needs'],
                        'avgscore' => $r['avgscore'], 'speaktries' => $r['speaktries'], 'hints' => $r['hints'],
                        'testbest' => $r['testbest'], 'lastseen' => $r['lastseen']];
                }
            }
        })();
        \core\dataformat::download_data('ailanguageteacher-learners', $download, $columns, $generator);
        exit;
    }
    $rows = array_map(function ($r) {
        unset($r['user']);
        return $r;
    }, $learnerrows($page * $perpage, $perpage));
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
    $data['paging'] = $OUTPUT->paging_bar($count, $page, $perpage, $baseurl);
    $data['downloads'] = $OUTPUT->download_dataformat_selector(
        get_string('downloadas', 'table'),
        $baseurl->out_omit_querystring(),
        'download',
        ['id' => $cm->id, 'tab' => $tab]
    );
}

if ($tab === 'phrases') {
    $rows = [];
    if ($phraseids) {
        [$usql, $uparams] = $userfilter('pr.userid');
        [$insql, $inparams] = $DB->get_in_or_equal($phraseids, SQL_PARAMS_NAMED, 'ph');
        $params = $inparams + $uparams + ['mastered' => learning::STATE_MASTERED, 'needs' => learning::STATE_NEEDSPRACTICE];
        $practice = $DB->get_records_sql(
            "SELECT pr.phraseid, COUNT(1) AS learners,
                    SUM(CASE WHEN pr.state = :mastered THEN 1 ELSE 0 END) AS mastered,
                    SUM(CASE WHEN pr.state = :needs THEN 1 ELSE 0 END) AS needs,
                    AVG(CASE WHEN pr.matchtries > 0 THEN pr.matchtries END) AS matchtries,
                    AVG(pr.hints) AS hints, AVG(pr.bestscore) AS bestscore, AVG(pr.speaktries) AS speaktries
               FROM {ailanguageteacher_progress} pr
              WHERE pr.phraseid $insql $usql
           GROUP BY pr.phraseid",
            $params
        );
        [$usql2, $uparams2] = $userfilter('a.userid');
        [$insql2, $inparams2] = $DB->get_in_or_equal($phraseids, SQL_PARAMS_NAMED, 'tp');
        $test = $DB->get_records_sql(
            "SELECT r.phraseid,
                    AVG(CASE WHEN r.stage = 'match' THEN r.correct END) AS matchok,
                    AVG(CASE WHEN r.stage = 'listen' THEN r.correct END) AS listenok,
                    AVG(CASE WHEN r.stage = 'speak' THEN r.score END) AS speakscore
               FROM {ailanguageteacher_response} r
               JOIN {ailanguageteacher_attempt} a ON a.id = r.attemptid
              WHERE a.ailanguageteacherid = :aid AND a.kind = :kind AND r.phraseid $insql2 $usql2
           GROUP BY r.phraseid",
            $inparams2 + $uparams2 + ['aid' => $instance->id, 'kind' => 'test']
        );
        foreach ($phraseids as $pid) {
            $p = $practice[$pid] ?? null;
            $t = $test[$pid] ?? null;
            $matchok = $t && $t->matchok !== null ? round($t->matchok * 100) : null;
            $rows[] = $phrasemeta[$pid] + [
                'learners' => (int)($p->learners ?? 0),
                'mastered' => (int)($p->mastered ?? 0),
                'needs' => (int)($p->needs ?? 0),
                'matchtries' => $p && $p->matchtries !== null ? format_float($p->matchtries, 1) : '–',
                'hints' => $p ? format_float((float)$p->hints, 1) : '–',
                'bestscore' => $fmtpct($p->bestscore ?? null),
                'speaktries' => $p ? format_float((float)$p->speaktries, 1) : '–',
                'testmatch' => $matchok === null ? '–' : $matchok . '%',
                'testlisten' => $t && $t->listenok !== null ? round($t->listenok * 100) . '%' : '–',
                'testspeak' => $fmtpct($t->speakscore ?? null),
                'hard' => $matchok !== null && $matchok < 60,
            ];
        }
    }
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
}

if ($tab === 'attempts') {
    [$ufsql, $ufparams] = $userfilter('a.userid');
    $where = "a.ailanguageteacherid = :aid AND a.kind = :kind AND u.deleted = 0 $ufsql";
    $params = ['aid' => $instance->id, 'kind' => $kind] + $ufparams;
    $count = $DB->count_records_sql("SELECT COUNT(1) FROM {ailanguageteacher_attempt} a JOIN {user} u ON u.id = a.userid
        WHERE $where", $params);
    $params += $userselect->params;
    $sql = "SELECT a.id, a.userid, a.attempt, a.state, a.timestart, a.duration, a.correct, a.total, a.grade
                   {$userselect->selects}
              FROM {ailanguageteacher_attempt} a
              JOIN {user} u ON u.id = a.userid
                   {$userselect->joins}
             WHERE $where
          ORDER BY u.lastname, u.firstname, a.attempt, a.id";
    $format = fn($a) => [
        'id' => (int)$a->id,
        'fullname' => fullname($a),
        'identity' => array_map(fn($f) => ['value' => $a->$f ?? ''], $identityfields),
        'attempt' => (int)$a->attempt,
        'state' => $str('state_' . $a->state),
        'started' => userdate($a->timestart, get_string('strftimedatetimeshort', 'langconfig')),
        'duration' => format_time((int)$a->duration),
        'score' => format_float((float)$a->correct, 1) . ' / ' . (int)$a->total,
        'grade' => $a->grade === null ? '–' : round((float)$a->grade, 1) . '%',
    ];
    if ($download) {
        $columns = ['fullname' => get_string('fullname')];
        foreach ($identityfields as $f) {
            $columns[$f] = \core_user\fields::get_display_name($f);
        }
        $columns += ['attempt' => $str('col_attempt'), 'state' => $str('col_state'), 'started' => $str('col_started'),
            'duration' => $str('col_duration'), 'score' => $str('col_score'), 'grade' => get_string('percentage', 'grades')];
        $rs = $DB->get_recordset_sql($sql, $params);
        \core\dataformat::download_data(
            'ailanguageteacher-attempts',
            $download,
            $columns,
            $rs,
            function ($a) use ($format, $identityfields) {
                $r = $format($a);
                $line = ['fullname' => $r['fullname']];
                foreach ($identityfields as $f) {
                    $line[$f] = $a->$f ?? '';
                }
                return $line + ['attempt' => $r['attempt'], 'state' => $r['state'], 'started' => $r['started'],
                    'duration' => $r['duration'], 'score' => $r['score'], 'grade' => $r['grade']];
            }
        );
        $rs->close();
        exit;
    }
    $rows = array_map($format, array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage)));
    $data['rows'] = $rows;
    $data['hasrows'] = !empty($rows);
    $data['paging'] = $OUTPUT->paging_bar($count, $page, $perpage, $baseurl);
    $data['kinds'] = array_map(fn($k) => ['name' => $str('mode' . $k), 'active' => $k === $kind,
        'url' => (new moodle_url($baseurl, ['kind' => $k]))->out(false)], ['test', 'practice']);
    $data['canmanage'] = $canmanage;
    $data['actionurl'] = $baseurl->out(false);
    $data['sesskey'] = sesskey();
    $data['downloads'] = $OUTPUT->download_dataformat_selector(
        get_string('downloadas', 'table'),
        $baseurl->out_omit_querystring(),
        'download',
        ['id' => $cm->id, 'tab' => $tab, 'kind' => $kind]
    );
}

$PAGE->requires->js_call_amd('mod_ailanguageteacher/report', 'init', ['#lt-report']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/report', $data);
echo $OUTPUT->footer();
