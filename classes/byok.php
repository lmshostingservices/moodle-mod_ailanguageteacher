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

use mod_ailanguageteacher\local\byok\engine;
use mod_ailanguageteacher\local\byok\js;

/**
 * Bring your own AI: AI Language Teacher work that runs on the site's own AI instead of LMS Labs credits.
 *
 * AI Grader Central Config (local_aiconfig) decides, for each kind of work, whether it runs on LMS Labs credits, the
 * Moodle AI subsystem or the site's own key. Scene writing is text, scene pictures are images (their phrase targets are
 * found by vision when the site's own AI reads pictures) and phrase voices are voice; each is decided separately.
 *
 * Work on the site's own AI runs the LMS Labs service's own logic ({@see engine}) inside this Moodle site and answers
 * every request exactly as the service would, at no charge. So the plugin's stored requests, refusals, "Check again"
 * and saved media work unchanged: {@see \mod_ailanguageteacher\local\remote::request()} asks here first.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** @var string The component Central Config knows this plugin by. */
    const COMPONENT = 'mod_ailanguageteacher';
    /** @var int Seconds a scene draft may take. */
    const TEXT_TIMEOUT = 120;
    /** @var string[] Chirp 3 HD voices with a female voice. */
    const FEMALE = ['Achernar', 'Aoede', 'Autonoe', 'Callirrhoe', 'Despina', 'Erinome', 'Gacrux', 'Kore', 'Laomedeia',
        'Leda', 'Pulcherrima', 'Sulafat', 'Vindemiatrix', 'Zephyr'];
    /** @var string[] Chirp 3 HD voices with a male voice. */
    const MALE = ['Achird', 'Algenib', 'Algieba', 'Alnilam', 'Charon', 'Enceladus', 'Fenrir', 'Iapetus', 'Orus', 'Puck',
        'Rasalgethi', 'Sadachbia', 'Sadaltager', 'Schedar', 'Umbriel', 'Zubenelgenubi'];
    /** @var string[] The closest OpenAI voice for each Chirp 3 HD voice. */
    const OPENAI_VOICES = ['Achernar' => 'shimmer', 'Aoede' => 'nova', 'Autonoe' => 'coral', 'Callirrhoe' => 'shimmer',
        'Despina' => 'coral', 'Erinome' => 'sage', 'Gacrux' => 'sage', 'Kore' => 'shimmer', 'Laomedeia' => 'nova',
        'Leda' => 'coral', 'Pulcherrima' => 'nova', 'Sulafat' => 'sage', 'Vindemiatrix' => 'sage', 'Zephyr' => 'sage',
        'Achird' => 'echo', 'Algenib' => 'onyx', 'Algieba' => 'ash', 'Alnilam' => 'onyx', 'Charon' => 'echo',
        'Enceladus' => 'ash', 'Fenrir' => 'ash', 'Iapetus' => 'echo', 'Orus' => 'ballad', 'Puck' => 'onyx',
        'Rasalgethi' => 'ballad', 'Sadachbia' => 'verse', 'Sadaltager' => 'echo', 'Schedar' => 'onyx',
        'Umbriel' => 'ash', 'Zubenelgenubi' => 'verse'];
    /** @var string[] Locales whose voices carry another language code: in the catalogue, and at Google. */
    const CATALOGUE_CODES = ['zh-CN' => 'cmn-CN', 'zh-HK' => 'yue-HK'];
    /** @var string[] Language codes Google's Chirp 3 HD voices use for these locales. */
    const GOOGLE_CODES = ['zh-CN' => 'cmn-CN', 'zh-HK' => 'yue-HK', 'zh-TW' => 'cmn-TW'];

    /** @var string|null What the site's own AI said when it last failed, shown to the teacher. */
    public static $lasterror = null;

    /** @var bool Whether the last request was answered here, on the site's own AI. */
    public static $answered = false;

    /**
     * Whether a kind of work runs on the site's own AI now.
     *
     * @param string $kind text, image, voice or vision
     * @return bool
     */
    public static function uses(string $kind): bool {
        return class_exists('\local_aiconfig\byok\ai') && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, $kind);
    }

    /**
     * Whether any work runs on the site's own AI now (the site then holds a Bring your own AI licence).
     *
     * @return bool
     */
    public static function any(): bool {
        return self::uses('text') || self::uses('image') || self::uses('voice');
    }

    /**
     * The service's answer to a request, made here on the site's own AI; null when the request goes to LMS Labs.
     *
     * @param string $path the route
     * @param array|null $body the JSON body (POST), or null for GET
     * @return array|null [status, body, info, headers (lower-case names)], as remote::request() answers
     */
    public static function answer(string $path, ?array $body): ?array {
        $routes = [
            local\ai\lmslabs::DRAFT_ROUTE => 'text', local\ai\lmslabs::IMPORT_ROUTE => 'text',
            local\pictures::ROUTE => 'image', local\pictures::CAPABILITIES_ROUTE => 'image',
            local\speech\lmslabs::TTS_ROUTE => 'voice', local\speech\lmslabs::CAPABILITIES_ROUTE => 'voice',
            local\speech\lmslabs::QUOTE_ROUTE => 'voice',
        ];
        self::$lasterror = null;
        self::$answered = isset($routes[$path]) && self::uses($routes[$path]);
        if (!self::$answered) {
            return null;
        }
        self::hooks();
        $json = $body === null ? '' : json_encode($body);
        $id = \core\uuid::generate();
        $error = fn(int $status, string $code, bool $structured = true) => [$status, json_encode(['requestId' => $id,
            'error' => $structured ? ['code' => $code, 'message' => $code] : $code]), [], []];
        switch ($path) {
            case local\ai\lmslabs::DRAFT_ROUTE:
                [$status, $out] = engine::lesson($json);
                if ($status !== 200) {
                    return $error($status, $out);
                }
                return [200, js::stringify((object)['requestId' => $id, 'draft' => js::parse($out), 'model' => engine::MODEL,
                    'creditsCharged' => 0, 'creditsBalance' => null]), [], []];
            case local\ai\lmslabs::IMPORT_ROUTE:
                [$status, $out] = engine::import($json);
                return $status === 200 ? [200, json_encode(['requestId' => $id, 'creditsCharged' => 0,
                    'creditsBalance' => null]), [], []] : $error($status, $out);
            case local\pictures::CAPABILITIES_ROUTE:
                return [200, json_encode(['tariff' => ['image' => engine::IMAGE_TARIFF], 'styles' => engine::STYLES,
                    'maxPromptChars' => 2000, 'anchorPositions' => true]), [], []];
            case local\pictures::ROUTE:
                [$status, $out, $headers] = engine::image($json);
                return $status === 200 ? [200, $out, [], $headers + ['content-type' => 'image/png',
                    'x-credits-charged' => '0', 'x-request-id' => $id]] : $error($status, $out, false);
            case local\speech\lmslabs::CAPABILITIES_ROUTE:
                return [200, json_encode(['locales' => self::catalogue(),
                    'tariff' => ['tts' => local\speech\lmslabs::TTS_CREDITS], 'requestId' => $id]), [], []];
            case local\speech\lmslabs::QUOTE_ROUTE:
                return [200, json_encode(['requestId' => $id, 'credits' => 0, 'freeRemakesRemaining' => 0,
                    'firstClip' => false]), [], []];
            default:
                [$status, $out, $headers] = engine::speech($json, self::catalogue());
                return $status === 200 ? [200, $out, [], $headers + ['content-type' => 'audio/mpeg',
                    'x-credits-charged' => '0', 'x-request-id' => $id]] : $error($status, $out, false);
        }
    }

    /**
     * The voice catalogue on the site's own voice: every Chirp 3 HD voice for every locale the plugin teaches. On an
     * own OpenAI key each plays as the closest OpenAI voice; on other voice services, as the service's own voice.
     *
     * @return array the service's catalogue format
     */
    public static function catalogue(): array {
        $names = array_merge(self::FEMALE, self::MALE);
        sort($names);
        $out = [];
        foreach (local\languages::LANGUAGES as $language) {
            foreach ($language[1] as $locale) {
                $code = self::CATALOGUE_CODES[$locale] ?? $locale;
                $out[] = ['locale' => $locale, 'ttsLanguageCode' => $code,
                    'voices' => array_map(fn($n) => ['name' => "{$code}-Chirp3-HD-{$n}",
                        'gender' => in_array($n, self::FEMALE, true) ? 'FEMALE' : 'MALE'], $names),
                    'defaultFemale' => "{$code}-Chirp3-HD-Kore", 'defaultMale' => "{$code}-Chirp3-HD-Charon",
                    'rates' => ['normal' => 1, 'slow' => 0.8]];
            }
        }
        return $out;
    }

    /**
     * Connect the ported service to the site's own AI.
     */
    protected static function hooks(): void {
        $ai = '\local_aiconfig\byok\ai';
        engine::$text = fn(string $system, string $user, int $maxtokens, float $temperature, array $schema) => self::call(
            fn() => $ai::text(self::COMPONENT, $system, $user, ['maxtokens' => $maxtokens, 'temperature' => $temperature,
                'schema' => $schema, 'timeout' => self::TEXT_TIMEOUT])
        );
        engine::$image = fn(string $prompt) => self::call(
            fn() => $ai::image(self::COMPONENT, $prompt, ['size' => '1536x1024', 'quality' => 'medium'])
        );
        // Without vision on the site's own AI the service's own fallback applies: Moodle keeps its placement guesses.
        engine::$vision = !self::uses('vision') ? null : fn(string $system, string $user, string $png, array $schema) =>
            $ai::text(self::COMPONENT, $system, $user, ['images' => [['mime' => 'image/png', 'data' => $png]],
                'schema' => $schema, 'maxtokens' => 500, 'timeout' => 30]);
        engine::$speech = fn(string $text, string $locale, string $voice, float $rate) => self::call(
            fn() => $ai::speech(self::COMPONENT, $text, ['language' => self::GOOGLE_CODES[$locale] ?? $locale,
                'voice' => self::voice($locale, $voice), 'format' => 'mp3', 'rate' => $rate])
        );
    }

    /**
     * The voice to ask the site's voice service for.
     *
     * @param string $locale
     * @param string $voice a catalogue voice name
     * @return string
     */
    public static function voice(string $locale, string $voice): string {
        $short = substr($voice, strrpos($voice, '-') + 1);
        $vendor = \local_aiconfig\byok\router::vendor(\local_aiconfig\byok\router::VOICE);
        if ($vendor === 'googletts') {
            return (self::GOOGLE_CODES[$locale] ?? $locale) . '-Chirp3-HD-' . $short;
        }
        return $vendor === 'openai' ? (self::OPENAI_VOICES[$short] ?? 'shimmer') : '';
    }

    /**
     * Run a call to the site's own AI, keeping what it said when it fails.
     *
     * @param callable $call
     * @return string
     */
    protected static function call(callable $call): string {
        try {
            return (string)$call();
        } catch (\Throwable $e) {
            self::$lasterror = $e->getMessage();
            throw $e;
        }
    }
}
