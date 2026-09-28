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

use mod_ailanguageteacher\completion\custom_completion;
use mod_ailanguageteacher\local\learning;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Custom completion rule tests.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\completion\custom_completion
 */
#[CoversClass(custom_completion::class)]
final class completion_test extends \advanced_testcase {
    /**
     * Study, mastery and finish rules.
     */
    public function test_rules(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_and_enrol($course, 'student');
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionstudy' => 1, 'completionmastery' => 1, 'completionfinish' => 1, 'speaking' => 0]);
        $lt->create_scene($instance, 'A', [['text' => 'Hello', 'x' => 20, 'y' => 30]]);
        $cm = get_fast_modinfo($course)->get_cm(get_coursemodule_from_instance('ailanguageteacher', $instance->id)->id);
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('ailanguageteacher', ['id' => $instance->id]);
        $check = fn($rule) => (new custom_completion($cm, (int)$student->id))->get_state($rule);
        foreach (['completionstudy', 'completionmastery', 'completionfinish'] as $rule) {
            $this->assertSame(COMPLETION_INCOMPLETE, $check($rule));
        }
        learning::record_study($instance, $cm, $course, $context, (int)$student->id, [], true);
        $this->assertSame(COMPLETION_COMPLETE, $check('completionstudy'));
        $data = learning::start_attempt($instance, $cm, $course, $context, 'practice', (int)$student->id);
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        learning::practice_event(
            $instance,
            $cm,
            $course,
            $context,
            $attempt,
            $data['scenes'][0]['answers'][0]['chip'],
            'matched',
            1,
            0
        );
        $this->assertSame(COMPLETION_COMPLETE, $check('completionmastery'));
        $test = learning::start_attempt($instance, $cm, $course, $context, 'test', (int)$student->id);
        learning::finish_attempt(
            $instance,
            $cm,
            $course,
            $context,
            $DB->get_record('ailanguageteacher_attempt', ['id' => $test['attemptid']])
        );
        $this->assertSame(COMPLETION_COMPLETE, $check('completionfinish'));
        $this->assertCount(3, custom_completion::get_defined_custom_rules());
    }
}
