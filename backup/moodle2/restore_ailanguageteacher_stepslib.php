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
 * Restore structure for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_ailanguageteacher_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $base = '/activity/ailanguageteacher';
        $paths = [
            new restore_path_element('ailanguageteacher', $base),
            new restore_path_element('ailanguageteacher_situation', $base . '/situationlist/situation'),
            new restore_path_element('ailanguageteacher_scene', $base . '/scenes/scene'),
            new restore_path_element('ailanguageteacher_phrase', $base . '/scenes/scene/phrases/phrase'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('ailanguageteacher_attempt', $base . '/attempts/attempt');
            $paths[] = new restore_path_element('ailanguageteacher_response', $base . '/attempts/attempt/responses/response');
            $paths[] = new restore_path_element('ailanguageteacher_progress', $base . '/progress/progressrow');
            $paths[] = new restore_path_element('ailanguageteacher_speech', $base . '/speech/speechrow');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the instance.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timemodified = time();
        $newid = $DB->insert_record('ailanguageteacher', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restores a situation.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_situation($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->ailanguageteacherid = $this->get_new_parentid('ailanguageteacher');
        $newid = $DB->insert_record('ailanguageteacher_situation', $data);
        $this->set_mapping('ailanguageteacher_situation', $oldid, $newid);
    }

    /**
     * Restores a scene.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_scene($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->ailanguageteacherid = $this->get_new_parentid('ailanguageteacher');
        $data->situationid = $data->situationid ? (int)$this->get_mappingid(
            'ailanguageteacher_situation',
            $data->situationid
        ) : 0;
        $newid = $DB->insert_record('ailanguageteacher_scene', $data);
        $this->set_mapping('ailanguageteacher_scene', $oldid, $newid, true);
    }

    /**
     * Restores a phrase.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_phrase($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->sceneid = $this->get_new_parentid('ailanguageteacher_scene');
        $newid = $DB->insert_record('ailanguageteacher_phrase', $data);
        $this->set_mapping('ailanguageteacher_phrase', $oldid, $newid, true);
    }

    /**
     * New id of a phrase (0 when it was not restored).
     *
     * @param mixed $oldid
     * @return int
     */
    protected function phrase_id($oldid): int {
        return $oldid ? (int)$this->get_mappingid('ailanguageteacher_phrase', $oldid) : 0;
    }

    /**
     * Restores an attempt, remapping its token map to the new phrase and scene ids.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->ailanguageteacherid = $this->get_new_parentid('ailanguageteacher');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $map = json_decode((string)$data->tokenmap, true);
        if (is_array($map)) {
            foreach (['pins', 'chips', 'listen', 'speak'] as $key) {
                foreach ($map[$key] ?? [] as $token => $phraseid) {
                    $map[$key][$token] = $this->phrase_id($phraseid);
                }
            }
            $scenes = [];
            foreach ($map['scenes'] ?? [] as $sceneid => $phraseids) {
                $newscene = (int)$this->get_mappingid('ailanguageteacher_scene', $sceneid);
                $scenes[$newscene] = array_map(fn($p) => $this->phrase_id($p), (array)$phraseids);
            }
            $map['scenes'] = $scenes;
            $data->tokenmap = json_encode($map);
        }
        $newid = $DB->insert_record('ailanguageteacher_attempt', $data);
        $this->set_mapping('ailanguageteacher_attempt', $oldid, $newid);
    }

    /**
     * Restores a response.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_response($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('ailanguageteacher_attempt');
        $data->sceneid = (int)$this->get_mappingid('ailanguageteacher_scene', $data->sceneid);
        $data->phraseid = $this->phrase_id($data->phraseid);
        $data->answerid = $this->phrase_id($data->answerid);
        $DB->insert_record('ailanguageteacher_response', $data);
    }

    /**
     * Restores a progress row.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_progress($data) {
        global $DB;
        $data = (object)$data;
        $data->ailanguageteacherid = $this->get_new_parentid('ailanguageteacher');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->phraseid = $this->phrase_id($data->phraseid);
        if (
            $data->userid && $data->phraseid && !$DB->record_exists(
                'ailanguageteacher_progress',
                ['userid' => $data->userid, 'phraseid' => $data->phraseid]
            )
        ) {
            $DB->insert_record('ailanguageteacher_progress', $data);
        }
    }

    /**
     * Restores a speaking score.
     *
     * @param array $data
     */
    protected function process_ailanguageteacher_speech($data) {
        global $DB;
        $data = (object)$data;
        $data->ailanguageteacherid = $this->get_new_parentid('ailanguageteacher');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->phraseid = $this->phrase_id($data->phraseid);
        $data->attemptid = $data->attemptid ? (int)$this->get_mappingid('ailanguageteacher_attempt', $data->attemptid) : 0;
        if ($data->userid) {
            $DB->insert_record('ailanguageteacher_speech', $data);
        }
    }

    /**
     * Restores files after the structure.
     */
    protected function after_execute() {
        $this->add_related_files('mod_ailanguageteacher', 'intro', null);
        $this->add_related_files('mod_ailanguageteacher', 'sceneimage', 'ailanguageteacher_scene');
        $this->add_related_files('mod_ailanguageteacher', 'phraseaudio', 'ailanguageteacher_phrase');
        $this->add_related_files('mod_ailanguageteacher', 'ttsaudio', 'ailanguageteacher_phrase');
    }
}
