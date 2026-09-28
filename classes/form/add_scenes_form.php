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

namespace mod_ailanguageteacher\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Adds scenes: one scene per uploaded picture (ZIP files are unpacked), or one empty scene with a title.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_scenes_form extends \moodleform {
    /**
     * Definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $c = 'mod_ailanguageteacher';
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'add');
        $mform->setType('action', PARAM_ALPHA);
        $situations = [0 => get_string('situation_new', $c)] + ($this->_customdata['situations'] ?? []);
        $mform->addElement('select', 'situationid', get_string('situation', $c), $situations);
        $mform->addElement('text', 'situationname', get_string('situation_newname', $c), ['size' => 40]);
        $mform->setType('situationname', PARAM_TEXT);
        $mform->hideIf('situationname', 'situationid', 'neq', 0);
        $mform->addElement('text', 'title', get_string('scenetitle', $c), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addHelpButton('title', 'scenetitle', $c);
        $options = \mod_ailanguageteacher\local\manager::image_filemanager_options(-1);
        $mform->addElement('filemanager', 'images', get_string('uploadimages', $c), null, $options);
        $mform->addHelpButton('images', 'uploadimages', $c);
        $this->add_action_buttons(false, get_string('addscenes', $c));
    }

    /**
     * Needs a picture or a title.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $draft = file_get_drafarea_files($data['images'] ?? 0);
        if (empty($draft->list) && trim((string)($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('errorsceneneeds', 'mod_ailanguageteacher');
        }
        return $errors;
    }
}
