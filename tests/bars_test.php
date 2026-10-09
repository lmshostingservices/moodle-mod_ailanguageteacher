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

use mod_ailanguageteacher\local\learning;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the traffic-light progress bars.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\learning
 */
#[CoversClass(learning::class)]
final class bars_test extends \advanced_testcase {
    /**
     * Red below the amber number, amber from it, green from the green number; bad bands are made safe.
     */
    public function test_bar_tone(): void {
        $this->assertSame('red', learning::bar_tone(39, null));
        $this->assertSame('amber', learning::bar_tone(40, null));
        $this->assertSame('green', learning::bar_tone(70, null));
        $instance = (object)['barsamber' => 20, 'barsgreen' => 50];
        $this->assertSame('red', learning::bar_tone(10, $instance));
        $this->assertSame('amber', learning::bar_tone(49.9, $instance));
        $this->assertSame('green', learning::bar_tone(50, $instance));
        $odd = (object)['barsamber' => 80, 'barsgreen' => 60];
        $this->assertSame('amber', learning::bar_tone(80, $odd));
        $this->assertSame('green', learning::bar_tone(81, $odd));
    }
}
