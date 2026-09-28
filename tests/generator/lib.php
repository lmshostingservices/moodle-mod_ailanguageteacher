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
 * Data generator for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_ailanguageteacher_generator extends testing_module_generator {
    /**
     * Creates an instance with sensible defaults.
     *
     * @param array|stdClass $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'targetlang' => 'en', 'targetlocale' => 'en-AU', 'supportlang' => 'th', 'cefrlevel' => 'A1',
            'imagestyle' => 'illustration', 'showromanisation' => 1, 'allowstudy' => 1, 'allowpractice' => 1,
            'allowtest' => 1, 'sequential' => 0, 'speaking' => 1, 'passscore' => 80, 'repetitions' => 3,
            'consecutive' => 0, 'maxspeaktries' => 8, 'testlistening' => 1, 'testspeaking' => 1, 'grade' => 100,
            'grademethod' => 1, 'maxattempts' => 0, 'timelimit' => 0, 'shufflelabels' => 1, 'sounds' => 1,
            'leaderboard' => 0, 'completionstudy' => 0, 'completionmastery' => 0, 'completionfinish' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }

    /**
     * Creates a scene with a picture and phrases.
     *
     * @param stdClass $instance
     * @param string $title
     * @param array $phrases list of arrays: text, translation, x, y (placed), distractor, alternatives, ...
     * @param string $situation situation name
     * @param string|null $imagepath picture (defaults to the test fixture)
     * @return stdClass scene
     */
    public function create_scene(
        stdClass $instance,
        string $title,
        array $phrases,
        string $situation = 'Greetings',
        ?string $imagepath = null
    ): stdClass {
        global $CFG, $DB;
        $cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $situationid = \mod_ailanguageteacher\local\manager::situation_id((int)$instance->id, $situation);
        $sceneid = \mod_ailanguageteacher\local\manager::add_scene($instance, $situationid, $title, 'Context of ' . $title);
        $imagepath = $imagepath ?? $CFG->dirroot . '/mod/ailanguageteacher/tests/fixtures/scene.png';
        get_file_storage()->create_file_from_pathname([
            'contextid' => $context->id, 'component' => 'mod_ailanguageteacher', 'filearea' => 'sceneimage',
            'itemid' => $sceneid, 'filepath' => '/', 'filename' => basename($imagepath),
        ], $imagepath);
        $data = [];
        foreach ($phrases as $p) {
            $data[] = $p + ['id' => 0, 'placed' => isset($p['x']) ? 1 : 0, 'color' => ''];
        }
        $scene = $DB->get_record('ailanguageteacher_scene', ['id' => $sceneid], '*', MUST_EXIST);
        \mod_ailanguageteacher\local\manager::save_scene($scene, ['title' => $title, 'context' => $scene->context], $data);
        return $DB->get_record('ailanguageteacher_scene', ['id' => $sceneid], '*', MUST_EXIST);
    }
    /**
     * Creates a scene from Behat table data.
     *
     * @param array $data activityid (instance id), title, situation, phrases ("text=meaning" pairs separated by "|")
     * @return stdClass scene
     */
    public function create_behat_scene(array $data): stdClass {
        global $DB;
        $instance = $DB->get_record('ailanguageteacher', ['id' => $data['activityid']], '*', MUST_EXIST);
        $phrases = [];
        $n = 0;
        foreach (array_filter(array_map('trim', explode('|', (string)($data['phrases'] ?? '')))) as $pair) {
            [$text, $meaning] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            $n++;
            $phrases[] = ['text' => $text, 'translation' => $meaning, 'x' => 15 * $n, 'y' => 40];
        }
        return $this->create_scene($instance, $data['title'], $phrases, $data['situation'] ?? 'Greetings');
    }
}
