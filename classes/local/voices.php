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

use stdClass;

/**
 * Which voice says each phrase: the activity's voice, or, when "match the voice to who says it" is on and the phrase
 * belongs to someone of the other gender, the activity's second voice.
 *
 * Who says a phrase is read from where it belongs in the picture ("the male taxi driver", "the waitress") unless the
 * teacher chose female or male for the phrase. Voice types are the eight Google Chirp 3 HD ones. Audio that has been
 * made keeps its voice: a choice made here only applies to audio made from now on.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class voices {
    /**
     * @var string[][] Chirp 3 HD voice types by how they sound; the eight used across the LMS Labs plugins come first,
     *     so a second voice is picked from them when the catalogue offers them.
     */
    public const GENDERS = [
        'f' => ['Aoede', 'Kore', 'Leda', 'Zephyr', 'Achernar', 'Autonoe', 'Callirrhoe', 'Despina', 'Erinome', 'Gacrux',
            'Laomedeia', 'Pulcherrima', 'Sulafat', 'Vindemiatrix'],
        'm' => ['Charon', 'Fenrir', 'Orus', 'Puck', 'Achird', 'Algenib', 'Algieba', 'Alnilam', 'Enceladus', 'Iapetus',
            'Rasalgethi', 'Sadachbia', 'Sadaltager', 'Schedar', 'Umbriel', 'Zubenelgenubi'],
    ];

    /** @var string[] Words that say who is female (English descriptions of the picture). */
    protected const FEMALE = ['woman', 'women', 'female', 'girl', 'lady', 'ladies', 'mother', 'mum', 'mom', 'grandmother',
        'daughter', 'sister', 'wife', 'aunt', 'she', 'her', 'waitress', 'actress', 'hostess', 'businesswoman', 'saleswoman',
        'policewoman', 'mrs', 'ms', 'miss', 'madam', 'queen', 'bride', 'niece'];

    /** @var string[] Words that say who is male. */
    protected const MALE = ['man', 'men', 'male', 'boy', 'gentleman', 'gentlemen', 'father', 'dad', 'grandfather', 'son',
        'brother', 'husband', 'uncle', 'he', 'him', 'his', 'waiter', 'actor', 'host', 'businessman', 'salesman', 'policeman',
        'mr', 'sir', 'king', 'groom', 'nephew', 'guy'];

    /**
     * The voice type of a catalogue voice name, such as Kore for en-AU-Chirp3-HD-Kore.
     *
     * @param string $voice
     * @return string
     */
    public static function type(string $voice): string {
        return (string)preg_replace('/^.*-Chirp3-HD-/', '', $voice);
    }

    /**
     * Whether a voice sounds female or male.
     *
     * @param string $voice
     * @return string f, m or ''
     */
    public static function gender_of_voice(string $voice): string {
        $type = self::type($voice);
        foreach (self::GENDERS as $gender => $types) {
            if (in_array($type, $types, true)) {
                return $gender;
            }
        }
        return '';
    }

    /**
     * Who says a phrase, read from where it belongs in the picture.
     *
     * @param string $anchor such as "the male taxi driver seated at the steering wheel"
     * @return string f, m or '' when it does not say
     */
    public static function gender_of_anchor(string $anchor): string {
        $words = preg_split('/[^a-z]+/', \core_text::strtolower($anchor), -1, PREG_SPLIT_NO_EMPTY);
        $f = count(array_intersect($words, self::FEMALE));
        $m = count(array_intersect($words, self::MALE));
        return $f > $m ? 'f' : ($m > $f ? 'm' : '');
    }

    /**
     * Who says a phrase: the teacher's choice, or else where it belongs.
     *
     * @param stdClass $phrase
     * @return string f, m or ''
     */
    public static function gender_of_phrase(stdClass $phrase): string {
        $chosen = (string)($phrase->voicegender ?? '');
        return in_array($chosen, ['f', 'm'], true) ? $chosen : self::gender_of_anchor((string)($phrase->anchor ?? ''));
    }

    /**
     * The second voice: the teacher's choice, or the first offered voice of the other gender.
     *
     * @param stdClass $instance
     * @param string[] $names catalogue voice names for the activity's locale
     * @return string '' when there is none
     */
    public static function second(stdClass $instance, array $names): string {
        $main = (string)$instance->ttsvoice;
        $maingender = self::gender_of_voice($main);
        if ($maingender === '') {
            // Not a voice we know: there is no "other" gender to pick.
            return '';
        }
        $other = $maingender === 'm' ? 'f' : 'm';
        $chosen = (string)($instance->ttsvoice2 ?? '');
        if (in_array($chosen, $names, true) && self::gender_of_voice($chosen) === $other) {
            return $chosen;
        }
        foreach (self::GENDERS[$other] as $type) {
            foreach ($names as $name) {
                if (self::type($name) === $type) {
                    return $name;
                }
            }
        }
        return '';
    }

    /**
     * The voice for a phrase's new audio.
     *
     * @param stdClass $instance
     * @param stdClass $phrase
     * @param string[] $names catalogue voice names for the activity's locale
     * @param string $main the activity's voice (the one the teacher chose in the Voices step)
     * @return string
     */
    public static function for_phrase(stdClass $instance, stdClass $phrase, array $names, string $main): string {
        if (empty($instance->voicematch)) {
            return $main;
        }
        $gender = self::gender_of_phrase($phrase);
        $maingender = self::gender_of_voice($main);
        if ($gender === '' || $maingender === '' || $gender === $maingender) {
            return $main;
        }
        $second = self::second((object)['ttsvoice' => $main, 'ttsvoice2' => $instance->ttsvoice2 ?? ''], $names);
        return $second !== '' && self::gender_of_voice($second) === $gender ? $second : $main;
    }
}
