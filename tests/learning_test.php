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
use mod_ailanguageteacher\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the learning journey: Study, Practice mastery and Test marking.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\learning
 */
#[CoversClass(learning::class)]
final class learning_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $cm;
    /** @var \context_module */
    protected $context;
    /** @var \stdClass */
    protected $student;

    /**
     * Creates a course, a learner and an activity with two scenes (browser speech scoring).
     *
     * @param array $settings
     */
    protected function make(array $settings = []): void {
        $this->resetAfterTest();
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $this->instance = $lt->create_instance(['course' => $this->course->id, 'repetitions' => 2] + $settings);
        $lt->create_scene($this->instance, 'Arriving', [
            ['text' => 'Good morning!', 'translation' => 'Bonjour', 'alternatives' => 'Morning!', 'x' => 20, 'y' => 30],
            ['text' => 'Please come in.', 'translation' => 'Entrez', 'x' => 60, 'y' => 50],
            ['text' => 'Good night.', 'distractor' => 1],
        ]);
        $lt->create_scene($this->instance, 'Leaving', [['text' => 'Goodbye!', 'x' => 40, 'y' => 40]], 'Farewells');
        $this->cm = get_coursemodule_from_instance('ailanguageteacher', $this->instance->id);
        $this->context = \context_module::instance($this->cm->id);
        $this->instance = $GLOBALS['DB']->get_record('ailanguageteacher', ['id' => $this->instance->id]);
        $this->setUser($this->student);
    }

    /**
     * Starts an attempt for the learner.
     *
     * @param string $kind
     * @return array
     */
    protected function start(string $kind): array {
        return learning::start_attempt(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            $kind,
            (int)$this->student->id
        );
    }

    /**
     * Study shows every placed phrase with its details, and records progress.
     */
    public function test_study(): void {
        global $DB;
        $this->make();
        $data = learning::study_data($this->instance, $this->context);
        $this->assertCount(2, $data['scenes']);
        $this->assertCount(2, $data['scenes'][0]['pins']);
        $this->assertSame('Good morning!', $data['scenes'][0]['pins'][0]['text']);
        $this->assertSame('Bonjour', $data['scenes'][0]['pins'][0]['translation']);
        $ids = array_column($data['scenes'][0]['pins'], 'phraseid');
        learning::record_study(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            array_merge($ids, [999999]),
            false
        );
        $this->assertSame(2, $DB->count_records('ailanguageteacher_progress', ['state' => learning::STATE_STUDIED]));
        $this->assertFalse(learning::has_studied((int)$this->instance->id, (int)$this->student->id));
        learning::record_study($this->instance, $this->cm, $this->course, $this->context, (int)$this->student->id, [], true);
        $this->assertTrue(learning::has_studied((int)$this->instance->id, (int)$this->student->id));
    }

    /**
     * Practice gives answers and details; matching, hints and speaking lead to mastery.
     */
    public function test_practice_mastery(): void {
        global $DB;
        $this->make();
        $data = $this->start('practice');
        $scene = $data['scenes'][0];
        $this->assertCount(3, $scene['chips']);
        $this->assertCount(2, $scene['answers']);
        $this->assertEmpty($scene['listen']);
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $token = $scene['answers'][0]['chip'];
        $detail = array_values(array_filter($scene['details'], fn($d) => $d['token'] === $token))[0];
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['id' => $detail['phraseid']]);

        learning::practice_event($this->instance, $this->cm, $this->course, $this->context, $attempt, $token, 'hint', 0, 2);
        $res = learning::practice_event(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            $attempt,
            $token,
            'matched',
            2,
            2
        );
        $this->assertSame(learning::STATE_MATCHED, $res['state']);

        // Two successful tries (repetitions = 2) master the phrase; an accepted alternative counts.
        $r = learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            [$phrase->text],
            false
        );
        $this->assertSame(1, $r['passed']);
        $this->assertSame(1, $r['successes']);
        $this->assertSame(0, $r['mastered']);
        $r = learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            ['badly said words', 'nothing like it'],
            false
        );
        $this->assertSame(0, $r['passed']);
        $this->assertSame(1, $r['successes'], 'Not in a row: the count is kept.');
        $alt = $phrase->text === 'Good morning!' ? 'morning' : $phrase->text;
        $r = learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            [$alt],
            false
        );
        $this->assertSame(1, $r['mastered']);
        $row = $DB->get_record('ailanguageteacher_progress', ['phraseid' => $phrase->id]);
        $this->assertSame(learning::STATE_MASTERED, $row->state);
        $this->assertEquals(1, $row->hints);
        $this->assertEquals(2, $row->hintlevel);
        $this->assertEquals(3, $row->speaktries);
        $this->assertEquals(3, $DB->count_records('ailanguageteacher_speech', ['userid' => $this->student->id]));

        // The other phrases: move on without mastering.
        foreach ($data['scenes'] as $s) {
            foreach ($s['answers'] as $a) {
                if ($a['chip'] !== $token) {
                    learning::practice_event(
                        $this->instance,
                        $this->cm,
                        $this->course,
                        $this->context,
                        $attempt,
                        $a['chip'],
                        'moveon',
                        1,
                        0
                    );
                }
            }
        }
        $summary = learning::finish_attempt($this->instance, $this->cm, $this->course, $this->context, $attempt);
        $this->assertSame(1, $summary['practice']['mastered']);
        $this->assertSame(2, $summary['practice']['needspractice']);
        $this->assertEqualsWithDelta(33.3, $summary['percent'], 0.1);

        // Resuming practice sends the saved states.
        $again = $this->start('practice');
        $states = array_column($again['scenes'][0]['details'], 'state');
        $this->assertContains(learning::STATE_MASTERED, $states);
        learning::reset_progress($this->instance, $this->cm, $this->course, (int)$this->student->id);
        $this->assertSame(0, $DB->count_records('ailanguageteacher_progress', ['state' => learning::STATE_MASTERED]));
    }

    /**
     * With "in a row", a failed try starts the count again; distractors cannot be scored.
     */
    public function test_consecutive_and_distractor(): void {
        global $DB;
        $this->make(['consecutive' => 1]);
        $data = $this->start('practice');
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $token = $data['scenes'][1]['answers'][0]['chip'];
        $r = learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            ['Goodbye'],
            false
        );
        $this->assertSame(1, $r['successes']);
        $r = learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            ['hello there my friend'],
            false
        );
        $this->assertSame(0, $r['successes']);
        $distractor = $DB->get_record('ailanguageteacher_phrase', ['text' => 'Good night.']);
        $this->expectException(\moodle_exception::class);
        learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'study',
            null,
            'p' . $distractor->id,
            '',
            ['Good night'],
            false
        );
    }

    /**
     * A Test gives no answers, marks each stage on the server and grades with partial speaking credit.
     */
    public function test_test_marking(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->make();
        $data = $this->start('test');
        $this->assertSame(['match', 'listen', 'speak'], $data['stages']);
        $this->assertSame(9, $data['total']);
        $scene = $data['scenes'][0];
        $this->assertEmpty($scene['answers']);
        $this->assertEmpty($scene['details']);
        $this->assertArrayNotHasKey('translation', $scene['chips'][0]);
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $map = json_decode($attempt->tokenmap, true);

        // Match: first pin right, second pin given the distractor.
        $byphrase = array_flip($map['chips']);
        $pins = array_keys(array_filter($map['pins'], fn($p) => in_array($p, $map['scenes'][$scene['id']])));
        $distractor = $DB->get_record('ailanguageteacher_phrase', ['text' => 'Good night.']);
        $answers = [['item' => $pins[0], 'answer' => $byphrase[$map['pins'][$pins[0]]]],
            ['item' => $pins[1], 'answer' => $byphrase[$distractor->id]]];
        $this->assertSame(2, learning::submit_scene($this->instance, $attempt, $scene['id'], 'match', $answers));
        try {
            learning::submit_scene($this->instance, $attempt, $scene['id'], 'match', $answers);
            $this->fail('Resubmitting must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('scenealreadysubmitted', $e->errorcode);
        }
        // Listen: all correct.
        $pinfor = array_flip($map['pins']);
        $listen = [];
        foreach ($scene['listen'] as $item) {
            $listen[] = ['item' => $item['token'], 'answer' => $pinfor[$map['listen'][$item['token']]]];
        }
        learning::submit_scene($this->instance, $attempt, $scene['id'], 'listen', $listen);
        // Speak: one perfect, one poor (two tries max).
        foreach ($scene['speak'] as $i => $item) {
            $phrase = $DB->get_record('ailanguageteacher_phrase', ['id' => $map['speak'][$item['token']]]);
            $said = $i === 0 ? $phrase->text : 'zzzz';
            $r = learning::assess(
                $this->instance,
                $this->cm,
                $this->course,
                $this->context,
                (int)$this->student->id,
                'test',
                $attempt,
                $item['token'],
                '',
                [$said],
                false
            );
            $this->assertSame(0, $r['passed'], 'No marking is revealed during a Test.');
            $this->assertSame([], $r['units']);
            $this->assertSame(-1, $r['score']);
        }
        $item = $scene['speak'][1];
        learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'test',
            $attempt,
            $item['token'],
            '',
            ['zzzz'],
            false
        );
        try {
            learning::assess(
                $this->instance,
                $this->cm,
                $this->course,
                $this->context,
                (int)$this->student->id,
                'test',
                $attempt,
                $item['token'],
                '',
                ['zzzz'],
                false
            );
            $this->fail('Only two tries');
        } catch (\moodle_exception $e) {
            $this->assertSame('notriesleft', $e->errorcode);
        }
        // A speak token cannot fetch model audio.
        try {
            learning::resolve_phrase($this->instance, $item['token'], $attempt, 'listen');
            $this->fail('Speak tokens are not listening tokens');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidphrase', $e->errorcode);
        }
        $this->assertTrue(learning::test_in_progress($this->instance, (int)$this->student->id));

        $summary = learning::finish_attempt($this->instance, $this->cm, $this->course, $this->context, $attempt);
        // Match 1 + listen 2 + speak 1 = 4 of 9 (scene 2 unanswered).
        $this->assertEquals(4, $summary['correct']);
        $this->assertEqualsWithDelta(44.4, $summary['percent'], 0.1);
        $this->assertCount(2, $summary['review']);
        $this->assertSame(1, $summary['review'][0]['rows'][0]['match']);
        $this->assertSame('Good night.', $summary['review'][0]['rows'][1]['chosen']);
        $grades = grade_get_grades($this->course->id, 'mod', 'ailanguageteacher', $this->instance->id, $this->student->id);
        $this->assertEqualsWithDelta(44.44, $grades->items[0]->grades[$this->student->id]->grade, 0.01);
        $this->assertFalse(learning::test_in_progress($this->instance, (int)$this->student->id));
    }

    /**
     * Speaking credit is full at the pass score and half from 60% of it.
     */
    public function test_speak_credit(): void {
        $this->assertSame(1.0, learning::speak_credit(80, 80));
        $this->assertSame(0.5, learning::speak_credit(50, 80));
        $this->assertSame(0.0, learning::speak_credit(47, 80));
    }

    /**
     * Modes unlock in order; attempt limits and ownership are enforced.
     */
    public function test_locks_limits_ownership(): void {
        global $DB;
        $this->make(['sequential' => 1, 'maxattempts' => 1]);
        $this->assertTrue(learning::is_locked($this->instance, $this->context, 'practice', (int)$this->student->id));
        $this->assertTrue(learning::is_locked($this->instance, $this->context, 'test', (int)$this->student->id));
        try {
            $this->start('practice');
            $this->fail('Practice is locked');
        } catch (\moodle_exception $e) {
            $this->assertSame('modelocked', $e->errorcode);
        }
        learning::record_study($this->instance, $this->cm, $this->course, $this->context, (int)$this->student->id, [], true);
        $this->assertFalse(learning::is_locked($this->instance, $this->context, 'practice', (int)$this->student->id));
        $data = $this->start('practice');
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        foreach ($data['scenes'] as $s) {
            foreach ($s['answers'] as $a) {
                learning::practice_event(
                    $this->instance,
                    $this->cm,
                    $this->course,
                    $this->context,
                    $attempt,
                    $a['chip'],
                    'moveon',
                    1,
                    0
                );
            }
        }
        $this->assertFalse(learning::is_locked($this->instance, $this->context, 'test', (int)$this->student->id));
        $this->start('test');
        try {
            $this->start('test');
            $this->fail('Attempt limit');
        } catch (\moodle_exception $e) {
            $this->assertSame('nomoreattempts', $e->errorcode);
        }
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->expectException(\moodle_exception::class);
        learning::get_user_attempt((int)$attempt->id, (int)$other->id);
    }

    /**
     * Without speaking in Practice, a correct match masters the phrase; the Test drops speaking without a scorer.
     */
    public function test_no_speaking(): void {
        global $DB;
        $this->make(['speaking' => 0]);
        set_config('browserspeech', 0, 'mod_ailanguageteacher');
        $data = $this->start('practice');
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $res = learning::practice_event(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            $attempt,
            $data['scenes'][0]['answers'][0]['chip'],
            'matched',
            1,
            0
        );
        $this->assertSame(learning::STATE_MASTERED, $res['state']);
        $test = $this->start('test');
        $this->assertSame(['match', 'listen'], $test['stages']);
        $this->assertSame(['match'], learning::test_stages($this->instance, false, false));
    }

    /**
     * The leaderboard is limited to the learner's groups in separate groups mode.
     */
    public function test_leaderboard_groups(): void {
        global $DB;
        $this->make(['leaderboard' => 1]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance('ailanguageteacher', $this->instance->id);
        $gen = $this->getDataGenerator();
        $g1 = $gen->create_group(['courseid' => $this->course->id]);
        $g2 = $gen->create_group(['courseid' => $this->course->id]);
        $other = $gen->create_and_enrol($this->course, 'student');
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $this->student->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $other->id]);
        foreach ([$this->student, $other] as $u) {
            $DB->insert_record('ailanguageteacher_attempt', (object)['ailanguageteacherid' => $this->instance->id,
                'userid' => $u->id, 'attempt' => 1, 'kind' => 'test', 'state' => 'finished', 'tokenmap' => '{}',
                'stages' => 'match', 'timestart' => time(), 'timefinish' => time(), 'correct' => 1, 'total' => 1,
                'grade' => 100, 'duration' => 10]);
        }
        $board = learning::leaderboard($this->instance, $this->cm, (int)$this->student->id);
        $this->assertCount(1, $board);
        $this->assertSame(1, $board[0]['me']);
    }

    /**
     * Deleting data for one learner leaves the others.
     */
    public function test_delete_user_data(): void {
        global $DB;
        $this->make();
        $this->start('practice');
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        learning::start_attempt($this->instance, $this->cm, $this->course, $this->context, 'practice', (int)$other->id);
        learning::delete_user_data_for_user((int)$this->instance->id, (int)$this->student->id);
        $this->assertSame(0, $DB->count_records('ailanguageteacher_attempt', ['userid' => $this->student->id]));
        $this->assertSame(1, $DB->count_records('ailanguageteacher_attempt', ['userid' => $other->id]));
        learning::delete_all_user_data((int)$this->instance->id);
        $this->assertSame(0, $DB->count_records('ailanguageteacher_attempt'));
        $this->assertSame(4, $DB->count_records('ailanguageteacher_phrase'));
        $this->assertSame(2, count(manager::get_scenes((int)$this->instance->id)));
    }
}
