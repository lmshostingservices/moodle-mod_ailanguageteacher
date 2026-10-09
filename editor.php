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
 * Scene editor: place each phrase on the picture and edit its meaning, notes, audio and accepted answers.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_ailanguageteacher\local\audio;
use mod_ailanguageteacher\local\languages;
use mod_ailanguageteacher\local\manager;

$id = required_param('id', PARAM_INT);
$sceneid = required_param('sceneid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'ailanguageteacher');
$instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/ailanguageteacher:manage', $context);
$scene = $DB->get_record(
    'ailanguageteacher_scene',
    ['id' => $sceneid, 'ailanguageteacherid' => $instance->id],
    '*',
    MUST_EXIST
);

$PAGE->set_url('/mod/ailanguageteacher/editor.php', ['id' => $cm->id, 'sceneid' => $scene->id]);
$PAGE->set_title(format_string($instance->name) . ': ' . format_string($scene->title));
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

$scenes = array_values(manager::get_scenes($instance->id));
$prev = $next = null;
$position = 0;
foreach ($scenes as $i => $s) {
    if ((int)$s->id === (int)$scene->id) {
        $position = $i + 1;
        $prev = $scenes[$i - 1] ?? null;
        $next = $scenes[$i + 1] ?? null;
    }
}
$editorurl = fn($s) => $s ? (new moodle_url('/mod/ailanguageteacher/editor.php', ['id' => $cm->id, 'sceneid' => $s->id]))
    ->out(false) : '';

$data = manager::editor_data($instance, $context, $scene);
$data += [
    'cmid' => (int)$cm->id,
    'maxphrases' => manager::MAX_PHRASES,
    'maxdistractors' => manager::MAX_DISTRACTORS,
    'position' => $position,
    'total' => count($scenes),
    'prevurl' => $editorurl($prev),
    'nexturl' => $editorurl($next),
    'backurl' => \mod_ailanguageteacher\local\setuppath::url((int)$cm->id, \mod_ailanguageteacher\local\setuppath::CHECK)
        ->out(false),
    'bar' => \mod_ailanguageteacher\local\setuppath::bar(\mod_ailanguageteacher\local\setuppath::CHECK),
    'previewurl' => (new moodle_url('/mod/ailanguageteacher/view.php', ['id' => $cm->id]))->out(false),
    'replaceurl' => (new moodle_url('/mod/ailanguageteacher/scenes.php', ['id' => $cm->id, 'step' => 'check',
        'action' => 'replace', 'sceneid' => $scene->id]))->out(false),
    'locale' => $instance->targetlocale,
    'rtl' => languages::is_rtl($instance->targetlang),
    'romanise' => languages::needs_romanisation($instance->targetlang),
    'hasservicetts' => audio::has_service_tts($instance->targetlocale),
    'voice' => (string)$instance->ttsvoice,
    'remakes' => audio::remakes() !== null,
    'voicecredits' => \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS,
    'canrecord' => true,
    'targetname' => languages::name($instance->targetlang),
    'supportname' => languages::name($instance->supportlang),
];

$PAGE->requires->js_call_amd('mod_ailanguageteacher/editor', 'init', ['#lt-editor']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_ailanguageteacher/editor', [
    'config' => json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'noimage' => empty($data['image']),
    'replaceurl' => $data['replaceurl'],
    'bar' => $data['bar'],
]);
echo $OUTPUT->footer();
