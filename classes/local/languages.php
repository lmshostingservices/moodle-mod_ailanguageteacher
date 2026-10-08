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

/**
 * Catalogue of the languages, regional varieties, levels and situations the activity supports.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class languages {
    /**
     * Languages: code => [name in the language itself, locales (first is the default), writing needs romanisation,
     * written without spaces between words, right to left].
     *
     * @var array
     */
    public const LANGUAGES = [
        'en' => ['English', ['en-GB', 'en-US', 'en-AU', 'en-CA', 'en-IN'], false, false, false],
        'es' => ['Español', ['es-ES', 'es-MX'], false, false, false],
        'fr' => ['Français', ['fr-FR', 'fr-CA'], false, false, false],
        'de' => ['Deutsch', ['de-DE'], false, false, false],
        'it' => ['Italiano', ['it-IT'], false, false, false],
        'pt' => ['Português', ['pt-BR', 'pt-PT'], false, false, false],
        'nl' => ['Nederlands', ['nl-NL'], false, false, false],
        'ru' => ['Русский', ['ru-RU'], true, false, false],
        'pl' => ['Polski', ['pl-PL'], false, false, false],
        'tr' => ['Türkçe', ['tr-TR'], false, false, false],
        'ar' => ['العربية', ['ar-SA', 'ar-EG'], true, false, true],
        'hi' => ['हिन्दी', ['hi-IN'], true, false, false],
        'zh' => ['中文（普通话）', ['zh-CN', 'zh-TW'], true, true, false],
        'yue' => ['廣東話', ['zh-HK'], true, true, false],
        'ja' => ['日本語', ['ja-JP'], true, true, false],
        'ko' => ['한국어', ['ko-KR'], true, false, false],
        'th' => ['ไทย', ['th-TH'], true, true, false],
        'vi' => ['Tiếng Việt', ['vi-VN'], false, false, false],
        'id' => ['Bahasa Indonesia', ['id-ID'], false, false, false],
        'ms' => ['Bahasa Melayu', ['ms-MY'], false, false, false],
        'fil' => ['Filipino', ['fil-PH'], false, false, false],
    ];

    /** @var string[] CEFR levels, lowest first. */
    public const LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    /**
     * Situations offered in the lesson builder, by group.
     *
     * @var array
     */
    public const SITUATIONS = [
        'everyday' => ['greetings', 'introductions', 'farewells', 'family', 'home', 'food', 'restaurant', 'shopping',
            'supermarket', 'weather', 'time', 'hobbies'],
        'travel' => ['airport', 'transport', 'taxi', 'trainbus', 'directions', 'hotel', 'tickets', 'sightseeing',
            'carhire', 'customs', 'lostproperty', 'emergencies'],
        'services' => ['doctor', 'pharmacy', 'bank', 'postoffice', 'phonecalls', 'appointments', 'hairsalon', 'repairs'],
        'work' => ['workplace', 'meetings', 'customerservice', 'jobinterview', 'instructions', 'safety', 'hospitality',
            'housekeeping', 'construction', 'retail'],
    ];

    /** @var int Most custom situations a teacher can add in the builder. */
    public const MAX_CUSTOM = 6;

    /**
     * Whether a language code is supported.
     *
     * @param string $code
     * @return bool
     */
    public static function is_language(string $code): bool {
        return isset(self::LANGUAGES[$code]);
    }

    /**
     * Whether a locale belongs to a language.
     *
     * @param string $lang
     * @param string $locale
     * @return bool
     */
    public static function is_locale(string $lang, string $locale): bool {
        return self::is_language($lang) && in_array($locale, self::LANGUAGES[$lang][1], true);
    }

    /**
     * The default locale of a language.
     *
     * @param string $lang
     * @return string
     */
    public static function default_locale(string $lang): string {
        return self::is_language($lang) ? self::LANGUAGES[$lang][1][0] : 'en-GB';
    }

    /**
     * Language name in the current interface language.
     *
     * @param string $code
     * @param string|null $lang language to name it in (null: the current language)
     * @return string
     */
    public static function name(string $code, ?string $lang = null): string {
        if (!self::is_language($code)) {
            return $code;
        }
        return get_string_manager()->get_string('lang_' . $code, 'mod_ailanguageteacher', null, $lang);
    }

    /**
     * Language name in the language itself.
     *
     * @param string $code
     * @return string
     */
    public static function endonym(string $code): string {
        return self::LANGUAGES[$code][0] ?? $code;
    }

    /**
     * The country of a locale's region, in English, such as "Mexico" for es-MX. '' when the locale has no region.
     *
     * @param string $locale
     * @return string
     */
    public static function country(string $locale): string {
        if (!preg_match('/^[a-z]{2,3}-([A-Z]{2})$/', $locale, $m)) {
            return '';
        }
        $countries = get_string_manager()->get_list_of_countries(true, 'en');
        return (string)($countries[$m[1]] ?? '');
    }

    /**
     * Locale name, such as "English (Australia)".
     *
     * @param string $locale
     * @return string
     */
    public static function locale_name(string $locale): string {
        $key = 'locale_' . str_replace('-', '_', $locale);
        if (get_string_manager()->string_exists($key, 'mod_ailanguageteacher')) {
            return get_string($key, 'mod_ailanguageteacher');
        }
        return $locale;
    }

    /**
     * Whether the language is usually shown with romanisation for learners.
     *
     * @param string $code
     * @return bool
     */
    public static function needs_romanisation(string $code): bool {
        return !empty(self::LANGUAGES[$code][2]);
    }

    /**
     * Whether words are written without spaces, so speech is compared character by character.
     *
     * @param string $code
     * @return bool
     */
    public static function no_spaces(string $code): bool {
        return !empty(self::LANGUAGES[$code][3]);
    }

    /**
     * Whether the language is written right to left.
     *
     * @param string $code
     * @return bool
     */
    public static function is_rtl(string $code): bool {
        return !empty(self::LANGUAGES[$code][4]);
    }

    /**
     * Language options for a select menu, code => "English name (endonym)".
     *
     * @return array
     */
    public static function options(): array {
        $out = [];
        foreach (array_keys(self::LANGUAGES) as $code) {
            $name = self::name($code);
            $endonym = self::endonym($code);
            $out[$code] = $name === $endonym ? $name : $name . ' · ' . $endonym;
        }
        return $out;
    }

    /**
     * All locale options for a select menu, locale => name.
     *
     * @return array
     */
    public static function locale_options(): array {
        $out = [];
        foreach (self::LANGUAGES as $info) {
            foreach ($info[1] as $locale) {
                $out[$locale] = self::locale_name($locale);
            }
        }
        return $out;
    }

    /**
     * Level options for a select menu.
     *
     * @return array
     */
    public static function level_options(): array {
        $out = [];
        foreach (self::LEVELS as $level) {
            $out[$level] = get_string('level_' . strtolower($level), 'mod_ailanguageteacher');
        }
        return $out;
    }

    /**
     * Whether a key is a built-in situation.
     *
     * @param string $key
     * @return bool
     */
    public static function is_situation(string $key): bool {
        foreach (self::SITUATIONS as $keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Display name of a built-in situation.
     *
     * @param string $key
     * @param string|null $lang language to name it in (null: the current language)
     * @return string
     */
    public static function situation_name(string $key, ?string $lang = null): string {
        return get_string_manager()->get_string('sit_' . $key, 'mod_ailanguageteacher', null, $lang);
    }

    /**
     * The voice used for a locale's phrase audio.
     *
     * No voice list has been agreed with the speech service yet, so this is '' (the service's default voice for the
     * locale). The value is part of the phrase-audio cache identity, so changing it later creates new audio.
     *
     * @param string $locale
     * @return string
     */
    public static function voice(string $locale): string {
        return '';
    }

    /**
     * The installed Moodle language pack that suits a learner language, if any.
     *
     * @param string $code
     * @return string|null
     */
    public static function moodle_pack(string $code): ?string {
        $candidates = [
            'zh' => ['zh_cn', 'zh_tw'],
            'yue' => ['zh_tw', 'zh_cn'],
            'pt' => ['pt', 'pt_br'],
            'es' => ['es', 'es_mx'],
            'fil' => ['fil', 'tl'],
        ][$code] ?? [$code];
        $installed = get_string_manager()->get_list_of_translations();
        foreach ($candidates as $pack) {
            if (isset($installed[$pack])) {
                return $pack;
            }
        }
        return null;
    }
}
