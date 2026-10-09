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
use mod_ailanguageteacher\local\learning;
use mod_ailanguageteacher\local\speech\factory;
use mod_ailanguageteacher\local\speech\lmslabs;
use mod_ailanguageteacher\local\speech\matcher;
use mod_ailanguageteacher\local\speech\service;
use mod_ailanguageteacher\local\speech\wav;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the speech service layer: recording checks, the spoken-answer check and cached phrase audio.
 *
 * A fake service stands in for LMS Labs; no network calls are made.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\audio
 * @covers     \mod_ailanguageteacher\local\speech\wav
 * @covers     \mod_ailanguageteacher\local\speech\lmslabs
 */
#[CoversClass(audio::class)]
#[CoversClass(wav::class)]
#[CoversClass(lmslabs::class)]
final class speech_test extends \advanced_testcase {
    /** @var object fake service */
    protected $fake;
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
     * Resets the service override.
     */
    protected function tearDown(): void {
        factory::$override = null;
        parent::tearDown();
    }

    /**
     * A WAV file of the given length.
     *
     * @param float $seconds
     * @param int $rate
     * @param int $channels
     * @return string
     */
    protected static function wav(float $seconds, int $rate = 16000, int $channels = 1): string {
        $data = str_repeat("\x01\x00", (int)round($seconds * $rate * $channels));
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack(
            'VvvVVvv',
            16,
            1,
            $channels,
            $rate,
            $rate * 2 * $channels,
            2 * $channels,
            16
        ) . 'data' . pack('V', strlen($data)) . $data;
    }

    /**
     * Installs a fake speech service that records its calls.
     *
     * @param array $reply what transcribe() returns
     * @return object
     */
    protected function fake(array $reply = []): object {
        $this->fake = new class ($reply) implements service {
            /** @var array calls made */
            public $calls = ['tts' => [], 'stt' => []];
            /** @var array transcription reply */
            public $reply;
            /** @var bool */
            public $on = true;
            /** @var string */
            public $identity = 'fake-v1';

            /**
             * Constructor.
             *
             * @param array $reply
             */
            public function __construct(array $reply) {
                $this->reply = $reply;
            }

            /**
             * Available.
             *
             * @return bool
             */
            public function available(): bool {
                return $this->on;
            }

            /**
             * Supports.
             *
             * @param string $locale
             * @param string $operation
             * @return bool
             */
            public function supports(string $locale, string $operation): bool {
                return $locale === 'en-AU';
            }

            /**
             * Identity.
             *
             * @return string
             */
            public function tts_identity(): string {
                return $this->identity;
            }

            /**
             * Synthesise.
             *
             * @param string $text
             * @param string $locale
             * @param string $voice
             * @param string $speed
             * @param string $idemkey
             * @return array
             */
            public function synthesise(
                string $text,
                string $locale,
                string $voice,
                string $speed,
                string $idemkey,
                ?string $clipref = null,
                ?int $maxcredits = null
            ): array {
                $this->calls['tts'][] = [$text, $locale, $speed, $idemkey];
                return ['audio' => 'ID3' . str_repeat('m', 200), 'mimetype' => 'audio/mpeg', 'requestid' => 'r1'];
            }

            /**
             * Transcribe.
             *
             * @param string $wav
             * @param string $locale
             * @param array $hints
             * @param string $idemkey
             * @return array
             */
            public function transcribe(string $wav, string $locale, array $hints, string $idemkey): array {
                $this->calls['stt'][] = [strlen($wav), $locale, $hints, $idemkey];
                return $this->reply + ['requestid' => 'r2'];
            }
        };
        factory::$override = $this->fake;
        return $this->fake;
    }

    /**
     * Creates a course, learner and activity with one scene.
     */
    protected function make(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->student = $gen->create_and_enrol($this->course, 'student');
        $lt = $gen->get_plugin_generator('mod_ailanguageteacher');
        $this->instance = $lt->create_instance(['course' => $this->course->id, 'repetitions' => 2, 'passscore' => 80]);
        $lt->create_scene($this->instance, 'Arriving', [
            ['text' => 'Good morning, Maria!', 'alternatives' => 'Morning, Maria!', 'x' => 20, 'y' => 30,
                'example' => 'Good morning, how are you?'],
            ['text' => 'Please come in.', 'x' => 60, 'y' => 50],
        ]);
        $this->cm = get_coursemodule_from_instance('ailanguageteacher', $this->instance->id);
        $this->context = \context_module::instance($this->cm->id);
        $this->instance = $GLOBALS['DB']->get_record('ailanguageteacher', ['id' => $this->instance->id]);
        $this->setUser($this->student);
    }

    /**
     * Starts Practice and returns [attempt, token of the first phrase, phrase].
     *
     * @return array
     */
    protected function practice(): array {
        global $DB;
        $data = learning::start_attempt(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            'practice',
            (int)$this->student->id
        );
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $scene = $data['scenes'][0];
        $token = $scene['answers'][0]['chip'];
        $detail = array_values(array_filter($scene['details'], fn($d) => $d['token'] === $token))[0];
        return [$attempt, $token, $DB->get_record('ailanguageteacher_phrase', ['id' => $detail['phraseid']])];
    }

    /**
     * Sends one spoken answer.
     *
     * @param string $kind
     * @param \stdClass|null $attempt
     * @param string $ref
     * @param string $wav
     * @param array $transcripts
     * @return array
     */
    protected function assess(string $kind, ?\stdClass $attempt, string $ref, string $wav, array $transcripts = []): array {
        return learning::assess(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            (int)$this->student->id,
            $kind,
            $attempt,
            $ref,
            $wav,
            $transcripts,
            false
        );
    }

    /**
     * Recordings are checked in PHP: format, sample rate, channels and length.
     */
    public function test_wav_validation(): void {
        $this->assertEqualsWithDelta(1.0, wav::validate(self::wav(1)), 0.001);
        $this->assertEqualsWithDelta(8.0, wav::validate(self::wav(8)), 0.001);
        foreach ([self::wav(8.5), self::wav(1, 44100), self::wav(1, 16000, 2), self::wav(0.05), 'RIFF1234WAVEjunk', ''] as $bad) {
            try {
                wav::validate($bad);
                $this->fail('Expected a bad recording error');
            } catch (\moodle_exception $e) {
                $this->assertSame('badrecording', $e->errorcode);
            }
        }
    }

    /**
     * Words recognised are counted in order; languages without spaces count characters.
     */
    public function test_words_recognised(): void {
        $this->resetAfterTest();
        $r = matcher::recognised('good morning', 'Good morning, Maria!', false);
        $this->assertSame(2, $r['found']);
        $this->assertSame(3, $r['total']);
        $this->assertSame([['text' => 'Good', 'ok' => true], ['text' => 'morning,', 'ok' => true],
            ['text' => 'Maria!', 'ok' => false]], $r['units']);
        $this->assertSame(0, matcher::recognised('morning good', 'good', false)['found'] - 1);
        $this->assertSame(0, matcher::recognised('', 'Hello', false)['found']);
        $thai = matcher::recognised('สวัสดี', 'สวัสดีค่ะ', true);
        $this->assertSame(6, $thai['found']);
        $this->assertSame(9, $thai['total']);
        [$score, $heard, $target] = matcher::best(['morning maria'], 'Good morning, Maria!', ['Morning, Maria!'], false);
        $this->assertSame(100.0, $score);
        $this->assertSame('morning maria', $heard);
        $this->assertSame('Morning, Maria!', $target);
    }

    /**
     * The LMS Labs service stays off: no locale, no calls, and speaking falls back to the browser or self-check.
     */
    public function test_lmslabs_is_off(): void {
        $this->resetAfterTest();
        $service = factory::get();
        $this->assertInstanceOf(lmslabs::class, $service);
        $this->assertFalse($service->available());
        $this->assertFalse($service->supports('en-AU', 'tts'));
        $this->assertFalse($service->supports('en-AU', 'stt'));
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $this->assertSame('browser', audio::speaking_mode('en-AU'));
        set_config('browserspeech', 0, 'mod_ailanguageteacher');
        $this->assertSame('self', audio::speaking_mode('en-AU'));
        $this->assertFalse(audio::has_service_tts('en-AU'));
        $this->expectException(\moodle_exception::class);
        $service->transcribe(self::wav(1), 'en-AU', [], 'k');
    }

    /**
     * The service transcribes a valid recording; the result is a words-recognised check against the phrase.
     */
    public function test_service_transcription(): void {
        global $DB;
        $this->make();
        $fake = $this->fake(['status' => service::STATUS_OK, 'alternatives' => ['good morning maria', 'good morning']]);
        $this->assertSame('service', audio::speaking_mode('en-AU'));
        [$attempt, $token, $phrase] = $this->practice();
        $r = $this->assess('practice', $attempt, $token, self::wav(1.5));
        $this->assertSame('service', $r['provider']);
        $this->assertSame(1, $r['counted']);
        $this->assertSame(100, $r['score']);
        $this->assertSame(3, $r['found']);
        $this->assertSame(3, $r['total']);
        $this->assertSame(1, $r['passed']);
        $this->assertSame(1, $r['successes']);
        $this->assertSame(['Good morning, Maria!', 'Morning, Maria!'], $fake->calls['stt'][0][2]);
        $this->assertSame('en-AU', $fake->calls['stt'][0][1]);
        $this->assertNotSame('', $fake->calls['stt'][0][3], 'An idempotency key is sent.');
        $row = $DB->get_record('ailanguageteacher_speech', ['phraseid' => $phrase->id]);
        $this->assertSame('service', $row->provider);
        $this->assertSame('good morning maria', $row->recognised);

        // A recording that fails the PHP checks never reaches the service.
        try {
            $this->assess('practice', $attempt, $token, self::wav(9));
            $this->fail('Expected a bad recording error');
        } catch (\moodle_exception $e) {
            $this->assertSame('badrecording', $e->errorcode);
        }
        $this->assertCount(1, $fake->calls['stt']);
    }

    /**
     * Silence and unclear speech get no score and do not count: no progress change, no stored result, no Test try used.
     */
    public function test_silence_does_not_count(): void {
        global $DB;
        $this->make();
        $fake = $this->fake(['status' => service::STATUS_NOSPEECH, 'alternatives' => []]);
        [$attempt, $token, $phrase] = $this->practice();
        $r = $this->assess('practice', $attempt, $token, self::wav(1));
        $this->assertSame(0, $r['counted']);
        $this->assertSame('nospeech', $r['status']);
        $this->assertSame(-1, $r['score']);
        $this->assertSame(0, $r['passed']);
        $fake->reply = ['status' => service::STATUS_OK, 'alternatives' => ['  ']];
        $r = $this->assess('practice', $attempt, $token, self::wav(1));
        $this->assertSame('unintelligible', $r['status']);
        $this->assertSame(0, $r['counted']);
        $fake->reply = ['status' => service::STATUS_UNINTELLIGIBLE];
        $this->assertSame(0, $this->assess('practice', $attempt, $token, self::wav(1))['counted']);
        $this->assertSame(0, $DB->count_records('ailanguageteacher_speech'));
        $row = $DB->get_record('ailanguageteacher_progress', ['phraseid' => $phrase->id]);
        $this->assertTrue(!$row || (int)$row->speaktries === 0);

        // The browser's recognition hearing nothing is treated the same way.
        factory::$override = null;
        set_config('browserspeech', 1, 'mod_ailanguageteacher');
        $r = $this->assess('practice', $attempt, $token, '', []);
        $this->assertSame(0, $r['counted']);
        $this->assertSame('nospeech', $r['status']);

        // In a Test, silence does not use up one of the two tries.
        $this->fake(['status' => service::STATUS_NOSPEECH]);
        $data = learning::start_attempt(
            $this->instance,
            $this->cm,
            $this->course,
            $this->context,
            'test',
            (int)$this->student->id,
            true,
            true
        );
        $test = $DB->get_record('ailanguageteacher_attempt', ['id' => $data['attemptid']]);
        $item = $data['scenes'][0]['speak'][0];
        $r = $this->assess('test', $test, $item['token'], self::wav(1));
        $this->assertSame(0, $r['counted']);
        $this->assertSame(2, $r['triesleft']);
        $this->assertSame(0, $DB->count_records('ailanguageteacher_response', ['attemptid' => $test->id]));
    }

    /**
     * Learners never cause a voice to be made: only the teacher's explicit action does, once per text, voice and
     * service settings, and the saved voice is then reused by everyone.
     */
    public function test_phrase_audio_cache(): void {
        $this->make();
        $fake = $this->fake();
        $phrase = $GLOBALS['DB']->get_record('ailanguageteacher_phrase', ['text' => 'Please come in.']);
        $this->assertSame('', audio::url($this->instance, $this->context, $phrase, 'normal'));
        $this->assertCount(0, $fake->calls['tts'], 'A learner read never asks for a voice.');
        $url = audio::create($this->instance, $this->context, $phrase, 'normal', 2, 'en-AU-Chirp3-HD-Kore');
        $this->assertStringContainsString('/ttsaudio/', $url);
        $this->assertSame($url, audio::url($this->instance, $this->context, $phrase, 'normal'));
        $this->assertSame($url, audio::create($this->instance, $this->context, $phrase, 'normal', 2, 'en-AU-Chirp3-HD-Kore'));
        $this->assertCount(1, $fake->calls['tts'], 'A saved voice is reused, never made again.');

        // Changing the service's synthesis settings means a new voice when the teacher asks; the old file goes.
        $fake->identity = 'fake-v2';
        $this->assertSame('', audio::url($this->instance, $this->context, $phrase, 'normal'));
        $new = audio::create($this->instance, $this->context, $phrase, 'normal', 2, 'en-AU-Chirp3-HD-Kore');
        $this->assertNotSame($url, $new);
        $this->assertCount(2, $fake->calls['tts']);
        $files = get_file_storage()->get_area_files(
            $this->context->id,
            'mod_ailanguageteacher',
            'ttsaudio',
            $phrase->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);

        // Unsupported locale or no service: no call, the browser speaks.
        $this->instance->targetlocale = 'th-TH';
        $this->assertSame('', audio::create($this->instance, $this->context, $phrase, 'normal', 2, ''));
        $fake->on = false;
        $this->instance->targetlocale = 'en-AU';
        $other = $GLOBALS['DB']->get_record('ailanguageteacher_phrase', ['text' => 'Good morning, Maria!']);
        $this->assertSame('', audio::create($this->instance, $this->context, $other, 'normal', 2, ''));
        $this->assertCount(2, $fake->calls['tts']);
    }
}
