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

namespace mod_ailanguageteacher;

use mod_ailanguageteacher\local\learning;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Backup and restore tests (duplicate, and course backup with user data).
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[CoversNothing]
final class backup_test extends \advanced_testcase {
    /**
     * Backs up a course with users and restores it as a new course.
     *
     * @param int $courseid
     * @return int new course id
     */
    protected function backup_restore(int $courseid): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $file = $bc->get_results()['backup_destination'];
        $file->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($backupid)
        );
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course('Restored', 'R1', $this->getDataGenerator()->create_category()->id);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * Content, files, progress and attempts survive, with every id remapped.
     */
    public function test_course_backup_with_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $lt->create_scene($instance, 'Arriving', [['text' => 'Good morning!', 'x' => 20, 'y' => 30],
            ['text' => 'Night', 'distractor' => 1]], 'Greetings');
        $cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id);
        $context = \context_module::instance($cm->id);
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['text' => 'Good morning!']);
        \mod_ailanguageteacher\local\manager::save_recording($context, $phrase, 'OggS' . str_repeat('a', 300));
        $instance = $DB->get_record('ailanguageteacher', ['id' => $instance->id]);
        $data = learning::start_attempt($instance, $cm, $course, $context, 'test', (int)$student->id);
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $map = json_decode($attempt->tokenmap, true);
        $pin = array_key_first($map['pins']);
        learning::submit_scene(
            $instance,
            $attempt,
            (int)$data['scenes'][0]['id'],
            'match',
            [['item' => $pin, 'answer' => array_flip($map['chips'])[$map['pins'][$pin]]]]
        );
        learning::finish_attempt($instance, $cm, $course, $context, $attempt);

        $newcourseid = $this->backup_restore((int)$course->id);
        $newinstance = $DB->get_record('ailanguageteacher', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame('th', $newinstance->supportlang);
        $newscene = $DB->get_record('ailanguageteacher_scene', ['ailanguageteacherid' => $newinstance->id], '*', MUST_EXIST);
        $this->assertNotEquals(0, (int)$newscene->situationid);
        $this->assertSame('Greetings', $DB->get_field('ailanguageteacher_situation', 'name', ['id' => $newscene->situationid]));
        $newphrase = $DB->get_record('ailanguageteacher_phrase', ['sceneid' => $newscene->id, 'text' => 'Good morning!']);
        $newcm = get_coursemodule_from_instance('ailanguageteacher', $newinstance->id);
        $newcontext = \context_module::instance($newcm->id);
        $this->assertNotNull(\mod_ailanguageteacher\local\manager::get_scene_file($newcontext, (int)$newscene->id));
        $this->assertNotSame('', \mod_ailanguageteacher\local\manager::recording_url($newcontext, (int)$newphrase->id));
        $newattempt = $DB->get_record(
            'ailanguageteacher_attempt',
            ['ailanguageteacherid' => $newinstance->id, 'kind' => 'test'],
            '*',
            MUST_EXIST
        );
        $newmap = json_decode($newattempt->tokenmap, true);
        $this->assertContains((int)$newphrase->id, array_values($newmap['pins']));
        $this->assertArrayHasKey($newscene->id, $newmap['scenes']);
        $response = $DB->get_record('ailanguageteacher_response', ['attemptid' => $newattempt->id], '*', MUST_EXIST);
        $this->assertEquals($newphrase->id, $response->phraseid);
        $this->assertEquals(1, $response->correct);
        // The restored activity is usable.
        $this->setUser($student);
        $again = learning::start_attempt(
            $newinstance,
            $newcm,
            get_course($newcourseid),
            $newcontext,
            'practice',
            (int)$student->id
        );
        $this->assertCount(1, $again['scenes']);
    }

    /**
     * Duplicating an activity copies the content but not learner data.
     */
    public function test_duplicate(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id, 'name' => 'Orig']);
        $lt->create_scene($instance, 'Arriving', [['text' => 'Hello', 'x' => 20, 'y' => 30]]);
        $cm = get_fast_modinfo($course)->get_cm(get_coursemodule_from_instance('ailanguageteacher', $instance->id)->id);
        if (method_exists(\core_courseformat\local\cmactions::class, 'duplicate')) {
            // Moodle 5.2+.
            $newcm = \core_courseformat\formatactions::cm($course->id)->duplicate($cm->id);
        } else {
            $newcm = duplicate_module($course, $cm);
        }
        $this->assertSame(2, $DB->count_records('ailanguageteacher'));
        $this->assertSame(2, $DB->count_records('ailanguageteacher_phrase', ['text' => 'Hello']));
        $this->assertNotNull(\mod_ailanguageteacher\local\manager::get_scene_file(
            \context_module::instance($newcm->id),
            (int)$DB->get_field('ailanguageteacher_scene', 'id', ['ailanguageteacherid' => $newcm->instance])
        ));
    }
}
