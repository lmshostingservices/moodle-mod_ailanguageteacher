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

use mod_ailanguageteacher\local\credentials;
use mod_ailanguageteacher\local\manager;
use mod_ailanguageteacher\local\pictures;
use mod_ailanguageteacher\local\remote;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * LMS Labs AI pictures (contract of 13 Oct 2026, 2 credits per picture). Mocked: nothing is charged.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\pictures
 */
#[CoversClass(pictures::class)]
final class pictures_test extends \advanced_testcase {
    /** @var array Picture requests sent. */
    protected $sent = [];

    /** @var array Answers to the picture route, in order. */
    protected $answers = [];

    /** @var array|null The capabilities answer [status, body]. */
    protected $caps = null;

    /**
     * Fakes LMS Labs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('aiimages', 1, 'mod_ailanguageteacher');
        credentials::$central = fn() => ['siteid' => 'site-fixture', 'apikey' => 'secret-fixture'];
        $this->caps = [200, json_encode(['tariff' => ['image' => 2], 'styles' => ['illustration', 'photo'],
            'maxPromptChars' => 2000])];
        remote::$transport = function ($url, $headers, $body) {
            if (str_ends_with($url, '/images/capabilities')) {
                return [$this->caps[0], $this->caps[1], []];
            }
            $this->sent[] = ['url' => $url, 'body' => json_decode((string)$body, true), 'headers' => $headers];
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
     * A real 16:10 PNG.
     *
     * @return string
     */
    protected static function png(): string {
        $im = imagecreatetruecolor(160, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /**
     * An activity with one scene and a teacher.
     *
     * @return array [instance, context, scene, teacher]
     */
    protected function activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $gen->create_instance(['course' => $course->id, 'imagestyle' => 'illustration']);
        $scene = $gen->create_scene($instance, 'At the door', [['text' => 'Good morning', 'x' => 10, 'y' => 10,
            'anchor' => 'the woman at the door']]);
        $DB->set_field(
            'ailanguageteacher_scene',
            'imageprompt',
            'A realistic photograph of a woman at a door in Peru.',
            ['id' => $scene->id]
        );
        $scene = $DB->get_record('ailanguageteacher_scene', ['id' => $scene->id]);
        // The generator gives every scene a picture: this scene starts without one.
        get_file_storage()->delete_area_files(
            \context_module::instance($instance->cmid)->id,
            'mod_ailanguageteacher',
            'sceneimage',
            $scene->id
        );
        return [$DB->get_record('ailanguageteacher', ['id' => $instance->id]), \context_module::instance($instance->cmid),
            $scene, $this->getDataGenerator()->create_user()];
    }

    /**
     * Asserts that creating a picture is refused with an error code and sends nothing.
     *
     * @param array $activity
     * @param string $code
     */
    protected function assert_refused(array $activity, string $code): void {
        [$instance, $context, $scene, $teacher] = $activity;
        try {
            pictures::create($instance, $context, $scene, (int)$teacher->id);
            $this->fail("Expected $code");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    /**
     * No picture is asked for while LMS Labs publishes no price or another price.
     */
    public function test_price_hold(): void {
        $activity = $this->activity();
        $this->caps = [404, '{"error":"NOT_FOUND"}'];
        $this->assertSame(0, pictures::price_hold());
        $this->assert_refused($activity, 'pictures_priceunknown');
        $this->caps = [200, json_encode(['tariff' => ['image' => 5]])];
        $this->assertSame(5, pictures::published_price(true));
        $this->assert_refused($activity, 'pictures_pricehold');
        $this->caps = [200, json_encode(['styles' => ['photo']])];
        $this->assertNull(pictures::published_price(true));
        $this->assert_refused($activity, 'pictures_priceunknown');
        $this->assertSame([], $this->sent);
        $this->caps = [200, json_encode(['tariff' => ['image' => 2]])];
        $this->assertSame(2, pictures::published_price(true));
        $this->assertNull(pictures::price_hold());
    }

    /**
     * A picture is asked for with exactly the prompt, the style and a ceiling of 2, saved, and never bought twice.
     */
    public function test_create_and_save(): void {
        global $DB;
        [$instance, $context, $scene, $teacher] = $this->activity();
        $this->answers = [[200, self::png(), [], ['content-type' => 'image/png', 'x-credits-charged' => '2',
            'x-request-id' => 'img-1']]];
        $result = pictures::create($instance, $context, $scene, (int)$teacher->id);
        $this->assertTrue($result['made']);
        $this->assertSame(2, $result['charged']);
        $this->assertStringContainsString('/sceneimage/', $result['url']);
        $sent = $this->sent[0];
        $this->assertStringEndsWith('/api/moodle/ai-language-teacher/images', $sent['url']);
        $this->assertSame(['prompt', 'style', 'maxCredits'], array_keys($sent['body']));
        $this->assertSame('illustration', $sent['body']['style']);
        $this->assertSame(2, $sent['body']['maxCredits']);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($sent['body']['prompt']));
        // The description's photo words follow the activity's style; the style is a firm rule; targets are named.
        $this->assertStringContainsString('An illustration of a woman at a door in Peru.', $sent['body']['prompt']);
        $this->assertStringContainsString('It must look drawn, never like a photograph', $sent['body']['prompt']);
        $this->assertStringContainsString('- the woman at the door', $sent['body']['prompt']);
        $this->assertStringNotContainsString('photograph of', $sent['body']['prompt']);
        $this->assertNotEmpty(preg_grep('/^Idempotency-Key: [0-9a-f-]{36}$/', $sent['headers']));
        $this->assertSame('complete', $DB->get_field('ailanguageteacher_operation', 'state', ['kind' => 'image']));
        // The scene has a picture now: asked again without "new picture", nothing is bought.
        $again = pictures::create($instance, $context, $scene, (int)$teacher->id);
        $this->assertFalse($again['made']);
        $this->assertCount(1, $this->sent);
        // A new picture is a new request with a new key.
        $this->answers = [[200, self::png(), [], ['x-credits-charged' => '2']]];
        pictures::create($instance, $context, $scene, (int)$teacher->id, true);
        $this->assertCount(2, $this->sent);
        $this->assertNotSame(
            preg_grep('/^Idempotency-Key/', $this->sent[0]['headers']),
            preg_grep('/^Idempotency-Key/', $this->sent[1]['headers'])
        );
    }

    /**
     * A price rise is refused before anything is made; an unknown outcome keeps the key, and "Check again" sends the
     * same key and body; a result LMS Labs no longer keeps says so and never makes it again on its own.
     */
    public function test_outcomes(): void {
        global $DB;
        [$instance, $context, $scene, $teacher] = $this->activity();
        $this->answers = [[409, '{"error":"PRICE_CHANGED","requestId":"r1"}', []]];
        $this->assert_refused([$instance, $context, $scene, $teacher], 'remoterefused_price_changed');
        $this->assertSame('failed', $DB->get_field('ailanguageteacher_operation', 'state', ['kind' => 'image']));
        $this->assertSame([], manager::get_scene_image($context, (int)$scene->id)[0] ? ['x'] : []);
        // Unknown outcome: kept with its key.
        $this->answers = [[503, '{"error":"SETTLEMENT_UNCONFIRMED","requestId":"r2"}', []]];
        $this->assert_refused([$instance, $context, $scene, $teacher], 'remoteuncertain');
        $pending = $DB->get_record('ailanguageteacher_operation', ['kind' => 'image', 'state' => 'pending']);
        $this->assertNotEmpty($pending);
        // Asked again: the same key and the exact same body, even while the price check would now hold.
        $this->caps = [200, json_encode(['tariff' => ['image' => 5]])];
        pictures::published_price(true);
        $this->answers = [[410, '{"error":"RESULT_NOT_RETAINED","requestId":"r3"}', []]];
        $this->assert_refused([$instance, $context, $scene, $teacher], 'pictures_notretained');
        $last = end($this->sent);
        $this->assertContains('Idempotency-Key: ' . $pending->idemkey, $last['headers']);
        $this->assertSame($this->sent[1]['body'], $last['body']);
        $this->assertSame('expired', $DB->get_field('ailanguageteacher_operation', 'state', ['id' => $pending->id]));
        // A provider failure is "not created, not charged" and ends the request.
        $this->caps = [200, json_encode(['tariff' => ['image' => 2]])];
        pictures::published_price(true);
        $this->answers = [[502, '{"error":"IMAGE_FAILED","requestId":"r4"}', []]];
        try {
            pictures::create($instance, $context, $scene, (int)$teacher->id);
            $this->fail('Expected a refusal');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('remoteuncertain', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('ailanguageteacher_operation', ['kind' => 'image', 'state' => 'pending']));
    }

    /**
     * A long scene description is shortened so the prompt stays within 2,000 code points; the rules stay whole.
     */
    public function test_prompt_limit(): void {
        global $DB;
        [$instance, , $scene] = $this->activity();
        $DB->set_field(
            'ailanguageteacher_scene',
            'imageprompt',
            str_repeat('Ein großer Platz in Perú mit Menschen. ', 80),
            ['id' => $scene->id]
        );
        $scene = $DB->get_record('ailanguageteacher_scene', ['id' => $scene->id]);
        $body = pictures::body($instance, $scene);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($body['prompt']));
        $this->assertStringContainsString('No text, letters, numbers', $body['prompt']);
        $this->assertSame($body, pictures::body($instance, $scene), 'The same scene always gives the same body.');
    }
}
