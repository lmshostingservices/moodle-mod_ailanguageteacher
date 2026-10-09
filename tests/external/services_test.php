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

namespace mod_ailanguageteacher\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Web service permission and behaviour tests.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\external\start_attempt
 * @covers     \mod_ailanguageteacher\external\save_scene
 * @covers     \mod_ailanguageteacher\external\import_lesson
 * @covers     \mod_ailanguageteacher\external\get_audio
 * @covers     \mod_ailanguageteacher\external\assess_speech
 * @covers     \mod_ailanguageteacher\external\generate_lesson
 */
#[CoversClass(start_attempt::class)]
#[CoversClass(save_scene::class)]
#[CoversClass(import_lesson::class)]
#[CoversClass(get_audio::class)]
#[CoversClass(assess_speech::class)]
#[CoversClass(generate_lesson::class)]
final class services_test extends \advanced_testcase {
    /** @var array */
    protected $u = [];
    /** @var \stdClass */
    protected $cm;
    /** @var \stdClass */
    protected $scene;

    /**
     * A course with a teacher, a non-editing teacher, a learner and an outsider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        foreach (['editingteacher', 'teacher', 'student'] as $role) {
            $this->u[$role] = $gen->create_and_enrol($course, $role);
        }
        $this->u['outsider'] = $gen->create_user();
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $this->scene = $lt->create_scene($instance, 'Arriving', [['text' => 'Hello', 'x' => 20, 'y' => 30]]);
        $this->cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id);
        // The plugin is unlocked on this site; test_locked_site covers the opposite.
        set_config('unlockstate', json_encode(['status' => 'unlocked', 'checkedat' => time()]), 'mod_ailanguageteacher');
    }

    /**
     * Calls a service as a user and returns the cleaned result.
     *
     * @param string $user
     * @param string $class
     * @param array $args
     * @return mixed
     */
    protected function call(string $user, string $class, array $args) {
        $this->setUser($this->u[$user]);
        $class = '\\mod_ailanguageteacher\\external\\' . $class;
        $result = $class::execute(...$args);
        return external_api::clean_returnvalue($class::execute_returns(), $result);
    }

    /**
     * Only learners start attempts; the Test payload carries no answers.
     */
    public function test_start_attempt(): void {
        $data = $this->call('student', 'start_attempt', [(int)$this->cm->id, 'test']);
        $this->assertSame('test', $data['kind']);
        $this->assertEmpty($data['scenes'][0]['answers']);
        foreach (['teacher', 'outsider'] as $who) {
            try {
                $this->call($who, 'start_attempt', [(int)$this->cm->id, 'practice']);
                $this->fail($who . ' must not start an attempt');
            } catch (\moodle_exception $e) {
                $this->assertInstanceOf(\moodle_exception::class, $e);
            }
        }
    }

    /**
     * Only editing teachers save scenes and import lessons.
     */
    public function test_teacher_services(): void {
        global $DB;
        $res = $this->call('editingteacher', 'save_scene', [(int)$this->scene->id, 'Renamed', '', '', 0,
            [['text' => 'Hi there', 'x' => 10, 'y' => 10, 'placed' => 1]]]);
        $this->assertCount(1, $res['ids']);
        $this->assertSame('Renamed', $DB->get_field('ailanguageteacher_scene', 'title', ['id' => $this->scene->id]));
        // Scenes from the teacher's own AI assistant are charged (5 credits each) before they are created.
        set_config('lmslabssiteid', 'site', 'mod_ailanguageteacher');
        set_config('lmslabsapikey', 'key', 'mod_ailanguageteacher');
        $sent = [];
        \mod_ailanguageteacher\local\remote::$transport = function ($url, $headers, $body) use (&$sent) {
            $sent[] = [$url, json_decode($body, true)];
            return [200, json_encode(['requestId' => 'imp', 'creditsCharged' => 5, 'creditsBalance' => 40])];
        };
        $draft = json_encode(['scenes' => [['title' => 'Paying', 'phrases' => [['text' => 'How much?']]]]]);
        $res = $this->call('editingteacher', 'import_lesson', [(int)$this->cm->id, $draft]);
        $this->assertSame(1, $res['scenes']);
        $this->assertSame(5, $res['charged']);
        $this->assertSame('https://lms-labs.com/api/moodle/ai-language-teacher/lessons/import', $sent[0][0]);
        $this->assertSame(['sceneCount' => 1, 'titles' => ['Paying']], $sent[0][1]);
        \mod_ailanguageteacher\local\remote::$transport = null;
        foreach (['teacher', 'student'] as $who) {
            try {
                $this->call($who, 'save_scene', [(int)$this->scene->id, 'X', '', '', 0, []]);
                $this->fail($who . ' must not save scenes');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * Until LMS Labs has unlocked the plugin, nothing can be set up or studied.
     */
    public function test_locked_site(): void {
        unset_config('unlockstate', 'mod_ailanguageteacher');
        foreach (
            [['editingteacher', 'generate_lesson', [(int)$this->cm->id]],
                ['editingteacher', 'save_scene', [(int)$this->scene->id, 'X', '', '', 0, []]],
                ['student', 'start_attempt', [(int)$this->cm->id, 'practice']]] as [$user, $class, $args]
        ) {
            try {
                $this->call($user, $class, $args);
                $this->fail($class . ' worked on a locked site');
            } catch (\moodle_exception $e) {
                $this->assertSame('notactivated', $e->errorcode, $class);
            }
        }
        set_config('unlockstate', json_encode(['status' => 'unknown', 'wasunlocked' => true]), 'mod_ailanguageteacher');
        $this->assertTrue(\mod_ailanguageteacher\local\unlock::active());
    }

    /**
     * Resets the fake LMS Labs transport.
     */
    protected function tearDown(): void {
        \mod_ailanguageteacher\local\remote::$transport = null;
        parent::tearDown();
    }

    /**
     * AI generation reports "not available" until the LMS Labs route exists.
     */
    public function test_generate_lesson_unavailable(): void {
        $this->expectExceptionMessage(get_string('ainotavailable', 'mod_ailanguageteacher'));
        $this->call('editingteacher', 'generate_lesson', [(int)$this->cm->id]);
    }

    /**
     * Audio by phrase id is refused while the learner has a Test open.
     */
    public function test_audio_during_test(): void {
        global $DB;
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['sceneid' => $this->scene->id]);
        $res = $this->call('student', 'get_audio', [(int)$this->cm->id, 'p' . $phrase->id]);
        $this->assertSame('', $res['url'], 'No speech service: the browser speaks the phrase.');
        $this->call('student', 'start_attempt', [(int)$this->cm->id, 'test']);
        try {
            $this->call('student', 'get_audio', [(int)$this->cm->id, 'p' . $phrase->id]);
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('testinprogress', $e->errorcode);
        }
        $res = $this->call('editingteacher', 'get_audio', [(int)$this->cm->id, 'p' . $phrase->id]);
        $this->assertSame('', $res['url']);
    }

    /**
     * A learner cannot score speech in someone else's attempt.
     */
    public function test_assess_other_attempt(): void {
        $data = $this->call('student', 'start_attempt', [(int)$this->cm->id, 'practice']);
        $other = $this->getDataGenerator()->create_and_enrol(get_course($this->cm->course), 'student');
        $this->u['other'] = $other;
        $this->expectException(\moodle_exception::class);
        $this->call('other', 'assess_speech', [(int)$this->cm->id, 'practice', (int)$data['attemptid'],
            $data['scenes'][0]['answers'][0]['chip'], '', ['Hello']]);
    }
}
