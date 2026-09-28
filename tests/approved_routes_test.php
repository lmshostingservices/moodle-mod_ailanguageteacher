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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Mocked dedicated text/speech API fixtures.
 *
 * @package mod_ailanguageteacher
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_ailanguageteacher;

use mod_ailanguageteacher\local\ai\lmslabs as text_provider;
use mod_ailanguageteacher\local\credentials;
use mod_ailanguageteacher\local\operation;
use mod_ailanguageteacher\local\remote;
use mod_ailanguageteacher\local\speech\lmslabs as speech_provider;

/** Mocked request/response contracts: no provider call or credits charged. */
final class approved_routes_test extends \advanced_testcase {
    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    public function test_exact_routes_headers_and_catalog_no_synthesis(): void {
        $this->resetAfterTest();
        credentials::$central = fn() => ['siteid' => 'site-fixture', 'apikey' => 'secret-fixture'];
        $calls = [];
        remote::$transport = function ($url, $headers, $body) use (&$calls) {
            $calls[] = compact('url', 'headers', 'body');
            if (str_ends_with($url, '/capabilities')) {
                return [200, json_encode(['locales' => [
                    ['locale' => 'en-AU', 'voices' => [['name' => 'en-AU-Chirp3-HD-Kore']]],
                ]]), []];
            }
            if (str_ends_with($url, '/speech/tts')) {
                return [410, '{"error":"RESULT_NOT_RETAINED","requestId":"fixture"}', []];
            }
            return [200, json_encode(['draft' => ['title' => 'Greeting', 'objective' => 'Greet someone',
                'explanation' => 'Be polite', 'vocabulary' => [['term' => 'Hi', 'meaning' => 'Hello']],
                'practice' => [['prompt' => 'Greet someone', 'answer' => 'Hi']]]]), []];
        };
        $speech = new speech_provider();
        $this->assertSame(['en-AU-Chirp3-HD-Kore'], array_column($speech->voices('en-AU'), 'name'));
        $this->assertFalse($speech->supports('en-CA', 'tts'));
        $this->assertFalse($speech->supports('en-AU', 'stt'));
        $this->assertCount(1, $calls);
        $this->assertSame('Greeting', (new text_provider())->draft(
            ['brief' => 'Greet someone', 'locale' => 'en-AU', 'level' => 'beginner'], 'fixture-key')['title']);
        $this->assertContains('Idempotency-Key: fixture-key', $calls[1]['headers']);
        $this->assertContains('X-Site-ID: site-fixture', $calls[1]['headers']);
        $this->assertStringNotContainsString('secret-fixture', $calls[1]['url']);
        $this->expectException(\moodle_exception::class);
        $speech->synthesise('Hi', 'en-AU', 'en-AU-Chirp3-HD-Kore', 'normal', 'same-fixture-key');
    }

    public function test_claim_is_durable_and_speech_text_is_not_stored(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('ailanguageteacher', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $body = ['text' => 'Good morning', 'locale' => 'en-AU',
            'speed' => 'normal', 'voice' => 'en-AU-Chirp3-HD-Kore'];
        $first = operation::claim((int)$instance->id, (int)$teacher->id, 'tts', 11, $body);
        $second = operation::claim((int)$instance->id, (int)$teacher->id, 'tts', 11, $body);
        $this->assertSame($first->idemkey, $second->idemkey);
        $this->assertSame('', $DB->get_field('ailanguageteacher_operation', 'body', ['id' => $first->id]));
        operation::expired($first);
        $new = operation::claim((int)$instance->id, (int)$teacher->id, 'tts', 11, $body);
        $this->assertNotSame($first->idemkey, $new->idemkey);
    }
}