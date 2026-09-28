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

namespace mod_ailanguageteacher\local\speech;

/**
 * A server-side speech service: phrase audio (text to speech) and spoken-answer transcription.
 *
 * Moodle PHP is the only caller. Credentials stay with the service provider's own configuration and are never sent
 * to browsers. Transcription returns text only; it is compared with the expected phrase by {@see matcher}, which is a
 * words-recognised check, not a pronunciation assessment.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface service {
    /** @var string Transcription produced usable text. */
    public const STATUS_OK = 'ok';

    /** @var string No speech was detected in the recording. */
    public const STATUS_NOSPEECH = 'nospeech';

    /** @var string Speech was detected but no words could be made out. */
    public const STATUS_UNINTELLIGIBLE = 'unintelligible';

    /**
     * Whether the service may be called on this site (contract implemented and paid calls enabled).
     *
     * @return bool
     */
    public function available(): bool;

    /**
     * Whether the service supports an operation for a locale. Text to speech and transcription are checked separately.
     *
     * @param string $locale such as th-TH
     * @param string $operation 'tts' or 'stt'
     * @return bool
     */
    public function supports(string $locale, string $operation): bool;

    /**
     * Everything besides the text, locale, voice and speed that changes the generated audio (provider, encoding,
     * sample rate, synthesis settings version). Part of the phrase-audio cache identity.
     *
     * @return string
     */
    public function tts_identity(): string;

    /**
     * Creates spoken audio for a short phrase.
     *
     * @param string $text
     * @param string $locale
     * @param string $voice voice name, or '' for the service default
     * @param string $speed 'normal' or 'slow'
     * @param string $idemkey idempotency key for this operation
     * @return array ['audio' => bytes, 'mimetype' => string, 'requestid' => string]
     */
    public function synthesise(string $text, string $locale, string $voice, string $speed, string $idemkey): array;

    /**
     * Transcribes a learner's recording.
     *
     * @param string $wav validated WAV bytes (16 kHz, 16-bit, mono)
     * @param string $locale
     * @param string[] $hints expected phrase and accepted alternatives, when the service accepts hints
     * @param string $idemkey idempotency key for this operation
     * @return array ['status' => STATUS_*, 'alternatives' => string[] best first, 'requestid' => string]
     */
    public function transcribe(string $wav, string $locale, array $hints, string $idemkey): array;
}
