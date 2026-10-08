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
 * Lesson builder: language, situations, learners' language and level, then build the scenes.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ailanguageteacher\local\ai\factory;
use mod_ailanguageteacher\local\languages;
use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\manager;
use mod_ailanguageteacher\local\setuppath;

$id = required_param('id', PARAM_INT);
$step = optional_param('step', '', PARAM_ALPHA);
$start = optional_param('start', 1, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:manage', $context);

$baseurl = new moodle_url('/mod/ailanguageteacher/builder.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('setup_title', 'mod_ailanguageteacher'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
if ($node = $PAGE->settingsnav->find('ailanguageteacher_builder', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

// The whole plugin needs this site to be unlocked with LMS Labs (50 credits or a recognised Marketplace purchase).
if (!\mod_ailanguageteacher\local\unlock::active()) {
    echo $OUTPUT->header();
    echo \mod_ailanguageteacher\local\unlock::locked_notice();
    echo $OUTPUT->footer();
    exit;
}

$saved = json_decode((string)$instance->situations, true) ?: [];

// Save the choices from steps 1 to 4.
if (optional_param('savechoices', 0, PARAM_BOOL)) {
    require_sesskey();
    $target = required_param('targetlang', PARAM_ALPHA);
    $data = (object)[
        'id' => $instance->id,
        'targetlang' => $target,
        'targetlocale' => optional_param('targetlocale_' . $target, '', PARAM_TEXT),
        'supportlang' => required_param('supportlang', PARAM_ALPHA),
        'cefrlevel' => required_param('cefrlevel', PARAM_ALPHANUM),
        'imagestyle' => optional_param('imagestyle', 'illustration', PARAM_ALPHA),
    ];
    $data = manager::prepare_instance_data($data);
    $keys = array_values(array_filter(
        optional_param_array('situations', [], PARAM_ALPHA),
        fn($k) => languages::is_situation($k)
    ));
    $custom = [];
    foreach (optional_param_array('custom', [], PARAM_TEXT) as $name) {
        $name = manager::clean_line($name, 120);
        if ($name !== '' && count($custom) < languages::MAX_CUSTOM) {
            $custom[] = $name;
        }
    }
    $data->situations = json_encode([
        'keys' => $keys,
        'custom' => $custom,
        'scenes' => max(1, min(lesson::MAX_SCENES_PER_SITUATION, optional_param('scenesper', 3, PARAM_INT))),
        'phrases' => max(1, min(manager::MAX_PHRASES, optional_param('phrasesper', manager::MAX_PHRASES, PARAM_INT))),
    ]);
    $data->showromanisation = languages::needs_romanisation($data->targetlang) ? 1 : (int)$instance->showromanisation;
    $data->timemodified = time();
    $DB->update_record('ailanguageteacher', $data);

    // Optionally show the activity's buttons and messages in the learners' language.
    $pack = languages::moodle_pack($data->supportlang);
    $forcelang = optional_param('forcelang', 0, PARAM_BOOL);
    $newlang = $forcelang && $pack ? $pack : '';
    if ((string)$cm->lang !== $newlang && ($forcelang || (string)$cm->lang === (string)$pack)) {
        $DB->set_field('course_modules', 'lang', $newlang === '' ? null : $newlang, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);
    }
    redirect(new moodle_url($baseurl, ['step' => 'build']));
}

$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
$saved = json_decode((string)$instance->situations, true) ?: [];
$str = fn($k, $a = null) => get_string($k, 'mod_ailanguageteacher', $a);
$nav = fn(int $back, ?int $next, string $blocked = '') => ['nav' => [
    'backurl' => setuppath::url((int)$cm->id, $back)->out(false),
    'backlabel' => $str('setup_back'),
    'nexturl' => $next ? setuppath::url((int)$cm->id, $next)->out(false) : null,
    'nextlabel' => $next ? $str('setup_next', $str('setupstep_' . setuppath::STEPS[$next - 1])) : '',
    'blocked' => $blocked,
]];

if ($step === 'resume') {
    redirect(setuppath::url((int)$cm->id, setuppath::resume_step(
        setuppath::state($instance, $context),
        !empty($saved['keys']) || !empty($saved['custom'])
    )));
}

if ($step === 'finish') {
    $state = setuppath::state($instance, $context);
    $todo = [];
    if (!$state['scenes']) {
        $todo[] = $str('finish_noscenes');
    }
    if ($state['nopicture']) {
        $todo[] = $str('finish_nopicture', count($state['nopicture']));
    }
    if ($state['novoice']) {
        $todo[] = $str('finish_novoice', count($state['novoice']));
    }
    if ($state['unplaced']) {
        $todo[] = $str('finish_unplaced', count($state['unplaced']));
    }
    $data = [
        'bar' => setuppath::bar(setuppath::FINISH),
        'title' => $str('setupstep_finish'),
        'ready' => $state['ready'],
        'scenes' => $state['scenes'],
        'allready' => !$todo,
        'todo' => $todo,
        'viewurl' => (new moodle_url('/mod/ailanguageteacher/view.php', ['id' => $cm->id]))->out(false),
        'courseurl' => course_get_url($course, $cm->sectionnum)->out(false),
    ] + $nav(setuppath::CHECK, null);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_ailanguageteacher/setup_finish', $data);
    echo $OUTPUT->footer();
    exit;
}

if ($step === 'build') {
    $provider = factory::get();
    $balance = $provider->balance();
    $scenes = manager::get_scenes($instance->id);
    $names = lesson::chosen_situations($instance);
    // Both ways of creating scenes go through LMS Labs (3 credits per scene), so both need it.
    $canai = $provider->can_generate() && has_capability('mod/ailanguageteacher:useai', $context);
    $templatedata = [
        'cmid' => (int)$cm->id,
        'summary' => [
            'target' => languages::locale_name($instance->targetlocale),
            'support' => languages::name($instance->supportlang),
            'level' => $instance->cefrlevel . ' · ' . $str('levelname_' . strtolower($instance->cefrlevel)),
            'situations' => $names ? implode(', ', $names) : $str('situation_general'),
            'options' => $str('builder_options', lesson::options($instance)),
        ],
        'canai' => $canai,
        'canimport' => $canai,
        'draftcredits' => \mod_ailanguageteacher\local\ai\lmslabs::TEXT_CREDITS,
        'importcredits' => \mod_ailanguageteacher\local\ai\lmslabs::TEXT_CREDITS,
        'bar' => setuppath::bar(setuppath::CREATE),
        'nexturl' => setuppath::url((int)$cm->id, setuppath::PICTURES)->out(false),
        'hasbalance' => $balance !== null,
        'balance' => $balance === null ? '' : ($balance['unlimited'] ? $str('balance_unlimited')
            : $str('balance_credits', $balance['credits'])),
        'prompt' => lesson::prompt($instance),
        'existing' => count($scenes),
    ];
    $templatedata += $nav(4, setuppath::PICTURES, $scenes ? '' : $str('setup_needscene'));
    $PAGE->requires->js_call_amd('mod_ailanguageteacher/builder', 'initBuild', ['#lt-build']);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('mod_ailanguageteacher/builder_build', $templatedata);
    echo $OUTPUT->footer();
    exit;
}

// Steps 1 to 4.
$languages = [];
$supports = [];
foreach (languages::LANGUAGES as $code => $info) {
    $locales = [];
    foreach ($info[1] as $locale) {
        $locales[] = ['locale' => $locale, 'name' => languages::locale_name($locale),
            'selected' => $locale === $instance->targetlocale];
    }
    $item = ['code' => $code, 'name' => languages::name($code), 'endonym' => $info[0],
        'showendonym' => languages::name($code) !== $info[0], 'rtl' => languages::is_rtl($code)];
    $languages[] = $item + ['selected' => $code === $instance->targetlang, 'locales' => $locales,
        'multilocale' => count($locales) > 1];
    $supports[] = $item + ['selected' => $code === $instance->supportlang,
        'haspack' => languages::moodle_pack($code) !== null];
}
$levels = [];
foreach (languages::LEVELS as $level) {
    $l = strtolower($level);
    $levels[] = ['code' => $level, 'name' => $str('levelname_' . $l), 'desc' => $str('leveldesc_' . $l),
        'ielts' => $str('ielts_' . $l), 'selected' => $level === $instance->cefrlevel];
}
$chosen = $saved['keys'] ?? [];
$groups = [];
foreach (languages::SITUATIONS as $group => $keys) {
    $items = [];
    foreach ($keys as $key) {
        $items[] = ['key' => $key, 'name' => languages::situation_name($key), 'checked' => in_array($key, $chosen, true)];
    }
    $groups[] = ['key' => $group, 'name' => $str('sitgroup_' . $group), 'items' => $items];
}
$custom = array_values($saved['custom'] ?? []);
$customfields = [];
for ($i = 0; $i < languages::MAX_CUSTOM; $i++) {
    $customfields[] = ['index' => $i + 1, 'value' => $custom[$i] ?? '', 'hidden' => $i > max(0, count($custom))];
}
$options = lesson::options($instance);
$templatedata = [
    'cmid' => (int)$cm->id,
    'action' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'languages' => $languages,
    'supports' => $supports,
    'levels' => $levels,
    'groups' => $groups,
    'custom' => $customfields,
    'scenesper' => array_map(
        fn($n) => ['value' => $n, 'selected' => $n === $options['scenes']],
        range(1, lesson::MAX_SCENES_PER_SITUATION)
    ),
    'phrasesper' => array_map(
        fn($n) => ['value' => $n, 'selected' => $n === $options['phrases']],
        range(1, manager::MAX_PHRASES)
    ),
    'illustration' => $instance->imagestyle !== 'photo',
    'photo' => $instance->imagestyle === 'photo',
    'forcelang' => !empty($cm->lang),
    'bar' => setuppath::bar(max(1, min(4, $start))),
];
$PAGE->requires->js_call_amd('mod_ailanguageteacher/builder', 'initWizard', ['#lt-wizard', max(1, min(4, $start))]);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/builder', $templatedata);
echo $OUTPUT->footer();
