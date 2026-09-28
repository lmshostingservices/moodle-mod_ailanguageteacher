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
 * Scene manager: pictures, order, and adding or removing scenes.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ailanguageteacher\local\ai\factory;
use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\manager;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sceneid = optional_param('sceneid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:manage', $context);

$baseurl = new moodle_url('/mod/ailanguageteacher/scenes.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('managescenes', 'mod_ailanguageteacher'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
if ($node = $PAGE->settingsnav->find('ailanguageteacher_scenes', navigation_node::TYPE_SETTING)) {
    $node->make_active();
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

$situations = manager::get_situations($instance->id);
$situationoptions = [];
foreach ($situations as $s) {
    $situationoptions[$s->id] = format_string($s->name, true, ['context' => $context]);
}
$addform = new \mod_ailanguageteacher\form\add_scenes_form($baseurl, ['situations' => $situationoptions]);
$addform->set_data(['id' => $cm->id]);
if ($data = $addform->get_data()) {
    $situationid = (int)$data->situationid;
    if (!$situationid || !isset($situations[$situationid])) {
        $situationid = manager::situation_id((int)$instance->id, (string)$data->situationname);
    }
    $count = manager::create_scenes_from_draft($instance, $context, (int)$data->images, $situationid);
    if (!$count && trim((string)$data->title) !== '') {
        manager::add_scene($instance, $situationid, (string)$data->title);
        $count = 1;
    }
    manager::remove_empty_situations((int)$instance->id);
    redirect(
        $baseurl,
        get_string('scenescreated', 'mod_ailanguageteacher', $count),
        null,
        $count ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

$scenes = manager::get_scenes($instance->id);
$phrases = manager::get_phrases(array_keys($scenes));
$provider = factory::get();
// Lesson text has its own approved route; picture generation does not.
$canai = false;
$groups = [];
$i = 0;
$total = count($scenes);
$sesskey = sesskey();
foreach ($scenes as $s) {
    $i++;
    [$url] = manager::get_scene_image($context, (int)$s->id);
    $list = $phrases[$s->id];
    $pins = array_filter($list, fn($p) => empty($p->distractor));
    $placed = count(array_filter($pins, fn($p) => !empty($p->placed)));
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
        'unplaced' => count($pins) - $placed,
        'ready' => $url && $placed > 0,
        'imageprompt' => lesson::image_prompt($instance, $s, $list),
        'canai' => $canai,
        'editurl' => (new moodle_url('/mod/ailanguageteacher/editor.php', ['id' => $cm->id, 'sceneid' => $s->id]))->out(false),
        'upurl' => $i > 1 ? (new moodle_url($baseurl, ['action' => 'up', 'sceneid' => $s->id, 'sesskey' => $sesskey]))
            ->out(false) : null,
        'downurl' => $i < $total ? (new moodle_url($baseurl, ['action' => 'down', 'sceneid' => $s->id,
            'sesskey' => $sesskey]))->out(false) : null,
        'replaceurl' => (new moodle_url($baseurl, ['action' => 'replace', 'sceneid' => $s->id]))->out(false),
        'deleteurl' => (new moodle_url($baseurl, ['action' => 'delete', 'sceneid' => $s->id]))->out(false),
    ];
}

$PAGE->requires->js_call_amd('mod_ailanguageteacher/scenes', 'init', ['#lt-scenes']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/scenes', [
    'groups' => array_values($groups),
    'hasscenes' => $total > 0,
    'country' => lesson::setting($instance),
    'maxphrases' => manager::MAX_PHRASES,
    'count' => $total,
    'viewurl' => (new moodle_url('/mod/ailanguageteacher/view.php', ['id' => $cm->id]))->out(false),
    'builderurl' => (new moodle_url('/mod/ailanguageteacher/builder.php', ['id' => $cm->id]))->out(false),
    'addform' => $addform->render(),
]);
echo $OUTPUT->footer();
