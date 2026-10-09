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
 * Activity settings form.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_ailanguageteacher\local\languages;
use mod_ailanguageteacher\local\manager;

/**
 * Activity settings form.
 */
class mod_ailanguageteacher_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $config = get_config('mod_ailanguageteacher');
        $c = 'mod_ailanguageteacher';

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Language.
        $mform->addElement('header', 'languagehdr', get_string('languagesettings', $c));
        $mform->setExpanded('languagehdr');
        $mform->addElement('static', 'languagenote', '', get_string('languagesettings_note', $c));
        $mform->addElement('select', 'targetlang', get_string('targetlang', $c), languages::options());
        $mform->setDefault('targetlang', $config->defaulttargetlang ?? 'en');
        $mform->addHelpButton('targetlang', 'targetlang', $c);
        $mform->addElement('select', 'targetlocale', get_string('targetlocale', $c), languages::locale_options());
        $mform->setDefault('targetlocale', languages::default_locale($config->defaulttargetlang ?? 'en'));
        $mform->addHelpButton('targetlocale', 'targetlocale', $c);
        $mform->addElement('select', 'supportlang', get_string('supportlang', $c), languages::options());
        $mform->setDefault('supportlang', $config->defaultsupportlang ?? 'en');
        $mform->addHelpButton('supportlang', 'supportlang', $c);
        $mform->addElement('select', 'cefrlevel', get_string('cefrlevel', $c), languages::level_options());
        $mform->addHelpButton('cefrlevel', 'cefrlevel', $c);
        $mform->addElement(
            'advcheckbox',
            'showromanisation',
            get_string('showromanisation', $c),
            get_string('showromanisation_desc', $c)
        );
        $mform->setDefault('showromanisation', 1);
        $mform->addElement('select', 'imagestyle', get_string('imagestyle', $c), [
            'illustration' => get_string('imagestyle_illustration_name', $c),
            'photo' => get_string('imagestyle_photo_name', $c),
        ]);

        // Learning journey.
        $mform->addElement('header', 'modeshdr', get_string('journey', $c));
        $mform->addElement('advcheckbox', 'allowstudy', get_string('modestudy', $c), get_string('modestudy_desc', $c));
        $mform->setDefault('allowstudy', 1);
        $mform->addElement('advcheckbox', 'allowpractice', get_string('modepractice', $c), get_string('modepractice_desc', $c));
        $mform->setDefault('allowpractice', 1);
        $mform->addElement('advcheckbox', 'allowtest', get_string('modetest', $c), get_string('modetest_desc', $c));
        $mform->setDefault('allowtest', 1);
        $mform->addElement('advcheckbox', 'sequential', get_string('sequential', $c), get_string('sequential_desc', $c));
        $mform->setDefault('sequential', 1);
        $mform->addHelpButton('sequential', 'sequential', $c);

        // Speaking.
        $mform->addElement('header', 'speakinghdr', get_string('speakingsettings', $c));
        $mform->addElement('advcheckbox', 'speaking', get_string('speaking', $c), get_string('speaking_desc', $c));
        $mform->setDefault('speaking', 1);
        $mform->addHelpButton('speaking', 'speaking', $c);
        $scores = [];
        for ($i = 50; $i <= 100; $i += 5) {
            $scores[$i] = $i . '%';
        }
        $mform->addElement('select', 'passscore', get_string('passscore', $c), $scores);
        $mform->setDefault('passscore', 80);
        $mform->addHelpButton('passscore', 'passscore', $c);
        $mform->addElement('select', 'repetitions', get_string('repetitions', $c), array_combine(range(1, 10), range(1, 10)));
        $mform->setDefault('repetitions', 3);
        $mform->addHelpButton('repetitions', 'repetitions', $c);
        $mform->addElement('advcheckbox', 'mustlisten', get_string('mustlisten', $c), get_string('mustlisten_desc', $c));
        $mform->addHelpButton('mustlisten', 'mustlisten', $c);
        $mform->addElement('advcheckbox', 'consecutive', get_string('consecutive', $c), get_string('consecutive_desc', $c));
        $mform->addElement(
            'select',
            'maxspeaktries',
            get_string('maxspeaktries', $c),
            array_combine(range(3, 20), range(3, 20))
        );
        $mform->setDefault('maxspeaktries', 8);
        $mform->addHelpButton('maxspeaktries', 'maxspeaktries', $c);
        foreach (['passscore', 'repetitions', 'consecutive', 'maxspeaktries'] as $field) {
            $mform->hideIf($field, 'speaking', 'notchecked');
        }

        // Test.
        $mform->addElement('header', 'testhdr', get_string('testsettings', $c));
        $mform->addElement('advcheckbox', 'testlistening', get_string('testlistening', $c), get_string('testlistening_desc', $c));
        $mform->setDefault('testlistening', 1);
        $mform->addElement('advcheckbox', 'testspeaking', get_string('testspeaking', $c), get_string('testspeaking_desc', $c));
        $mform->setDefault('testspeaking', 1);
        $attemptoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', $c), $attemptoptions);
        $mform->addElement('duration', 'timelimit', get_string('timelimit', $c), ['optional' => true, 'defaultunit' => 60]);
        $mform->addHelpButton('timelimit', 'timelimit', $c);

        // Experience.
        $mform->addElement('header', 'experiencehdr', get_string('experience', $c));
        $mform->addElement('advcheckbox', 'shufflelabels', get_string('shufflelabels', $c));
        $mform->setDefault('shufflelabels', 1);
        $mform->addElement('advcheckbox', 'sounds', get_string('sounds', $c), get_string('sounds_desc', $c));
        $mform->setDefault('sounds', $config->defaultsounds ?? 1);
        $mform->addElement('advcheckbox', 'leaderboard', get_string('leaderboard', $c), get_string('leaderboard_desc', $c));
        $mform->setDefault('leaderboard', $config->defaultleaderboard ?? 0);

        // Grade.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        $mform->addElement('select', 'grademethod', get_string('grademethod', $c), [
            manager::GRADE_HIGHEST => get_string('gradehighest', $c),
            manager::GRADE_AVERAGE => get_string('gradeaverage', $c),
            manager::GRADE_FIRST => get_string('gradefirst', $c),
            manager::GRADE_LAST => get_string('gradelast', $c),
        ]);
        $mform->addHelpButton('grademethod', 'grademethod', $c);
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Returns the form element name with the completion suffix (Moodle 4.3+).
     *
     * @param string $name
     * @return string
     */
    protected function suffixed(string $name): string {
        return method_exists($this, 'get_suffix') ? $name . $this->get_suffix() : $name;
    }

    /**
     * Adds custom completion rules.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $names = [];
        foreach (['completionstudy', 'completionmastery', 'completionfinish'] as $rule) {
            $name = $this->suffixed($rule);
            $mform->addElement(
                'advcheckbox',
                $name,
                get_string($rule, 'mod_ailanguageteacher'),
                get_string($rule . '_desc', 'mod_ailanguageteacher')
            );
            $mform->addHelpButton($name, $rule, 'mod_ailanguageteacher');
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Whether a custom completion rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        foreach (['completionstudy', 'completionmastery', 'completionfinish'] as $rule) {
            if (!empty($data[$this->suffixed($rule)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['allowstudy']) && empty($data['allowpractice']) && empty($data['allowtest'])) {
            $errors['allowtest'] = get_string('errornomode', 'mod_ailanguageteacher');
        }
        return $errors;
    }
}
