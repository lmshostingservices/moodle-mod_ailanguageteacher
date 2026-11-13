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
use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\manager;
use mod_ailanguageteacher\local\speech\service;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for 1.5.0: conversations, the given first line and stricter speech checks.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\learning
 */
#[CoversClass(learning::class)]
final class conversation_test extends \advanced_testcase {
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
     * Creates an activity with a four-line conversation and a one-phrase picture.
     *
     * @param array $settings
     */
    protected function make(array $settings = []): void {
        global $DB;
        $this->resetAfterTest();
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $this->instance = $lt->create_instance(['course' => $this->course->id] + $settings);
        // Placed right to left on purpose: a conversation keeps its spoken order, not its place on the picture.
        $lt->create_scene($this->instance, 'At the bakery', [
            ['text' => 'Good morning! What would you like?', 'x' => 80, 'y' => 20],
            ['text' => 'Two croissants, please.', 'x' => 60, 'y' => 30],
            ['text' => 'Anything else?', 'x' => 40, 'y' => 40],
            ['text' => 'No, thank you.', 'x' => 20, 'y' => 50],
            ['text' => 'Good night.', 'distractor' => 1],
        ]);
        $lt->create_scene($this->instance, 'Leaving', [['text' => 'Goodbye!', 'x' => 40, 'y' => 40]], 'Farewells');
        $this->cm = get_coursemodule_from_instance('ailanguageteacher', $this->instance->id);
        $this->context = \context_module::instance($this->cm->id);
        $this->instance = $DB->get_record('ailanguageteacher', ['id' => $this->instance->id]);
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
     * Pictures with three or more phrases are numbered in the order they are said.
     */
    public function test_conversation_order(): void {
        $this->make();
        $ready = manager::ready_scenes($this->instance, $this->context);
        $texts = array_map(fn($p) => $p->text, $ready[0][1]);
        $this->assertSame(
            ['Good morning! What would you like?', 'Two croissants, please.', 'Anything else?', 'No, thank you.'],
            $texts
        );
        $this->assertSame(6, manager::MAX_PHRASES);
    }

    /**
     * Without "Show the first line" every phrase is a question.
     */
    public function test_no_given_line(): void {
        $this->make();
        $data = $this->start('test');
        $this->assertSame(15, $data['total']);
        foreach ($data['scenes'][0]['pins'] as $pin) {
            $this->assertArrayNotHasKey('given', $pin);
        }
        $this->assertSame(5, learning::practice_counts($this->instance, $this->context, (int)$this->student->id)['total']);
    }

    /**
     * The first line is shown in place, is not a chip, and is not marked; one-phrase pictures are unchanged.
     */
    public function test_given_first_line(): void {
        global $DB;
        $this->make(['givenfirst' => 1]);
        foreach (['practice', 'test'] as $kind) {
            $data = $this->start($kind);
            $scene = $data['scenes'][0];
            $given = array_values(array_filter($scene['pins'], fn($p) => !empty($p['given'])));
            $this->assertCount(1, $given);
            $this->assertSame(1, $given[0]['number']);
            $this->assertSame('Good morning! What would you like?', $given[0]['given']['text']);
            $chips = array_column($scene['chips'], 'text');
            $this->assertNotContains('Good morning! What would you like?', $chips);
            $this->assertCount(4, $chips, 'Three phrases and the distractor.');
            $this->assertEmpty(array_filter($data['scenes'][1]['pins'], fn($p) => !empty($p['given'])));
            if ($kind === 'practice') {
                $this->assertCount(3, $scene['answers']);
            } else {
                $this->assertSame(12, $data['total']);
                $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
                $map = json_decode($attempt->tokenmap, true);
                $first = $DB->get_record('ailanguageteacher_phrase', ['text' => 'Good morning! What would you like?']);
                $this->assertNotContains((int)$first->id, $map['pins']);
                $this->assertNotContains((int)$first->id, $map['speak']);
                $this->assertNotContains((int)$first->id, $map['listen']);
                $this->assertArrayNotHasKey($given[0]['token'], $map['pins']);
            }
        }
        $this->assertSame(4, learning::practice_counts($this->instance, $this->context, (int)$this->student->id)['total']);
    }

    /**
     * given_id picks the lowest sort order, and needs at least two phrases.
     */
    public function test_given_id(): void {
        $instance = (object)['givenfirst' => 1];
        $pins = [
            (object)['id' => 7, 'sortorder' => 2],
            (object)['id' => 9, 'sortorder' => 1],
            (object)['id' => 3, 'sortorder' => 3],
        ];
        $this->assertSame(9, learning::given_id($instance, $pins));
        $this->assertSame(0, learning::given_id($instance, [$pins[0]]));
        $this->assertSame(0, learning::given_id((object)['givenfirst' => 0], $pins));
    }

    /**
     * Assesses a practice phrase.
     *
     * @param array $transcripts
     * @param float $confidence
     * @return array
     */
    protected function practise(array $transcripts, float $confidence = -1): array {
        global $DB;
        $data = $this->start('practice');
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $token = $data['scenes'][1]['answers'][0]['chip'];
        return learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            'practice',
            $attempt,
            $token,
            '',
            $transcripts,
            false,
            $confidence
        );
    }

    /**
     * Only the browser's first guess is scored: a lower guess that happens to match does not count.
     */
    public function test_first_transcript_only(): void {
        $this->make();
        $r = $this->practise(['good buy', 'Goodbye!']);
        $this->assertSame(0, $r['passed']);
        $r = $this->practise(['Goodbye', 'good buy']);
        $this->assertSame(1, $r['passed']);
    }

    /**
     * A low-confidence result asks the learner to say it again and is not counted.
     */
    public function test_unsure(): void {
        global $DB;
        $this->make();
        set_config('minconfidence', 60, 'mod_ailanguageteacher');
        $r = $this->practise(['Goodbye'], 0.35);
        $this->assertSame(service::STATUS_UNSURE, $r['status']);
        $this->assertFalse((bool)$r['counted']);
        $this->assertSame(0, $r['successes']);
        $this->assertEquals(1, $DB->get_field('ailanguageteacher_progress', 'speaktries', ['userid' => $this->student->id]));
        // Sure enough, or a browser that gives no confidence: scored as usual.
        $this->assertSame(1, $this->practise(['Goodbye'], 0.8)['passed']);
        $this->assertSame(1, $this->practise(['Goodbye'], -1)['passed']);
        // Switched off.
        set_config('minconfidence', 0, 'mod_ailanguageteacher');
        $this->assertSame(1, $this->practise(['Goodbye'], 0.1)['passed']);
    }

    /**
     * The AI-assistant instructions ask for an ordered conversation from three phrases.
     */
    public function test_prompt_conversation(): void {
        $this->make();
        $instance = clone $this->instance;
        $instance->situations = json_encode(['keys' => [], 'custom' => ['At the bakery'], 'scenes' => 2, 'phrases' => 5]);
        $prompt = lesson::prompt($instance);
        $this->assertStringContainsString('exactly 5 useful phrases', $prompt);
        $this->assertStringContainsString('one short conversation', $prompt);
        $instance->situations = json_encode(['keys' => [], 'custom' => ['At the bakery'], 'scenes' => 2, 'phrases' => 2]);
        $this->assertStringNotContainsString('one short conversation', lesson::prompt($instance));
        $instance->situations = null;
        $this->assertSame(2, lesson::options($instance)['phrases']);
    }

    /**
     * Six phrases can be imported onto one picture, in order.
     */
    public function test_import_six(): void {
        global $DB;
        $this->make();
        $phrases = [];
        for ($i = 1; $i <= 6; $i++) {
            $who = $i % 2 ? 'Anna, the woman' : 'Ben, the man';
            $phrases[] = ['text' => "Line $i", 'translation' => "Ligne $i", 'anchor' => $who];
        }
        $counts = lesson::import($this->instance, ['scenes' => [['situation' => 'Talk', 'title' => 'Six lines',
            'context' => 'A chat.', 'imageprompt' => 'Two people.', 'phrases' => $phrases, 'distractors' => []]]]);
        $this->assertSame(['scenes' => 1, 'phrases' => 6], $counts);
        $scene = $DB->get_record('ailanguageteacher_scene', ['title' => 'Six lines']);
        $texts = $DB->get_fieldset_select('ailanguageteacher_phrase', 'text', 'sceneid = ?', [$scene->id], 'sortorder');
        $this->assertSame(['Line 1', 'Line 2', 'Line 3', 'Line 4', 'Line 5', 'Line 6'], $texts);
    }
}
