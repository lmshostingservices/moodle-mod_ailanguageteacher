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

use mod_ailanguageteacher\local\audio;
use mod_ailanguageteacher\local\credentials;
use mod_ailanguageteacher\local\operation;
use mod_ailanguageteacher\local\remote;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Free voice remakes (LMS Labs, 9 Oct 2026): clipRef and maxCredits, the free price check, refused price rises, and
 * requests made before the upgrade asked about again exactly as they were sent. Mocked: nothing is charged.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\audio
 */
#[CoversClass(audio::class)]
final class remakes_test extends \advanced_testcase {
    /** @var array Requests sent. */
    protected $sent = [];

    /** @var array Answers to give to speech/tts, in order. */
    protected $answers = [];

    /** @var bool Whether the catalogue says LMS Labs supports remakes. */
    protected $supported = true;

    /**
     * Fakes LMS Labs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        credentials::$central = fn() => ['siteid' => 'site-fixture', 'apikey' => 'secret-fixture'];
        remote::$transport = function ($url, $headers, $body) {
            if (str_ends_with($url, '/capabilities')) {
                return [200, json_encode(['locales' => [['locale' => 'en-AU', 'voices' => [['name' => 'en-AU-Chirp3-HD-Kore']]]],
                    'tariff' => $this->supported ? ['tts' => 2, 'ttsRemake' => 0, 'ttsRemakeLimit' => 10,
                    'ttsRemakeWindowDays' => 30, 'clipRefSupported' => true, 'maxCreditsRequired' => true] : ['tts' => 2]]), []];
            }
            $this->sent[] = ['url' => $url, 'body' => json_decode((string)$body, true), 'headers' => $headers];
            if (str_ends_with($url, '/speech/quote')) {
                return [200, json_encode(['requestId' => 'q', 'credits' => 0, 'firstClip' => false,
                    'freeRemakesRemaining' => 9, 'windowDays' => 30, 'freeRemakeLimit' => 10]), []];
            }
            return array_shift($this->answers);
        };
    }

    /**
     * Resets the fakes.
     */
    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * An activity with one phrase, and a teacher.
     *
     * @return array [instance, context, phrase, teacher]
     */
    protected function activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $gen->create_instance(['course' => $course->id, 'targetlang' => 'en', 'targetlocale' => 'en-AU']);
        $scene = $gen->create_scene($instance, 'S', [['text' => 'Good morning', 'x' => 10, 'y' => 10]]);
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['sceneid' => $scene->id]);
        $context = \context_module::instance($instance->cmid);
        return [$DB->get_record('ailanguageteacher', ['id' => $instance->id]), $context, $phrase,
            $this->getDataGenerator()->create_user()];
    }

    /**
     * A delivered MP3 answer.
     *
     * @param int $charged
     * @return array
     */
    protected function mp3(int $charged): array {
        return [200, 'ID3' . str_repeat('m', 200), [], ['x-credits-charged' => (string)$charged]];
    }

    /**
     * Each voice carries its place and the confirmed price; the place stays when the phrase changes; a free voice made
     * again is sent with a ceiling of 0, and a price that went up is refused without a charge.
     */
    public function test_cliprefs_and_ceilings(): void {
        global $DB;
        [$instance, $context, $phrase, $teacher] = $this->activity();
        (new \mod_ailanguageteacher\local\speech\lmslabs())->capabilities();
        $this->assertSame(['limit' => 10, 'days' => 30], audio::remakes());
        $this->answers = [$this->mp3(2)];
        $url = audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore');
        $this->assertNotSame('', $url);
        $first = end($this->sent)['body'];
        $this->assertSame(['text', 'locale', 'speed', 'voice', 'clipRef', 'maxCredits'], array_keys($first));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['clipRef']);
        $this->assertSame(2, $first['maxCredits']);
        // The made voice is found again (its stored request had a clip reference).
        $this->assertSame($url, audio::existing_url($instance, $context, $phrase, 'normal'));
        // The phrase changes: same place, free price, sent with a ceiling of 0.
        $DB->set_field('ailanguageteacher_phrase', 'text', 'Good afternoon', ['id' => $phrase->id]);
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['id' => $phrase->id]);
        $this->assertSame('', audio::existing_url($instance, $context, $phrase, 'normal'));
        $this->assertSame(0, audio::quote($instance, $phrase, 'normal'));
        $this->assertSame(['clipRef'], array_keys(end($this->sent)['body']));
        $this->answers = [$this->mp3(0)];
        audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore', false, 0);
        $second = end($this->sent)['body'];
        $this->assertSame($first['clipRef'], $second['clipRef']);
        $this->assertSame(0, $second['maxCredits']);
        // The price went up meanwhile: refused before anything was made; the request is ended, nothing charged.
        $DB->set_field('ailanguageteacher_phrase', 'text', 'Good evening', ['id' => $phrase->id]);
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['id' => $phrase->id]);
        $this->answers = [[409, '{"error":"PRICE_CHANGED"}', []]];
        try {
            audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore', false, 0);
            $this->fail('A price rise must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('remoterefused_price_changed', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('ailanguageteacher_operation', ['kind' => 'tts', 'state' => 'pending']));
    }

    /**
     * A request left unresolved before the upgrade is asked about again with its key and its old body (no clipRef),
     * and audio made before the upgrade still counts as made.
     */
    public function test_requests_from_before_the_upgrade(): void {
        global $DB;
        [$instance, $context, $phrase, $teacher] = $this->activity();
        (new \mod_ailanguageteacher\local\speech\lmslabs())->capabilities();
        $legacy = ['text' => 'Good morning', 'locale' => 'en-AU', 'speed' => 'normal', 'voice' => 'en-AU-Chirp3-HD-Kore'];
        $old = operation::claim((int)$instance->id, (int)$teacher->id, 'tts', (int)$phrase->id * 3, $legacy);
        $this->answers = [$this->mp3(5)];
        audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore', false, 0);
        $sent = end($this->sent);
        $this->assertSame($legacy, $sent['body']);
        $this->assertContains('Idempotency-Key: ' . $old->idemkey, $sent['headers']);
        $this->assertSame('complete', $DB->get_field('ailanguageteacher_operation', 'state', ['id' => $old->id]));
        $this->assertNotSame('', audio::existing_url($instance, $context, $phrase, 'normal'));
        $this->assertTrue(audio::pending_with_voice($instance, $phrase, 'normal', 'x') === false);
    }

    /**
     * While LMS Labs does not support remakes, the body is as before and nothing is priced.
     */
    public function test_not_supported(): void {
        [$instance, $context, $phrase, $teacher] = $this->activity();
        $this->supported = false;
        (new \mod_ailanguageteacher\local\speech\lmslabs())->capabilities();
        $this->assertNull(audio::remakes());
        $this->assertSame(2, audio::quote($instance, $phrase, 'normal'));
        $this->answers = [$this->mp3(5)];
        audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore', false, 0);
        $this->assertSame(['text', 'locale', 'speed', 'voice'], array_keys(end($this->sent)['body']));
        $this->assertCount(1, $this->sent);
    }

    /**
     * While LMS Labs publishes another price per voice than the approved 2 credits, no new voice is asked for; a
     * voice asked for before (stored with the old ceiling of 5) is still asked about with exactly its old body.
     */
    public function test_price_hold(): void {
        global $DB;
        [$instance, $context, $phrase, $teacher] = $this->activity();
        $this->assertSame(2, \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS);
        $this->supported = true;
        $tariff = 5;
        remote::$transport = function ($url, $headers, $body) use (&$tariff) {
            if (str_ends_with($url, '/capabilities')) {
                return [200, json_encode(['locales' => [['locale' => 'en-AU', 'voices' => [['name' => 'en-AU-Chirp3-HD-Kore']]]],
                    'tariff' => ['tts' => $tariff, 'clipRefSupported' => true, 'maxCreditsRequired' => true]]), []];
            }
            $this->sent[] = ['url' => $url, 'body' => json_decode((string)$body, true), 'headers' => $headers];
            return array_shift($this->answers);
        };
        (new \mod_ailanguageteacher\local\speech\lmslabs())->capabilities();
        $this->assertSame(5, audio::price_hold());
        try {
            audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore');
            $this->fail('No voice may be made at a price the teacher was not shown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('voices_pricehold', $e->errorcode);
        }
        $this->assertSame([], $this->sent);
        // A request stored by 1.3.5 with a ceiling of 5 is still found and asked about unchanged.
        $old = ['text' => 'Good morning', 'locale' => 'en-AU', 'speed' => 'normal', 'voice' => 'en-AU-Chirp3-HD-Kore',
            'clipRef' => audio::clipref($instance, $phrase, 'normal'), 'maxCredits' => 5];
        $claim = operation::claim((int)$instance->id, (int)$teacher->id, 'tts', (int)$phrase->id * 3, $old);
        $this->answers = [$this->mp3(5)];
        audio::create($instance, $context, $phrase, 'normal', (int)$teacher->id, 'en-AU-Chirp3-HD-Kore');
        $this->assertSame($old, end($this->sent)['body']);
        $this->assertContains('Idempotency-Key: ' . $claim->idemkey, end($this->sent)['headers']);
        $this->assertSame('complete', $DB->get_field('ailanguageteacher_operation', 'state', ['id' => $claim->id]));
    }
}
