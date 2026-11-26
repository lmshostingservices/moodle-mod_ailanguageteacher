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
use mod_ailanguageteacher\local\byok\engine;
use mod_ailanguageteacher\local\credentials;
use mod_ailanguageteacher\local\pictures;
use mod_ailanguageteacher\local\remote;
use mod_ailanguageteacher\local\unlock;

/**
 * Bring your own AI: scenes, pictures and voices made on the site's own AI, with the service's checks, at no charge.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\byok
 * @covers     \mod_ailanguageteacher\local\byok\engine
 * @covers     \mod_ailanguageteacher\local\remote
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(remote::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked providers received: [url, body]. */
    private array $requests = [];

    /** @var array Tool answers the mocked Anthropic gives, in order. */
    private array $answers = [];

    /**
     * Licence the site for Bring your own AI on a test key: text and vision on Anthropic, pictures and voice on OpenAI,
     * and no LMS Labs credentials at all.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        $vendors = ['text' => 'anthropic', 'vision' => 'anthropic', 'image' => 'openai', 'voice' => 'openai'];
        foreach ($vendors as $service => $vendor) {
            set_config('src_' . $service, 'ownkey', 'local_aiconfig');
            set_config('vendor_' . $service, $vendor, 'local_aiconfig');
        }
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_openai', 'sk-test', 'local_aiconfig');
        set_config('aiimages', 1, 'mod_ailanguageteacher');
        credentials::$central = fn() => null;
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = [$url, $body];
            if (strpos($url, '/audio/speech') !== false) {
                return "\xff\xf3\x84" . str_repeat("\0", 381);
            }
            if (strpos($url, '/images/') !== false) {
                return ['data' => [['b64_json' => base64_encode($this->png(1536, 1024))]]];
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object)['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'tool_use', 'id' => 't', 'name' => $body['tools'][0]['name'],
                'input' => $answer]], 'stop_reason' => 'tool_use'];
        };
        // LMS Labs must never be called.
        remote::$transport = function ($url) {
            throw new \coding_exception('LMS Labs was called: ' . $url);
        };
        $this->setAdminUser();
    }

    /**
     * Clean up the mocks.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        remote::$transport = null;
        credentials::$central = null;
        engine::$text = engine::$image = engine::$vision = engine::$speech = null;
        byok::$lasterror = null;
        byok::$answered = false;
        parent::tearDown();
    }

    /**
     * A PNG of a size.
     *
     * @param int $w
     * @param int $h
     * @return string
     */
    private function png(int $w, int $h): string {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /**
     * An activity with one scene (no picture yet).
     *
     * @return array [instance, context, scene]
     */
    private function activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $gen->create_instance(['course' => $course->id, 'targetlang' => 'es', 'targetlocale' => 'es-ES',
            'cefrlevel' => 'A2']);
        $scene = $gen->create_scene($instance, 'At the café', [
            ['text' => 'Un café, por favor.', 'x' => 10, 'y' => 10, 'anchor' => 'the waiter'],
            ['text' => 'La cuenta, por favor.', 'x' => 60, 'y' => 40, 'anchor' => 'the bill'],
        ]);
        $context = \context_module::instance($instance->cmid);
        get_file_storage()->delete_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id);
        return [$DB->get_record('ailanguageteacher', ['id' => $instance->id]), $context,
            $DB->get_record('ailanguageteacher_scene', ['id' => $scene->id]), (int)$instance->cmid];
    }

    /**
     * A lesson draft as the AI writes it.
     *
     * @return array
     */
    private function lesson(): array {
        return ['title' => 'En el café', 'objective' => 'Order a drink politely.', 'explanation' => 'Use "por favor".',
            'vocabulary' => [['term' => 'Un café, por favor.', 'meaning' => 'A coffee, please.']],
            'practice' => [['prompt' => 'Ask for the bill.', 'answer' => 'La cuenta, por favor.']]];
    }

    /**
     * The plugin is usable and offers its AI work with only a Bring your own AI licence: no LMS Labs connection.
     */
    public function test_available_without_lmslabs(): void {
        $this->assertTrue(byok::any());
        $this->assertTrue(unlock::active());
        $this->assertTrue((new local\ai\lmslabs())->can_generate());
        $this->assertTrue(pictures::enabled());
        $this->assertNull(pictures::price_hold());
        $this->assertNull(audio::price_hold());
        $this->assertNull(audio::remakes());
        $this->assertTrue(audio::has_service_tts('es-ES'));
        $this->assertTrue(audio::has_service_tts('zh-CN'));
    }

    /**
     * A scene is drafted on the site's own AI with the service's instruction and schema, and nothing is charged.
     */
    public function test_draft(): void {
        global $DB;
        [$instance, , , $cmid] = $this->activity();
        $this->answers = [$this->lesson()];
        $result = external\generate_lesson::execute($cmid);
        $draft = json_decode($result['draft'], true);
        $this->assertSame('En el café', $draft['scenes'][0]['title']);
        $this->assertCount(1, $this->requests);
        [$url, $sent] = $this->requests[0];
        $this->assertStringEndsWith('/v1/messages', $url);
        $this->assertStringStartsWith(local\byok\prompts::LESSON . "\n\n" . local\byok\prompts::LESSON_LIMITS, $sent['system']);
        $user = json_decode($sent['messages'][0]['content'][0]['text'], true);
        $this->assertSame(['brief', 'locale', 'level', 'topic'], array_keys($user));
        $this->assertSame('es-ES', $user['locale']);
        $this->assertSame('beginner', $user['level']);
        $this->assertSame('lesson_draft', $sent['tools'][0]['name']);
        $this->assertSame(3500, $sent['max_tokens']);
        $op = $DB->get_record('ailanguageteacher_operation', ['id' => $result['draftid']]);
        $this->assertSame('complete', $op->state);
    }

    /**
     * An answer the service would refuse is refused here too, with nothing charged, and the next click starts afresh.
     */
    public function test_draft_refused(): void {
        global $DB;
        [, , , $cmid] = $this->activity();
        $this->answers = [['title' => '<b>Café</b>'] + $this->lesson()];
        try {
            external\generate_lesson::execute($cmid);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('byok_failed', $e->errorcode);
            $this->assertStringContainsString('INVALID_PROVIDER_RESULT', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('ailanguageteacher_operation', ['state' => 'pending']));
        // The provider fails: the teacher sees what it said.
        try {
            external\generate_lesson::execute($cmid);
            $this->fail('No exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('byok_failed', $e->errorcode);
            $this->assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    /**
     * Scenes from the teacher's own AI assistant are created free, without asking LMS Labs.
     */
    public function test_import(): void {
        [$instance, , , $cmid] = $this->activity();
        $json = json_encode(['scenes' => [['title' => 'At the market', 'phrases' => [['text' => 'Hola']]]]]);
        $result = external\import_lesson::execute($cmid, $json);
        $this->assertSame(1, $result['scenes']);
        $this->assertSame(0, $result['charged']);
        $this->assertSame([], $this->requests);
    }

    /**
     * A scene picture is drawn by the site's own AI, cut to 1600 × 1000, and its phrase targets are found by vision.
     */
    public function test_picture(): void {
        global $DB;
        [$instance, $context, $scene] = $this->activity();
        // Placement guesses, not yet checked by the teacher: a new picture moves them to what vision finds.
        $DB->set_field('ailanguageteacher_phrase', 'placed', 0, ['sceneid' => $scene->id]);
        $this->answers = [['positions' => [['x' => 12.345, 'y' => 20], null]]];
        $result = pictures::create($instance, $context, $scene, 2);
        $this->assertTrue($result['made']);
        $this->assertSame(0, $result['charged']);
        $image = $this->requests[0];
        $this->assertStringContainsString('/images/generations', $image[0]);
        $this->assertSame('1536x1024', $image[1]['size']);
        $start = 'Create one adult language-learning educational scene as an illustration.';
        $this->assertStringStartsWith($start, $image[1]['prompt']);
        $files = get_file_storage()->get_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id, 'id', false);
        $info = reset($files)->get_imageinfo();
        $this->assertSame([1600, 1000], [$info['width'], $info['height']]);
        [, $sent] = $this->requests[1];
        $this->assertSame('image_anchor_positions', $sent['tools'][0]['name']);
        $texts = array_column(array_filter($sent['messages'][0]['content'], fn($c) => $c['type'] === 'text'), 'text');
        $this->assertSame(['anchors' => ['the waiter', 'the bill']], json_decode(reset($texts), true));
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['text' => 'Un café, por favor.']);
        $this->assertEqualsWithDelta(12.35, (float)$phrase->x, 0.01);
    }

    /**
     * A phrase voice is made on the site's own voice: the closest OpenAI voice, MP3, at the service's speed.
     */
    public function test_voice(): void {
        global $DB;
        [$instance, $context] = $this->activity();
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['text' => 'La cuenta, por favor.']);
        $names = array_column((new local\speech\lmslabs())->voices('es-ES'), 'name');
        $this->assertContains('es-ES-Chirp3-HD-Charon', $names);
        $url = audio::create($instance, $context, $phrase, 'slow', 2, 'es-ES-Chirp3-HD-Charon');
        $this->assertNotSame('', $url);
        [$sent, $body] = $this->requests[0];
        $this->assertStringEndsWith('/audio/speech', $sent);
        $this->assertSame('La cuenta, por favor.', $body['input']);
        $this->assertSame('echo', $body['voice']);
        $this->assertEquals(0.8, $body['speed']);
        $op = $DB->get_record('ailanguageteacher_operation', ['kind' => 'tts']);
        $this->assertSame('complete', $op->state);
    }
}
