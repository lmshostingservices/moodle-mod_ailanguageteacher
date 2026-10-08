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
 * Activity home: the learning journey and the player.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ailanguageteacher\local\audio;
use mod_ailanguageteacher\local\languages;
use mod_ailanguageteacher\local\learning;
use mod_ailanguageteacher\local\manager;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:view', $context);

$PAGE->set_url('/mod/ailanguageteacher/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

$canmanage = has_capability('mod/ailanguageteacher:manage', $context);

// The whole plugin needs this site to be unlocked with LMS Labs (50 credits or a recognised Marketplace purchase).
if (!\mod_ailanguageteacher\local\unlock::active()) {
    echo $OUTPUT->header();
    echo \mod_ailanguageteacher\local\unlock::locked_notice($canmanage);
    echo $OUTPUT->footer();
    exit;
}

$ready = manager::ready_scenes($instance, $context);

// A new activity with nothing in it: take the teacher straight to the lesson builder.
if (!$ready && $canmanage && !manager::get_scenes($instance->id)) {
    redirect(new moodle_url('/mod/ailanguageteacher/builder.php', ['id' => $cm->id]));
}

manager::view($instance, $course, $cm, $context);

$userid = (int)$USER->id;
$canattempt = has_capability('mod/ailanguageteacher:attempt', $context) && !isguestuser();
$phrasecount = 0;
foreach ($ready as [$scene, $pins]) {
    $phrasecount += count($pins);
}
$summary = learning::user_summary($instance, $userid);
$counts = $canattempt ? learning::practice_counts($instance, $context, $userid) : ['total' => $phrasecount,
    'mastered' => 0, 'needspractice' => 0, 'done' => 0];
$str = fn($k, $a = null) => get_string($k, 'mod_ailanguageteacher', $a);

// Pass mark from the gradebook (Grade to pass), as a percentage.
$passpercent = 0;
if ($instance->grade > 0) {
    require_once($CFG->libdir . '/gradelib.php');
    $gradeitem = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'ailanguageteacher',
        'iteminstance' => $instance->id, 'courseid' => $course->id, 'itemnumber' => 0]);
    if ($gradeitem && $gradeitem->gradepass > 0 && $gradeitem->grademax > 0) {
        $passpercent = round($gradeitem->gradepass / $gradeitem->grademax * 100, 1);
    }
}

$studied = $canattempt && learning::has_studied((int)$instance->id, $userid);
$practised = $counts['total'] > 0 && $counts['done'] >= $counts['total'];
$testpassed = $summary['best'] !== null && (!$passpercent || $summary['best'] >= $passpercent);
$compare = function (array $values) use ($str): array {
    $rows = [];
    foreach ($values as $key => [$yes, $text]) {
        $rows[] = ['label' => $str('cmp_' . $key), 'text' => $text, 'yes' => $yes];
    }
    return $rows;
};
$goal = $instance->repetitions > 1
    ? $str('goal_times', ['score' => $instance->passscore, 'times' => $instance->repetitions])
    : $str('goal_once', $instance->passscore);
$testattempts = $instance->maxattempts ? $str('cmp_attempts_n', $instance->maxattempts) : $str('cmp_attempts_unlimited');
$stages = [$str('stage_match')];
if ($instance->testlistening) {
    $stages[] = $str('stage_listen');
}
if ($instance->testspeaking && audio::speaking_mode($instance->targetlocale) !== 'self') {
    $stages[] = $str('stage_speak');
}

$modes = [];
$step = 0;
if ($instance->allowstudy) {
    $modes[] = ['key' => 'study', 'isstudy' => true, 'step' => ++$step, 'enabled' => (bool)$ready,
        'title' => $str('modestudy'), 'bestfor' => $str('bestfor_study'),
        'rows' => $compare([
            'phrases' => [true, $str('cmp_phrases_shown')],
            'listen' => [true, $str('cmp_listen_any')],
            'speak' => [true, $str('cmp_speak_try')],
            'graded' => [false, $str('cmp_no')],
        ]),
        'done' => $studied, 'status' => $studied ? $str('status_studied') : null];
}
if ($instance->allowpractice) {
    $locked = $canattempt && learning::is_locked($instance, $context, 'practice', $userid);
    $modes[] = ['key' => 'practice', 'ispractice' => true, 'step' => ++$step,
        'enabled' => $ready && $canattempt && !$locked, 'locked' => $locked, 'lockedtext' => $str('locked_afterstudy'),
        'title' => $str('modepractice'), 'bestfor' => $str('bestfor_practice'),
        'rows' => $compare([
            'phrases' => [true, $str('cmp_phrases_you')],
            'listen' => [true, $str('cmp_listen_after')],
            'speak' => [(bool)$instance->speaking, $instance->speaking ? $goal : $str('cmp_speak_off')],
            'hints' => [true, $str('cmp_hints_yes')],
        ]),
        'meta' => $counts['done'] ? $str('masteredof', ['done' => $counts['mastered'], 'total' => $counts['total']]) : '',
        'done' => $practised, 'status' => $practised ? $str('status_practised') : null];
}
if ($instance->allowtest) {
    $locked = $canattempt && learning::is_locked($instance, $context, 'test', $userid);
    $meta = [];
    if ($instance->maxattempts) {
        $meta[] = $str('attemptsused', ['used' => $summary['attemptsused'], 'max' => $instance->maxattempts]);
    }
    if ($summary['best'] !== null) {
        $meta[] = $str('bestscore', $summary['best']);
    }
    $modes[] = ['key' => 'test', 'istest' => true, 'step' => ++$step,
        'enabled' => $ready && $canattempt && !$locked && $summary['attemptsleft'] !== 0,
        'locked' => $locked, 'lockedtext' => $instance->allowpractice ? $str('locked_afterpractice') : $str('locked_afterstudy'),
        'title' => $str('modetest'), 'bestfor' => $str('bestfor_test'),
        'rows' => $compare([
            'stages' => [true, implode(' · ', $stages)],
            'hints' => [false, $str('cmp_hints_no')],
            'time' => [true, $instance->timelimit ? $str('cmp_time_limit', format_time($instance->timelimit))
                : $str('cmp_time_none')],
            'graded' => [$instance->grade != 0, $instance->grade != 0 ? $str('cmp_graded_yes', $testattempts) : $str('cmp_no')],
        ]),
        'meta' => implode(' · ', $meta),
        'nomore' => $summary['attemptsleft'] === 0 && !$testpassed,
        'graded' => $instance->grade != 0 && $summary['best'] === null,
        'done' => $testpassed,
        'status' => $testpassed ? $str($passpercent ? 'status_passed' : 'status_completed') : null,
        'warn' => ($summary['best'] !== null && !$testpassed) ? $str('status_notpassed', $passpercent) : null];
}

// Completion requirements, shown on the start screens.
$completionrules = [];
$cminfo = get_fast_modinfo($course)->get_cm($cm->id);
if ($cminfo->completion == COMPLETION_TRACKING_AUTOMATIC && !isguestuser()) {
    $details = \core_completion\cm_completion_details::get_instance($cminfo, $userid);
    foreach ($details->get_details() as $rule => $detail) {
        if ($rule !== 'completionview') {
            $completionrules[] = ['rule' => $rule, 'text' => $detail->description];
        }
    }
}

$config = [
    'cmid' => (int)$cm->id,
    'canattempt' => $canattempt,
    'passpercent' => $passpercent,
    'graded' => $instance->grade != 0,
    'maxattempts' => (int)$instance->maxattempts,
    'attemptsleft' => (int)$summary['attemptsleft'],
    'timelimit' => (int)$instance->timelimit,
    'timelimittext' => $instance->timelimit ? format_time($instance->timelimit) : '',
    'scenecount' => count($ready),
    'phrasecount' => $phrasecount,
    'completion' => $completionrules,
    'allowtest' => (int)$instance->allowtest,
    'sounds' => (int)$instance->sounds,
    'locale' => $instance->targetlocale,
    'supportlang' => $instance->supportlang,
    'speakmode' => audio::speaking_mode($instance->targetlocale),
    'hasservicetts' => audio::has_service_tts($instance->targetlocale),
    'speaking' => (int)$instance->speaking,
    'passscore' => (int)$instance->passscore,
    'repetitions' => (int)$instance->repetitions,
    'consecutive' => (int)$instance->consecutive,
    'maxspeaktries' => (int)$instance->maxspeaktries,
    'practicecounts' => $counts,
    'study' => ($instance->allowstudy && $ready) ? learning::study_data($instance, $context) : null,
];

$templatedata = [
    'uniqid' => 'lt-' . $cm->id,
    'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'ready' => (bool)$ready,
    'canmanage' => $canmanage,
    'setupurl' => (new moodle_url('/mod/ailanguageteacher/builder.php', ['id' => $cm->id, 'step' => 'resume']))->out(false),
    'reporturl' => has_capability('mod/ailanguageteacher:viewreports', $context)
        ? (new moodle_url('/mod/ailanguageteacher/report.php', ['id' => $cm->id]))->out(false) : null,
    'guest' => !$canattempt,
    'language' => [
        'target' => languages::name($instance->targetlang),
        'locale' => languages::locale_name($instance->targetlocale),
        'support' => languages::name($instance->supportlang),
        'level' => $instance->cefrlevel,
        'levelname' => get_string('levelname_' . strtolower($instance->cefrlevel), 'mod_ailanguageteacher'),
    ],
    'stats' => [
        'scenes' => count($ready),
        'phrases' => $phrasecount,
        'mastered' => $counts['mastered'],
        'percent' => $counts['total'] ? round($counts['mastered'] / $counts['total'] * 100) : 0,
        'hasprogress' => $canattempt && $counts['total'] > 0,
    ],
    'modes' => $modes,
    'donecount' => count(array_filter($modes, fn($m) => !empty($m['done']))),
    'modecount' => count($modes),
];

$PAGE->requires->js_call_amd('mod_ailanguageteacher/player', 'init', ['#lt-' . $cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/view', $templatedata);
echo $OUTPUT->footer();
