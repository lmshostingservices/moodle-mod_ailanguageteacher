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

use moodle_exception;

/**
 * LMS Labs Google Chirp3-HD TTS adapter. Paid STT remains unavailable.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs implements service {
    /** @var array|null Live catalog for this PHP request. */
    private $catalog = null;

    public function capabilities(): array {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        [$status, $body] = \mod_ailanguageteacher\local\remote::request(
            '/api/moodle/ai-language-teacher/speech/capabilities'
        );
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['locales']) || !is_array($data['locales'])) {
            throw new moodle_exception('speechcatalogunavailable', 'mod_ailanguageteacher');
        }
        return $this->catalog = $data['locales'];
    }

    public function voices(string $locale): array {
        foreach ($this->capabilities() as $entry) {
            if (($entry['locale'] ?? '') === $locale) {
                $code = (string)($entry['ttsLanguageCode'] ?? $locale);
                // The two approved Chinese aliases are explicit; never infer a substitute dialect.
                if ($code !== $locale &&
                        !(($locale === 'zh-CN' && $code === 'cmn-CN') ||
                            ($locale === 'zh-HK' && $code === 'yue-HK'))) {
                    return [];
                }
                return array_values(array_filter((array)($entry['voices'] ?? []),
                    fn($voice) => is_array($voice) && isset($voice['name']) &&
                        str_starts_with($voice['name'], $code . '-Chirp3-HD-') &&
                        preg_match('/^[a-zA-Z-]+-Chirp3-HD-[a-zA-Z]+$/', $voice['name'])));
            }
        }
        return [];
    }
    /**
     * Whether server-side site credentials are configured.
     *
     * @return bool
     */
    public function available(): bool {
        return \mod_ailanguageteacher\local\credentials::find() !== null;
    }

    /**
     * Only exact eligible catalog locales for TTS. STT is not approved.
     *
     * @param string $locale
     * @param string $operation
     * @return bool
     */
    public function supports(string $locale, string $operation): bool {
        // No paid STT route is approved. A catalog failure must not invent a fallback locale.
        if ($operation !== 'tts' || !$this->available()) {
            return false;
        }
        try {
            return count($this->voices($locale)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cache identity for LMS Labs phrase audio.
     *
     * @return string
     */
    public function tts_identity(): string {
        return 'lmslabs-google';
    }

    /**
     * Call the approved one-credit TTS route with an existing durable key.
     *
     * @param string $text
     * @param string $locale
     * @param string $voice
     * @param string $speed
     * @param string $idemkey
     * @return array
     */
    public function synthesise(string $text, string $locale, string $voice, string $speed, string $idemkey): array {
        $names = array_column($this->voices($locale), 'name');
        if (!$names || ($voice !== '' && !in_array($voice, $names, true))) {
            throw new moodle_exception('speechcatalogunavailable', 'mod_ailanguageteacher');
        }
        if ($speed !== 'normal' && $speed !== 'slow') {
            throw new moodle_exception('invalidvariant', 'mod_ailanguageteacher');
        }
        $request = ['text' => $text, 'locale' => $locale, 'speed' => $speed];
        if ($voice !== '') {
            $request['voice'] = $voice;
        }
        [$status, $body, $info] = \mod_ailanguageteacher\local\remote::request(
            '/api/moodle/ai-language-teacher/speech/tts', $request, $idemkey
        );
        if ($status !== 200) {
            if ($status === 410) {
                throw new moodle_exception('speechresultnotretained', 'mod_ailanguageteacher');
            }
            throw new moodle_exception('remoteerror', 'mod_ailanguageteacher',
                '', \mod_ailanguageteacher\local\remote::error($status, $body));
        }
        $frames = strlen($body) > 1 && ord($body[0]) === 255 &&
            (ord($body[1]) & 0xe0) === 0xe0 && (ord($body[1]) & 0x06) === 0x02;
        if ($body === '' || strlen($body) > 2 * 1024 * 1024 ||
                (substr($body, 0, 3) !== 'ID3' && !$frames)) {
            throw new moodle_exception('remoteuncertain', 'mod_ailanguageteacher');
        }
        return ['audio' => $body, 'mimetype' => 'audio/mpeg', 'requestid' => ''];
    }

    /**
     * Paid transcription is explicitly unavailable.
     *
     * @param string $wav
     * @param string $locale
     * @param array $hints
     * @param string $idemkey
     * @return array
     */
    public function transcribe(string $wav, string $locale, array $hints, string $idemkey): array {
        throw new moodle_exception('speechnotconfigured', 'mod_ailanguageteacher');
    }
}
