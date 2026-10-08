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

use core_external\external_api;
use mod_ailanguageteacher\external\generate_lesson;
use mod_ailanguageteacher\external\import_lesson;
use mod_ailanguageteacher\local\operation;
use mod_ailanguageteacher\local\remote;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Charging and recovery: every scene costs 3 credits whichever way it is made, nothing is charged twice, and a refused
 * or abandoned request never leaves the teacher stuck.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\operation
 * @covers     \mod_ailanguageteacher\external\import_lesson
 * @covers     \mod_ailanguageteacher\external\generate_lesson
 */
#[CoversClass(operation::class)]
#[CoversClass(import_lesson::class)]
#[CoversClass(generate_lesson::class)]
final class charging_test extends \advanced_testcase {
    /** @var array answers the fake LMS Labs gives, in order */
    protected $answers = [];

    /** @var array requests sent: [url, body, key] */
    protected $sent = [];

    /** @var \stdClass */
    protected $instance;

    /** @var \stdClass */
    protected $teacher;

    /**
     * An unlocked site with credentials, an activity, a teacher and a fake LMS Labs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('unlockstate', json_encode(['status' => 'unlocked', 'checkedat' => time()]), 'mod_ailanguageteacher');
        set_config('lmslabssiteid', 'site', 'mod_ailanguageteacher');
        set_config('lmslabsapikey', 'key', 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->instance = $gen->get_plugin_generator('mod_ailanguageteacher')->create_instance(['course' => $course->id]);
        $this->setUser($this->teacher);
        remote::$transport = function ($url, $headers, $body) {
            $key = substr(array_values(preg_grep('/^Idempotency-Key: /', $headers))[0] ?? 'Idempotency-Key: ', 17);
            $this->sent[] = [$url, json_decode((string)$body, true), $key];
            if (!$this->answers) {
                $this->fail('Unexpected request to LMS Labs: ' . $url);
            }
            return array_shift($this->answers);
        };
    }

    /**
     * Resets the transport.
     */
    protected function tearDown(): void {
        remote::$transport = null;
        parent::tearDown();
    }

    /**
     * Calls a web service as the teacher.
     *
     * @param string $class
     * @param array $args
     * @return array
     */
    protected function call(string $class, array $args): array {
        $class = '\\mod_ailanguageteacher\\external\\' . $class;
        return external_api::clean_returnvalue($class::execute_returns(), $class::execute(...$args));
    }

    /**
     * Course module id.
     *
     * @return int
     */
    protected function cmid(): int {
        return (int)get_coursemodule_from_instance('ailanguageteacher', $this->instance->id)->id;
    }

    /**
     * A scene draft as an AI assistant writes it.
     *
     * @param int $scenes
     * @return string
     */
    protected function assistant(int $scenes): string {
        $out = [];
        for ($i = 1; $i <= $scenes; $i++) {
            $out[] = ['title' => 'Scene ' . $i, 'phrases' => [['text' => 'Hello ' . $i]]];
        }
        return json_encode(['scenes' => $out]);
    }

    /**
     * An LMS Labs answer.
     *
     * @param int $status
     * @param array $body
     * @param array $headers
     * @return array
     */
    protected function answer(int $status, array $body, array $headers = []): array {
        return [$status, json_encode($body), [], $headers];
    }

    /**
     * AI-assistant scenes: refused (nothing created, nothing stuck), unconfirmed (same key, never a second charge),
     * then confirmed (created once, 3 credits per scene).
     */
    public function test_assistant_scenes_are_charged_once(): void {
        global $DB;
        $count = fn() => $DB->count_records('ailanguageteacher_scene', ['ailanguageteacherid' => $this->instance->id]);
        $start = $count();
        $this->answers = [$this->answer(402, ['error' => ['code' => 'INSUFFICIENT_CREDITS', 'message' => 'x']])];
        try {
            $this->call('import_lesson', [$this->cmid(), $this->assistant(2)]);
            $this->fail('Created without credits');
        } catch (\moodle_exception $e) {
            $this->assertSame('remoterefused_insufficient_credits', $e->errorcode);
        }
        $this->assertSame($start, $count());
        $this->assertSame(['sceneCount' => 2, 'titles' => ['Scene 1', 'Scene 2']], $this->sent[0][1]);
        $this->assertSame('failed', $DB->get_field('ailanguageteacher_operation', 'state', ['idemkey' => $this->sent[0][2]]));

        // No answer, then "still working": the same key both times; nothing is created yet.
        $this->answers = [[0, '', [], []], $this->answer(202, ['error' => 'PENDING'], ['retry-after' => '7'])];
        try {
            $this->call('import_lesson', [$this->cmid(), $this->assistant(2)]);
        } catch (\moodle_exception $e) {
            $this->assertSame('remoteuncertain', $e->errorcode);
        }
        try {
            $this->call('import_lesson', [$this->cmid(), $this->assistant(2)]);
        } catch (\moodle_exception $e) {
            $this->assertSame('remotepending', $e->errorcode);
            $this->assertStringContainsString('7 seconds', $e->getMessage());
        }
        $this->assertNotSame($this->sent[0][2], $this->sent[1][2], 'A refusal ends the request: a new click is new.');
        $this->assertSame($this->sent[1][2], $this->sent[2][2]);
        $this->assertSame($start, $count());

        // Confirmed: created, charged 6 for 2 scenes, with the same key.
        $this->answers = [$this->answer(200, ['requestId' => 'imp', 'creditsCharged' => 6, 'creditsBalance' => 30])];
        $res = $this->call('import_lesson', [$this->cmid(), $this->assistant(2)]);
        $this->assertSame(['scenes' => 2, 'phrases' => 2, 'charged' => 6, 'balance' => 30], $res);
        $this->assertSame($this->sent[2][2], $this->sent[3][2]);
        $this->assertSame($start + 2, $count());

        // Different content while one is unconfirmed: never sent unless the teacher says to start again.
        $this->answers = [[0, '', [], []]];
        try {
            $this->call('import_lesson', [$this->cmid(), $this->assistant(1)]);
        } catch (\moodle_exception $e) {
            $this->assertSame('remoteuncertain', $e->errorcode);
        }
        try {
            $this->call('import_lesson', [$this->cmid(), $this->assistant(3)]);
            $this->fail('Sent different content under an unconfirmed request.');
        } catch (\moodle_exception $e) {
            $this->assertSame('operationconflict', $e->errorcode);
        }
        $this->assertCount(5, $this->sent);
        $this->answers = [$this->answer(200, ['creditsCharged' => 9])];
        $res = $this->call('import_lesson', [$this->cmid(), $this->assistant(3), 0, true]);
        $this->assertSame(9, $res['charged']);
        $this->assertSame($start + 5, $count());
    }

    /**
     * An LMS Labs draft is charged when delivered; its scene is then created free, once.
     */
    public function test_lms_labs_draft_is_created_free_once(): void {
        global $DB;
        $this->answers = [$this->answer(200, ['requestId' => 'd1', 'draft' => ['title' => 'Greeting',
            'objective' => 'Greet', 'explanation' => 'Be polite', 'vocabulary' => [['term' => 'Hi', 'meaning' => 'Hello']],
            'practice' => [['prompt' => 'Greet someone', 'answer' => 'Hi there']]]])];
        $draft = $this->call('generate_lesson', [$this->cmid()]);
        $this->assertCount(1, $this->sent);
        $this->assertSame('https://lms-labs.com/api/moodle/ai-language-teacher/lessons/draft', $this->sent[0][0]);
        $res = $this->call('import_lesson', [$this->cmid(), $draft['draft'], $draft['draftid']]);
        $this->assertSame(0, $res['charged']);
        $this->assertSame(1, $res['scenes']);
        $this->assertCount(1, $this->sent, 'No second charge for an LMS Labs draft.');
        try {
            $this->call('import_lesson', [$this->cmid(), $draft['draft'], $draft['draftid']]);
            $this->fail('One paid draft created scenes twice.');
        } catch (\moodle_exception $e) {
            $this->assertSame('draftused', $e->errorcode);
        }
        // A delivered draft this site cannot use ends the request: it is never replayed for the same key.
        $this->answers = [$this->answer(200, ['requestId' => 'd2', 'draft' => ['title' => 'X', 'objective' => 'Y',
            'explanation' => 'Z', 'vocabulary' => 'not a list', 'practice' => []]])];
        try {
            $this->call('generate_lesson', [$this->cmid(), true]);
            $this->fail('An unusable draft was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('unusabledraft', $e->errorcode);
        }
        $this->assertSame('failed', $DB->get_field('ailanguageteacher_operation', 'state', ['idemkey' => $this->sent[1][2]]));
    }

    /**
     * What each answer means for a stored request.
     */
    public function test_outcomes(): void {
        $this->assertSame('pending', remote::outcome(202, ''));
        $this->assertSame('gone', remote::outcome(410, ''));
        $this->assertSame('uncertain', remote::outcome(0, ''));
        $this->assertSame('uncertain', remote::outcome(503, ''));
        $this->assertSame('uncertain', remote::outcome(500, json_encode(['error' => ['code' => 'SETTLEMENT_UNCONFIRMED']])));
        foreach ([400, 401, 402, 403, 404, 409, 413, 422, 429, 502] as $status) {
            $this->assertSame('refused', remote::outcome($status, ''), (string)$status);
        }
    }
}
