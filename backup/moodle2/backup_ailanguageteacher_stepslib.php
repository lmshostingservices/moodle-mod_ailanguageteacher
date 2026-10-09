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
 * Backup structure for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_ailanguageteacher_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element('ailanguageteacher', ['id'], [
            'name', 'intro', 'introformat', 'targetlang', 'targetlocale', 'supportlang', 'cefrlevel', 'situations',
            'imagestyle', 'ttsvoice', 'ttsvoice2', 'voicematch', 'showromanisation', 'allowstudy', 'allowpractice',
            'allowtest', 'sequential', 'speaking',
            'passscore', 'repetitions', 'consecutive', 'maxspeaktries', 'testlistening', 'testspeaking', 'grade',
            'grademethod', 'maxattempts', 'timelimit', 'shufflelabels', 'sounds', 'mustlisten', 'leaderboard', 'completionstudy',
            'completionmastery', 'completionfinish', 'timecreated', 'timemodified',
        ]);
        $situations = new backup_nested_element('situationlist');
        $situation = new backup_nested_element('situation', ['id'], ['name', 'sortorder']);
        $scenes = new backup_nested_element('scenes');
        $scene = new backup_nested_element('scene', ['id'], ['situationid', 'sortorder', 'title', 'context', 'imageprompt',
            'timemodified']);
        $phrases = new backup_nested_element('phrases');
        $phrase = new backup_nested_element('phrase', ['id'], ['sortorder', 'text', 'romanisation', 'translation',
            'usagenote', 'example', 'exampletrans', 'prompt', 'alternatives', 'anchor', 'voicegender', 'x', 'y', 'placed', 'color',
            'distractor', 'timemodified']);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], ['userid', 'attempt', 'kind', 'state', 'tokenmap',
            'stages', 'timestart', 'timefinish', 'correct', 'total', 'grade', 'duration']);
        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], ['sceneid', 'phraseid', 'stage', 'answerid', 'score',
            'correct', 'tries', 'hints', 'timecreated']);
        $progress = new backup_nested_element('progress');
        $progressrow = new backup_nested_element('progressrow', ['id'], ['userid', 'phraseid', 'state', 'matchtries',
            'hints', 'hintlevel', 'speaktries', 'successes', 'bestscore', 'lastscore', 'timestudied', 'timemastered',
            'timemodified']);
        $speech = new backup_nested_element('speech');
        $speechrow = new backup_nested_element('speechrow', ['id'], ['userid', 'phraseid', 'attemptid', 'kind', 'provider',
            'score', 'recognised', 'passed', 'timecreated']);

        $root->add_child($situations);
        $situations->add_child($situation);
        $root->add_child($scenes);
        $scenes->add_child($scene);
        $scene->add_child($phrases);
        $phrases->add_child($phrase);
        $root->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($responses);
        $responses->add_child($response);
        $root->add_child($progress);
        $progress->add_child($progressrow);
        $root->add_child($speech);
        $speech->add_child($speechrow);

        $root->set_source_table('ailanguageteacher', ['id' => backup::VAR_ACTIVITYID]);
        $situation->set_source_table(
            'ailanguageteacher_situation',
            ['ailanguageteacherid' => backup::VAR_PARENTID],
            'sortorder ASC'
        );
        $scene->set_source_table(
            'ailanguageteacher_scene',
            ['ailanguageteacherid' => backup::VAR_PARENTID],
            'sortorder ASC'
        );
        $phrase->set_source_table('ailanguageteacher_phrase', ['sceneid' => backup::VAR_PARENTID], 'sortorder ASC');
        if ($userinfo) {
            $attempt->set_source_table(
                'ailanguageteacher_attempt',
                ['ailanguageteacherid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $response->set_source_table('ailanguageteacher_response', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
            $progressrow->set_source_table(
                'ailanguageteacher_progress',
                ['ailanguageteacherid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $speechrow->set_source_table(
                'ailanguageteacher_speech',
                ['ailanguageteacherid' => backup::VAR_PARENTID],
                'id ASC'
            );
        }
        $attempt->annotate_ids('user', 'userid');
        $progressrow->annotate_ids('user', 'userid');
        $speechrow->annotate_ids('user', 'userid');

        $root->annotate_files('mod_ailanguageteacher', 'intro', null);
        $scene->annotate_files('mod_ailanguageteacher', 'sceneimage', 'id');
        $phrase->annotate_files('mod_ailanguageteacher', 'phraseaudio', 'id');
        $phrase->annotate_files('mod_ailanguageteacher', 'ttsaudio', 'id');

        return $this->prepare_activity_structure($root);
    }
}
