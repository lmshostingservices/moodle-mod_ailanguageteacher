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

use mod_ailanguageteacher\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Labels placed for the teacher to check.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\manager
 */
#[CoversClass(manager::class)]
final class guess_test extends \advanced_testcase {
    /**
     * Unplaced labels are put on the picture as guesses: one person's phrases together, people spread out, placed
     * labels and distractors untouched.
     */
    public function test_guess_positions(): void {
        $out = manager::guess_positions([
            ['text' => 'Hi, Emily!', 'anchor' => 'Jake, the man on the right', 'placed' => 0],
            ['text' => 'Hi, Jake!', 'anchor' => 'Emily, the woman on the left', 'placed' => 0],
            ['text' => 'How are you?', 'anchor' => 'Jake, the man waving', 'placed' => 0],
            ['text' => 'Done', 'anchor' => 'the door', 'placed' => 1, 'x' => 5, 'y' => 6],
            ['text' => 'Nope', 'anchor' => '', 'placed' => 0, 'distractor' => 1],
        ]);
        $this->assertSame(manager::GUESSED, $out[0]['placed']);
        $this->assertSame(manager::GUESSED, $out[1]['placed']);
        $this->assertGreaterThan(50, $out[0]['x']);
        $this->assertLessThan(50, $out[1]['x']);
        // Jake's two phrases sit together, one above the other.
        $this->assertEqualsWithDelta($out[0]['x'], $out[2]['x'], 5);
        $this->assertNotEquals($out[0]['y'], $out[2]['y']);
        $this->assertSame(1, $out[3]['placed']);
        $this->assertSame(5, $out[3]['x']);
        $this->assertSame(0, $out[4]['placed']);
        foreach ([$out[0], $out[1], $out[2]] as $p) {
            $this->assertTrue($p['x'] >= 10 && $p['x'] <= 90 && $p['y'] >= 10 && $p['y'] <= 90);
        }
    }

    /**
     * Learners never get guessed labels; a saved guess stays a guess.
     */
    public function test_guesses_hidden_from_learners(): void {
        $pins = manager::order_pins([
            (object)['id' => 1, 'placed' => manager::GUESSED, 'distractor' => 0, 'x' => 10, 'y' => 10],
            (object)['id' => 2, 'placed' => 1, 'distractor' => 0, 'x' => 20, 'y' => 20],
        ]);
        $this->assertSame([2], array_map(fn($p) => $p->id, $pins));
        $this->assertSame(manager::GUESSED, manager::clean_phrase(['text' => 'Hi', 'placed' => 2, 'x' => 30, 'y' => 40])->placed);
        $this->assertSame(1, manager::clean_phrase(['text' => 'Hi', 'placed' => 1, 'x' => 30, 'y' => 40])->placed);
        $this->assertSame(0, manager::clean_phrase(['text' => 'Hi', 'placed' => 2, 'distractor' => 1])->placed);
    }
}
