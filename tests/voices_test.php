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

use mod_ailanguageteacher\local\voices;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for phrase voices that match who says each phrase.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\voices
 */
#[CoversClass(voices::class)]
final class voices_test extends \advanced_testcase {
    /** @var string[] Catalogue voice names. */
    protected const NAMES = ['es-ES-Chirp3-HD-Kore', 'es-ES-Chirp3-HD-Charon', 'es-ES-Chirp3-HD-Leda', 'es-ES-Chirp3-HD-Puck'];

    /**
     * Who says it is read from where it belongs, unless the teacher chose.
     */
    public function test_gender(): void {
        $this->assertSame('m', voices::gender_of_anchor('the male taxi driver seated at the steering wheel'));
        $this->assertSame('f', voices::gender_of_anchor('The woman at the door'));
        $this->assertSame('', voices::gender_of_anchor('the receptionist'));
        $this->assertSame('', voices::gender_of_anchor('the woman handing the man a ticket'));
        $this->assertSame('f', voices::gender_of_phrase((object)['anchor' => 'the male driver', 'voicegender' => 'f']));
        $this->assertSame('m', voices::gender_of_voice('es-ES-Chirp3-HD-Charon'));
        $this->assertSame('', voices::gender_of_voice('es-ES-Standard-A'));
    }

    /**
     * The activity's voice, or the second voice for someone of the other gender; off: always the activity's voice.
     */
    public function test_for_phrase(): void {
        $instance = (object)['ttsvoice' => 'es-ES-Chirp3-HD-Kore', 'ttsvoice2' => '', 'voicematch' => 1];
        $male = (object)['anchor' => 'the male taxi driver', 'voicegender' => null];
        $female = (object)['anchor' => 'the woman at the counter', 'voicegender' => null];
        $nobody = (object)['anchor' => 'the menu', 'voicegender' => null];
        $main = 'es-ES-Chirp3-HD-Kore';
        $this->assertSame('es-ES-Chirp3-HD-Charon', voices::for_phrase($instance, $male, self::NAMES, $main));
        $this->assertSame($main, voices::for_phrase($instance, $female, self::NAMES, $main));
        $this->assertSame($main, voices::for_phrase($instance, $nobody, self::NAMES, $main));
        $instance->ttsvoice2 = 'es-ES-Chirp3-HD-Puck';
        $this->assertSame('es-ES-Chirp3-HD-Puck', voices::for_phrase($instance, $male, self::NAMES, $main));
        // A second voice of the same gender as the main voice is never used.
        $instance->ttsvoice2 = 'es-ES-Chirp3-HD-Leda';
        $this->assertSame('es-ES-Chirp3-HD-Charon', voices::for_phrase($instance, $male, self::NAMES, $main));
        $instance->voicematch = 0;
        $this->assertSame($main, voices::for_phrase($instance, $male, self::NAMES, $main));
        // No voice of the other gender on offer: the activity's voice.
        $instance->voicematch = 1;
        $this->assertSame($main, voices::for_phrase($instance, $male, ['es-ES-Chirp3-HD-Kore'], $main));
        // Every Chirp 3 HD voice has a known gender; an unknown voice is never paired.
        $this->assertSame('f', voices::gender_of_voice('es-ES-Chirp3-HD-Achernar'));
        $this->assertSame('m', voices::gender_of_voice('es-ES-Chirp3-HD-Zubenelgenubi'));
        $female = (object)['anchor' => 'the waitress', 'voicegender' => null];
        $odd = 'es-ES-Chirp3-HD-Nova';
        $this->assertSame($odd, voices::for_phrase($instance, $female, array_merge(self::NAMES, [$odd]), $odd));
        $this->assertSame(
            'es-ES-Chirp3-HD-Charon',
            voices::for_phrase($instance, $male, array_merge(self::NAMES, ['es-ES-Chirp3-HD-Achernar']), 'es-ES-Chirp3-HD-Achernar')
        );
    }
}
