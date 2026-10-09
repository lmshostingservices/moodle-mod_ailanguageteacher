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

namespace mod_ailanguageteacher\local;

use mod_ailanguageteacher\local\speech\factory;
use moodle_url;
use stdClass;

/**
 * Where "Listen" audio comes from, and how spoken answers are checked.
 *
 * Listen: the teacher's own recording first, then stored phrase audio from the site speech service (created once,
 * then reused by every learner), then the browser's built-in voice.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audio {
    /** @var string[] Audio variants. */
    public const VARIANTS = ['normal', 'slow', 'example'];

    /**
     * How spoken answers are checked for a locale: service (the site speech service transcribes the recording),
     * browser (the browser's own speech recognition) or self (the learner listens back and compares).
     *
     * @param string $locale
     * @return string
     */
    public static function speaking_mode(string $locale): string {
        $service = factory::get();
        if ($service->available() && $service->supports($locale, 'stt')) {
            return 'service';
        }
        return get_config('mod_ailanguageteacher', 'browserspeech') === '0' ? 'self' : 'browser';
    }

    /**
     * Whether the site speech service can create phrase audio for a locale.
     *
     * @param string $locale
     * @return bool
     */
    public static function has_service_tts(string $locale): bool {
        $service = factory::get();
        return $service->available() && $service->supports($locale, 'tts');
    }

    /**
     * The text spoken for a variant.
     *
     * @param stdClass $phrase
     * @param string $variant
     * @return string
     */
    public static function text_for(stdClass $phrase, string $variant): string {
        return $variant === 'example' ? (string)$phrase->example : (string)$phrase->text;
    }

    /**
     * The speed a variant is spoken at.
     *
     * @param string $variant
     * @return string normal or slow
     */
    protected static function speed_for(string $variant): string {
        return $variant === 'slow' ? 'slow' : 'normal';
    }

    /**
     * The file name stored phrase audio has. It is a hash of everything that changes the audio: the service's own
     * settings (provider, encoding, sample rate, synthesis version), the locale, voice, speed and text.
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string $variant
     * @return string
     */
    protected static function tts_filename(
        stdClass $instance,
        stdClass $phrase,
        string $variant,
        string $voice = ''
    ): string {
        $identity = implode('|', [
            factory::get()->tts_identity(),
            $instance->targetlocale,
            $voice,
            self::speed_for($variant),
            self::text_for($phrase, $variant),
        ]);
        return $variant . '-' . sha1($identity) . '.mp3';
    }

    /**
     * The free remake rule when LMS Labs supports it (voices are then sent with clipRef and maxCredits).
     *
     * @return array|null {limit, days}, or null when LMS Labs has not said it supports remakes
     */
    public static function remakes(): ?array {
        $rule = json_decode((string)get_config('mod_ailanguageteacher', 'speechremakes'), true);
        return is_array($rule) && !empty($rule['on'])
            ? ['limit' => (int)($rule['limit'] ?? 0), 'days' => (int)($rule['days'] ?? 0)] : null;
    }

    /**
     * The stable reference of one phrase voice, sent with each voice so LMS Labs can make it again for free after an
     * edit. It never contains text or voice, and its serialisation must never change: a JSON array of the component,
     * this site, the activity, the phrase and the variant.
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string $variant
     * @return string 64 lower-case hex characters
     */
    public static function clipref(stdClass $instance, stdClass $phrase, string $variant): string {
        return hash('sha256', json_encode(['mod_ailanguageteacher', (string)get_site_identifier(), (int)$instance->id,
            (int)$phrase->id, $variant]));
    }

    /**
     * Every request body this phrase voice may have been asked for with, by its hash: the body of earlier versions
     * (no clipRef) and the remake bodies (clipRef with a ceiling of 0 or 5 credits).
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string $variant
     * @param string $voice
     * @return array hash => [body, clipRef or null, maxCredits or null]
     */
    public static function bodies(stdClass $instance, stdClass $phrase, string $variant, string $voice): array {
        $legacy = ['text' => self::text_for($phrase, $variant), 'locale' => $instance->targetlocale,
            'speed' => self::speed_for($variant)];
        if ($voice !== '') {
            $legacy['voice'] = $voice;
        }
        $out = [operation::hash($legacy) => [$legacy, null, null]];
        $ref = self::clipref($instance, $phrase, $variant);
        foreach ([0, \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS] as $max) {
            $body = $legacy + ['clipRef' => $ref, 'maxCredits' => $max];
            $out[operation::hash($body)] = [$body, $ref, $max];
        }
        return $out;
    }

    /**
     * The current price of one phrase voice (free; nothing is made). No price from LMS Labs counts as full price.
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string $variant
     * @return int 0 or 5
     */
    public static function quote(stdClass $instance, stdClass $phrase, string $variant): int {
        $full = \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS;
        if (self::remakes() === null) {
            return $full;
        }
        $quote = \mod_ailanguageteacher\local\speech\lmslabs::quote(self::clipref($instance, $phrase, $variant));
        return $quote ?? $full;
    }

    /**
     * URL of an existing audio file for a phrase, or '' when it still has to be created or spoken by the browser.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $phrase
     * @param string $variant
     * @return string
     */
    public static function existing_url(stdClass $instance, \context $context, stdClass $phrase, string $variant): string {
        global $DB;
        if (trim(self::text_for($phrase, $variant)) === '') {
            return '';
        }
        if ($variant === 'normal') {
            $recording = manager::recording_url($context, (int)$phrase->id);
            if ($recording !== '') {
                return $recording;
            }
        }
        $rows = $DB->get_records(
            'ailanguageteacher_operation',
            ['ailanguageteacherid' => $instance->id, 'kind' => 'tts',
                'itemid' => (int)$phrase->id * 3 + array_search($variant, self::VARIANTS, true),
            'state' => 'complete'],
            'id DESC',
            '*',
            0,
            1
        );
        $row = $rows ? reset($rows) : null;
        $voice = $row ? (string)$row->result : '';
        // The stored voice is current when it was made for this text and voice, with or without a clip reference.
        if (!$row || !isset(self::bodies($instance, $phrase, $variant, $voice)[$row->bodyhash])) {
            return '';
        }
        $filename = self::tts_filename($instance, $phrase, $variant, $voice);
        $fs = get_file_storage();
        if (!$fs->file_exists($context->id, 'mod_ailanguageteacher', 'ttsaudio', $phrase->id, '/', $filename)) {
            return '';
        }
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_ailanguageteacher',
            'ttsaudio',
            $phrase->id,
            '/',
            $filename
        )->out(false);
    }

    /**
     * Whether an unresolved request for this phrase variant was made with this voice.
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string $variant
     * @param string $voice
     * @return bool
     */
    public static function pending_with_voice(stdClass $instance, stdClass $phrase, string $variant, string $voice): bool {
        global $DB;
        $hashes = array_keys(self::bodies($instance, $phrase, $variant, $voice));
        [$insql, $params] = $DB->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
        return $DB->record_exists_select(
            'ailanguageteacher_operation',
            "ailanguageteacherid = :aid AND kind = 'tts' AND itemid = :item AND state = 'pending' AND bodyhash $insql",
            $params + ['aid' => $instance->id, 'item' => (int)$phrase->id * 3 + array_search($variant, self::VARIANTS, true)]
        );
    }

    /**
     * Returns the audio URL for a phrase, asking the speech service for it once when there is no stored audio yet.
     *
     * A lock per audio file makes sure that learners opening a new phrase at the same time cause one request, not many.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $phrase
     * @param string $variant
     * @return string URL, or '' when the browser should speak the text itself
     */
    public static function url(stdClass $instance, \context $context, stdClass $phrase, string $variant): string {
        // Learner reads must never initiate a charge. Only the teacher's explicit create_audio action may synthesize.
        return self::existing_url($instance, $context, $phrase, $variant);
    }

    /**
     * Creates one paid phrase recording (5 credits). Called only after a teacher explicitly asks for it.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $phrase
     * @param string $variant
     * @param int $userid
     * @param string $voice exact catalogue voice name
     * @param bool $discard the teacher confirmed abandoning an unfinished request for different text or voice
     * @param int|null $maxcredits the most the teacher confirmed (0 or 5), when LMS Labs supports free remakes
     * @return string URL of the saved audio
     */
    public static function create(
        stdClass $instance,
        \context $context,
        stdClass $phrase,
        string $variant,
        int $userid,
        string $voice,
        bool $discard = false,
        ?int $maxcredits = null
    ): string {
        global $DB;
        $url = self::existing_url($instance, $context, $phrase, $variant);
        if ($url !== '' || !self::has_service_tts($instance->targetlocale) || trim(self::text_for($phrase, $variant)) === '') {
            return $url;
        }
        if (mb_strlen(self::text_for($phrase, $variant), 'UTF-8') > 200) {
            throw new \moodle_exception('speechtxttoolong', 'mod_ailanguageteacher');
        }
        $bodies = self::bodies($instance, $phrase, $variant, $voice);
        // What a new request sends: with free remakes, the voice's place and the price the teacher confirmed.
        $max = $maxcredits === 0 ? 0 : \mod_ailanguageteacher\local\speech\lmslabs::TTS_CREDITS;
        $body = array_values($bodies)[0][0];
        if (self::remakes() !== null) {
            $body += ['clipRef' => self::clipref($instance, $phrase, $variant), 'maxCredits' => $max];
        }
        $filename = self::tts_filename($instance, $phrase, $variant, $voice);
        $factory = \core\lock\lock_config::get_lock_factory('mod_ailanguageteacher_tts');
        // Serialise every teacher's request for this phrase variant, including requests for a different voice.
        $lock = $factory->get_lock($context->id . '-' . $phrase->id . '-' . $variant, 20);
        if (!$lock) {
            throw new \moodle_exception('operationpending', 'mod_ailanguageteacher');
        }
        try {
            $url = self::existing_url($instance, $context, $phrase, $variant);
            if ($url !== '') {
                return $url;
            }
            $itemid = (int)$phrase->id * 3 + array_search($variant, self::VARIANTS, true);
            $pending = $DB->get_records('ailanguageteacher_operation', [
                'ailanguageteacherid' => $instance->id, 'kind' => 'tts',
                'itemid' => $itemid, 'state' => 'pending',
            ], 'id DESC');
            $op = null;
            $send = $body;
            foreach ($pending as $claim) {
                if (isset($bodies[$claim->bodyhash])) {
                    // Unresolved: asked about again with its own key and exactly the body it was sent with.
                    $op = $claim;
                    $send = $bodies[$claim->bodyhash][0];
                } else if ($discard) {
                    operation::failed($claim);
                } else {
                    throw new \moodle_exception('operationconflict', 'mod_ailanguageteacher');
                }
            }
            if ($op === null) {
                $op = operation::claim((int)$instance->id, $userid, 'tts', $itemid, $body);
            }
            // A refusal ends the stored request; no answer or "still working" keeps it for the same key.
            $result = factory::get()->synthesise(
                self::text_for($phrase, $variant),
                $instance->targetlocale,
                $voice,
                self::speed_for($variant),
                $op->idemkey,
                $send['clipRef'] ?? null,
                $send['maxCredits'] ?? null
            );
            $fs = get_file_storage();
            // Remove this variant's older audio (the text, voice or service settings have changed).
            foreach ($fs->get_area_files($context->id, 'mod_ailanguageteacher', 'ttsaudio', $phrase->id, 'id', false) as $file) {
                if (strpos($file->get_filename(), $variant . '-') === 0) {
                    $file->delete();
                }
            }
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_ailanguageteacher',
                'filearea' => 'ttsaudio',
                'itemid' => $phrase->id,
                'filepath' => '/',
                'filename' => $filename,
                'mimetype' => 'audio/mpeg',
            ], (string)$result['audio']);
            operation::complete($op, $voice);
        } finally {
            $lock->release();
        }
        return self::existing_url($instance, $context, $phrase, $variant);
    }
}
