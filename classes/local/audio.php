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
    protected static function tts_filename(stdClass $instance, stdClass $phrase, string $variant,
            string $voice = ''): string {
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
        $rows = $DB->get_records('ailanguageteacher_operation',
            ['ailanguageteacherid' => $instance->id, 'kind' => 'tts',
                'itemid' => (int)$phrase->id * 3 + array_search($variant, self::VARIANTS, true),
                'state' => 'complete'], 'id DESC', '*', 0, 1);
        $row = $rows ? reset($rows) : null;
        $voice = $row ? (string)$row->result : '';
        $body = ['text' => self::text_for($phrase, $variant),
            'locale' => $instance->targetlocale, 'speed' => self::speed_for($variant), 'voice' => $voice];
        if (!$row || $row->bodyhash !== hash('sha256',
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
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

    /** Called only after a teacher explicitly asks for one paid phrase recording. */
    public static function create(stdClass $instance, \context $context, stdClass $phrase, string $variant,
            int $userid, string $voice): string {
        global $DB;
        $url = self::existing_url($instance, $context, $phrase, $variant);
        if ($url !== '' || !self::has_service_tts($instance->targetlocale) || trim(self::text_for($phrase, $variant)) === '') {
            return $url;
        }
        if (mb_strlen(self::text_for($phrase, $variant), 'UTF-8') > 200) {
            throw new \moodle_exception('speechtxttoolong', 'mod_ailanguageteacher');
        }
        $body = ['text' => self::text_for($phrase, $variant), 'locale' => $instance->targetlocale,
            'speed' => self::speed_for($variant)];
        if ($voice !== '') {
            $body['voice'] = $voice;
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
            foreach ($pending as $claim) {
                if ($claim->bodyhash !== hash('sha256',
                        json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
                    throw new \moodle_exception('operationconflict', 'mod_ailanguageteacher');
                }
                $op = $claim;
            }
            if ($op === null) {
                $op = operation::claim((int)$instance->id, $userid, 'tts', $itemid, $body);
            }
            try {
                $result = factory::get()->synthesise(
                    self::text_for($phrase, $variant), $instance->targetlocale,
                    $voice, self::speed_for($variant), $op->idemkey
                );
            } catch (\moodle_exception $e) {
                if ($e->errorcode === 'speechresultnotretained') {
                    operation::expired($op);
                }
                throw $e;
            }
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
