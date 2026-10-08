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
 * Set-up steps 6 (a picture for every scene), 7 (voices for every phrase) and 8 (check each scene: place its phrases,
 * order, remove).
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ailanguageteacher\local\manager;
use mod_ailanguageteacher\local\setuppath;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sceneid = optional_param('sceneid', 0, PARAM_INT);
$steps = ['pictures' => setuppath::PICTURES, 'voices' => setuppath::VOICES, 'check' => setuppath::CHECK];
$step = $steps[optional_param('step', 'check', PARAM_ALPHA)] ?? setuppath::CHECK;

[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:manage', $context);

$baseurl = setuppath::url((int)$cm->id, $step);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' .
    get_string('setupstep_' . setuppath::STEPS[$step - 1], 'mod_ailanguageteacher'));
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

$scene = null;
if ($sceneid) {
    $scene = $DB->get_record(
        'ailanguageteacher_scene',
        ['id' => $sceneid, 'ailanguageteacherid' => $instance->id],
        '*',
        MUST_EXIST
    );
}

if ($action === 'voice') {
    // The one voice used for all of this activity's phrase audio.
    require_sesskey();
    $voice = required_param('voice', PARAM_TEXT);
    $names = array_column((new \mod_ailanguageteacher\local\speech\lmslabs())->voices((string)$instance->targetlocale), 'name');
    if (in_array($voice, $names, true)) {
        $DB->set_field('ailanguageteacher', 'ttsvoice', $voice, ['id' => $instance->id]);
    }
    redirect($baseurl);
}
if (($action === 'up' || $action === 'down') && $scene) {
    require_sesskey();
    manager::move_scene($scene, $action === 'up' ? -1 : 1);
    redirect($baseurl);
}
if ($action === 'delete' && $scene) {
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        manager::delete_scene($context, $scene);
        redirect(
            $baseurl,
            get_string('scenedeleted', 'mod_ailanguageteacher'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletescene', 'mod_ailanguageteacher', format_string($scene->title)),
        new moodle_url($baseurl, ['action' => 'delete', 'sceneid' => $scene->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}
if ($action === 'replace' && $scene) {
    $form = new \mod_ailanguageteacher\form\replace_image_form($baseurl);
    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        $ok = manager::replace_scene_image($context, $scene, (int)$data->image);
        redirect(
            $baseurl,
            get_string($ok ? 'imagereplaced' : 'noimagefound', 'mod_ailanguageteacher'),
            null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    $draftid = file_get_submitted_draft_itemid('image');
    file_prepare_draft_area(
        $draftid,
        $context->id,
        'mod_ailanguageteacher',
        'sceneimage',
        $scene->id,
        manager::image_filemanager_options(1)
    );
    $form->set_data(['id' => $cm->id, 'sceneid' => $scene->id, 'image' => $draftid]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('replaceimage', 'mod_ailanguageteacher') . ': ' . format_string($scene->title), 3);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Scenes are created only in step 5, where every way of creating one is charged.
$situations = manager::get_situations($instance->id);
$scenes = manager::get_scenes($instance->id);
$phrases = manager::get_phrases(array_keys($scenes));
$state = setuppath::state($instance, $context);
$groups = [];
$i = 0;
$total = count($scenes);
$sesskey = sesskey();
$novoice = array_flip($state['novoice']);
foreach ($scenes as $s) {
    $i++;
    [$url] = manager::get_scene_image($context, (int)$s->id);
    $list = $phrases[$s->id];
    $pins = array_filter($list, fn($p) => empty($p->distractor));
    $placed = count(array_filter($pins, fn($p) => !empty($p->placed)));
    $voiced = [];
    foreach ($pins as $p) {
        if (trim((string)$p->text) !== '') {
            $voiced[] = ['id' => (int)$p->id, 'text' => format_string($p->text, true, ['context' => $context]),
                'hasvoice' => !isset($novoice[(int)$p->id])];
        }
    }
    $key = (int)$s->situationid;
    if (!isset($groups[$key])) {
        $groups[$key] = ['name' => isset($situations[$key]) ? format_string(
            $situations[$key]->name,
            true,
            ['context' => $context]
        ) : get_string('situation_general', 'mod_ailanguageteacher'), 'cards' => []];
    }
    $groups[$key]['cards'][] = [
        'id' => (int)$s->id,
        'number' => $i,
        'title' => format_string($s->title, true, ['context' => $context]),
        'image' => $url,
        'noimage' => !$url,
        'phrases' => count($pins),
        'placed' => $placed,
        'placedok' => $placed > 0,
        'voices' => $voiced,
        'novoices' => count(array_filter($voiced, fn($v) => !$v['hasvoice'])),
        'editurl' => (new moodle_url('/mod/ailanguageteacher/editor.php', ['id' => $cm->id, 'sceneid' => $s->id]))->out(false),
        'upurl' => $i > 1 ? (new moodle_url($baseurl, ['action' => 'up', 'sceneid' => $s->id, 'sesskey' => $sesskey]))
            ->out(false) : null,
        'downurl' => $i < $total ? (new moodle_url($baseurl, ['action' => 'down', 'sceneid' => $s->id,
            'sesskey' => $sesskey]))->out(false) : null,
        'replaceurl' => (new moodle_url($baseurl, ['action' => 'replace', 'sceneid' => $s->id]))->out(false),
        'deleteurl' => (new moodle_url($baseurl, ['action' => 'delete', 'sceneid' => $s->id]))->out(false),
    ];
}

$str = fn($k, $a = null) => get_string($k, 'mod_ailanguageteacher', $a);
// Next opens only when the step is done (voices only when LMS Labs has voices for this language).
$blocked = match ($step) {
    setuppath::PICTURES => $state['nopicture'] ? $str('setup_needpictures', count($state['nopicture'])) : '',
    setuppath::VOICES => $state['novoice'] ? $str('setup_needvoices', count($state['novoice'])) : '',
    default => $state['unplaced'] ? $str('setup_needplaced', count($state['unplaced']))
        : ($state['nopicture'] ? $str('setup_needpictures', count($state['nopicture'])) : ''),
};
if (!$total) {
    $blocked = $str('setup_needscene');
}

// The voice catalogue (cached) for the Voices step.
$voicedata = [];
$canvoice = false;
if ($step === setuppath::VOICES && $state['voices'] && has_capability('mod/ailanguageteacher:useai', $context)) {
    try {
        $names = array_column((new \mod_ailanguageteacher\local\speech\lmslabs())->voices((string)$instance->targetlocale), 'name');
    } catch (\moodle_exception $e) {
        $names = [];
    }
    $current = in_array((string)$instance->ttsvoice, $names, true) ? (string)$instance->ttsvoice : ($names[0] ?? '');
    foreach ($names as $name) {
        $voicedata[] = ['name' => $name, 'label' => preg_replace('/^.*-Chirp3-HD-/', '', $name),
            'selected' => $name === $current];
    }
    $canvoice = (bool)$names;
}

$PAGE->requires->js_call_amd('mod_ailanguageteacher/scenes', 'init', ['#lt-scenes']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/scenes', [
    'bar' => setuppath::bar($step),
    'pictures' => $step === setuppath::PICTURES,
    'voicesstep' => $step === setuppath::VOICES,
    'check' => $step === setuppath::CHECK,
    'groups' => array_values($groups),
    'hasscenes' => $total > 0,
    'count' => $total,
    'cmid' => (int)$cm->id,
    'canvoice' => $canvoice,
    'novoiceservice' => !$state['voices'],
    'voicelist' => $voicedata,
    'voiceaction' => $baseurl->out(false),
    'sesskey' => $sesskey,
    'missingvoices' => count($state['novoice']),
    'missingvoiceids' => implode(',', $state['novoice']),
    'missingvoicecredits' => count($state['novoice']),
    'nav' => [
        'backurl' => setuppath::url((int)$cm->id, $step - 1)->out(false),
        'backlabel' => $str('setup_back'),
        'nexturl' => setuppath::url((int)$cm->id, $step + 1)->out(false),
        'nextlabel' => $str('setup_next', $str('setupstep_' . setuppath::STEPS[$step])),
        'blocked' => $blocked,
    ],
]);
echo $OUTPUT->footer();
