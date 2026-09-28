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

namespace mod_ailanguageteacher\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_ailanguageteacher\local\learning;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy provider tests.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\privacy\provider
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \context_module */
    protected $context;
    /** @var \stdClass */
    protected $u1;
    /** @var \stdClass */
    protected $u2;

    /**
     * Two learners with attempts, progress and speaking scores.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $lt->create_scene($instance, 'Arriving', [['text' => 'Good morning!', 'x' => 20, 'y' => 30]]);
        $cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id);
        $this->context = \context_module::instance($cm->id);
        $instance = $DB->get_record('ailanguageteacher', ['id' => $instance->id]);
        foreach (['u1', 'u2'] as $name) {
            $user = $gen->create_and_enrol($course, 'student');
            $this->$name = $user;
            $data = learning::start_attempt($instance, $cm, $course, $this->context, 'practice', (int)$user->id);
            $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
            $token = $data['scenes'][0]['answers'][0]['chip'];
            learning::practice_event($instance, $cm, $course, $this->context, $attempt, $token, 'matched', 1, 0);
            learning::assess(
                $instance,
                $cm,
                $course,
                $this->context,
                (int)$user->id,
                'practice',
                $attempt,
                $token,
                '',
                ['good morning'],
                false
            );
        }
    }

    /**
     * Metadata lists every table and the grades link, and no external speech transfer while none exists.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('mod_ailanguageteacher'))->get_collection();
        $names = array_map(fn($i) => $i->get_name(), $items);
        foreach (
            ['ailanguageteacher_attempt', 'ailanguageteacher_response', 'ailanguageteacher_progress',
                'ailanguageteacher_speech', 'ailanguageteacher_ailog', 'core_grades'] as $name
        ) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('azurespeech', $names);
    }

    /**
     * Contexts, users and export.
     */
    public function test_export(): void {
        $contexts = provider::get_contexts_for_userid((int)$this->u1->id);
        $this->assertEquals([$this->context->id], $contexts->get_contextids());
        $userlist = new userlist($this->context, 'mod_ailanguageteacher');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->u1->id, $this->u2->id], $userlist->get_userids());
        $this->export_context_data_for_user((int)$this->u1->id, $this->context, 'mod_ailanguageteacher');
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertCount(1, $data->attempts);
        $this->assertSame('Good morning!', $data->attempts[0]->responses[0]->phrase);
        $this->assertSame('Good morning!', $data->progress[0]->phrase);
        $this->assertSame('browser', $data->speaking[0]->provider);
        $this->assertSame('good morning', $data->speaking[0]->recognised);
    }

    /**
     * Deleting one user, a list of users and the whole context.
     */
    public function test_delete(): void {
        global $DB;
        provider::delete_data_for_user(new approved_contextlist($this->u1, 'mod_ailanguageteacher', [$this->context->id]));
        foreach (['attempt', 'progress', 'speech'] as $t) {
            $this->assertSame(0, $DB->count_records('ailanguageteacher_' . $t, ['userid' => $this->u1->id]));
            $this->assertSame(1, $DB->count_records('ailanguageteacher_' . $t, ['userid' => $this->u2->id]));
        }
        $this->assertSame(1, $DB->count_records('ailanguageteacher_response'));
        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_ailanguageteacher', [$this->u2->id]));
        $this->assertSame(0, $DB->count_records('ailanguageteacher_progress'));
        $this->assertSame(0, $DB->count_records('ailanguageteacher_response'));
    }

    /**
     * Deleting everyone in the context keeps the lesson content.
     */
    public function test_delete_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        foreach (['attempt', 'response', 'progress', 'speech'] as $t) {
            $this->assertSame(0, $DB->count_records('ailanguageteacher_' . $t));
        }
        $this->assertSame(1, $DB->count_records('ailanguageteacher_phrase'));
    }
}
