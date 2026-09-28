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

use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for lesson drafts: prompt, parsing and import.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\lesson
 */
#[CoversClass(lesson::class)]
final class lesson_test extends \advanced_testcase {
    /**
     * The prompt includes the teacher's choices.
     */
    public function test_prompt(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('ailanguageteacher', ['course' => $course->id,
            'targetlang' => 'ja', 'targetlocale' => 'ja-JP', 'supportlang' => 'en', 'cefrlevel' => 'A2',
            'situations' => json_encode(['keys' => ['greetings', 'nonsense'], 'custom' => ['At the onsen'], 'scenes' => 1,
            'phrases' => 4])]);
        $prompt = lesson::prompt($instance);
        $this->assertStringContainsString('Japanese (ja-JP)', $prompt);
        $this->assertStringContainsString('CEFR A2', $prompt);
        $this->assertStringContainsString('- Greetings', $prompt);
        $this->assertStringContainsString('- At the onsen', $prompt);
        $this->assertStringNotContainsString('nonsense', $prompt);
        $this->assertStringContainsString('romaji', $prompt);
        $this->assertStringContainsString('exactly 2 useful phrases (never more than 2)', $prompt, 'Capped at two per picture.');
        $this->assertStringContainsString('takes place in Japan', $prompt);
        $this->assertStringContainsString('set in Japan and naming Japan', $prompt);
        $this->assertSame(['Greetings', 'At the onsen'], lesson::chosen_situations($instance));
    }

    /**
     * AI replies are read even with code fences and chatter; bad input is refused.
     */
    public function test_parse(): void {
        $fence = str_repeat(chr(96), 3);
        $raw = "Here you go:\n{$fence}json\n" . json_encode(['scenes' => [
            ['situation' => 'Greetings', 'title' => 'Arriving', 'context' => 'Morning', 'imageprompt' => 'A door',
                'phrases' => [['text' => 'Hello <b>there</b>', 'translation' => 'Hola', 'alternatives' => ['Hi', 'Hi']],
                    ['text' => '']], 'distractors' => ['Goodbye', ['text' => 'Night']]],
            ['title' => 'No phrases', 'phrases' => []],
        ]]) . "\n{$fence}\nEnjoy!";
        $draft = lesson::parse($raw);
        $this->assertCount(1, $draft['scenes']);
        $scene = $draft['scenes'][0];
        $this->assertSame('Hello there', $scene['phrases'][0]['text']);
        $this->assertSame('Hi', $scene['phrases'][0]['alternatives']);
        $this->assertCount(1, $scene['phrases']);
        $this->assertCount(2, $scene['distractors']);
        $this->assertSame(1, $scene['distractors'][0]['distractor']);
        try {
            lesson::parse('no json here');
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('lessoninvalid', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        lesson::parse('{"scenes": []}');
    }

    /**
     * Import creates situations, scenes and unplaced phrases, capped at the limits.
     */
    public function test_import(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('ailanguageteacher', ['course' => $course->id]);
        $phrases = [];
        for ($i = 1; $i <= 12; $i++) {
            $phrases[] = ['text' => "Phrase $i", 'translation' => "T$i", 'anchor' => 'Person'];
        }
        $counts = lesson::import($instance, ['scenes' => [
            ['situation' => 'Shops', 'title' => 'Paying', 'phrases' => $phrases, 'distractors' => [['text' => 'D1'],
                ['text' => 'D2'], ['text' => 'D3'], ['text' => 'D4']]],
            ['situation' => 'shops', 'title' => 'Asking', 'phrases' => [['text' => 'How much?']]],
        ]]);
        // Twelve phrases for one moment become six pictures of two; nothing is dropped.
        $this->assertSame(['scenes' => 7, 'phrases' => 13], $counts);
        $this->assertSame(1, $DB->count_records('ailanguageteacher_situation'));
        $this->assertSame(1, $DB->count_records('ailanguageteacher_scene', ['title' => 'Paying']));
        $this->assertSame(1, $DB->count_records('ailanguageteacher_scene', ['title' => 'Paying (6)']));
        foreach ($DB->get_records('ailanguageteacher_scene') as $scene) {
            $this->assertLessThanOrEqual(manager::MAX_PHRASES, $DB->count_records(
                'ailanguageteacher_phrase',
                ['sceneid' => $scene->id, 'distractor' => 0]
            ));
        }
        $this->assertSame(manager::MAX_DISTRACTORS, $DB->count_records('ailanguageteacher_phrase', ['distractor' => 1]));
        $this->assertSame(0, $DB->count_records('ailanguageteacher_phrase', ['placed' => 1]));
    }

    /**
     * The picture prompt lists where each phrase belongs and forbids text.
     */
    public function test_image_prompt(): void {
        $instance = (object)['imagestyle' => 'photo', 'targetlang' => 'es', 'targetlocale' => 'es-MX'];
        $scene = (object)['imageprompt' => 'A hotel reception', 'title' => 'Check in'];
        $prompt = lesson::image_prompt($instance, $scene, [(object)['anchor' => 'The receptionist', 'distractor' => 0],
            (object)['anchor' => 'Hidden', 'distractor' => 1]]);
        $this->assertStringContainsString('A hotel reception', $prompt);
        $this->assertStringContainsString('- The receptionist', $prompt);
        $this->assertStringNotContainsString('Hidden', $prompt);
        $this->assertStringContainsString('photograph', $prompt);
        $this->assertStringContainsString('No text', $prompt);
        $this->assertStringContainsString('Setting: Mexico.', $prompt);
        $this->assertStringContainsString('culturally appropriate for Mexico', $prompt);
        $instance->targetlocale = 'es-ES';
        $this->assertStringContainsString('Setting: Spain.', lesson::image_prompt($instance, $scene, []));
        $instance->targetlocale = 'es';
        $this->assertStringContainsString('a place where Spanish is spoken', lesson::image_prompt($instance, $scene, []));
    }

    /**
     * The AI rate limit.
     */
    public function test_ai_rate(): void {
        $this->resetAfterTest();
        set_config('airate', 2, 'mod_ailanguageteacher');
        lesson::log_ai(1, 5, 'lesson', 'ok');
        lesson::check_ai_rate(5);
        lesson::log_ai(1, 5, 'image', 'error');
        $this->expectException(\moodle_exception::class);
        lesson::check_ai_rate(5);
    }
}
