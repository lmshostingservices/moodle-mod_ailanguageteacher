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

use mod_ailanguageteacher\local\speech\matcher;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the speech transcript matcher.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\speech\matcher
 */
#[CoversClass(matcher::class)]
final class matcher_test extends \basic_testcase {
    /**
     * Identical text scores 100, ignoring case and punctuation.
     */
    public function test_identical(): void {
        $this->assertSame(100.0, matcher::similarity('good morning', 'Good morning!', false));
        $this->assertSame(100.0, matcher::similarity('¿Cómo estás?', 'cómo estás', false));
    }

    /**
     * Near misses keep most of the credit; unrelated text scores low.
     */
    public function test_near_and_far(): void {
        $near = matcher::similarity('good mornin', 'Good morning', false);
        $this->assertGreaterThan(70, $near);
        $this->assertLessThan(100, $near);
        $this->assertLessThan(40, matcher::similarity('thank you', 'Good morning', false));
        $this->assertSame(0.0, matcher::similarity('anything', '', false));
    }

    /**
     * Languages without spaces are compared character by character.
     */
    public function test_characters(): void {
        $this->assertSame(100.0, matcher::similarity('สวัสดีครับ', 'สวัสดี ครับ', true));
        $this->assertGreaterThan(60, matcher::similarity('你好吗', '你好', true));
    }

    /**
     * The best of several transcripts and alternatives wins.
     */
    public function test_best(): void {
        [$score, $heard] = matcher::best(['more ning', 'morning'], 'Good morning', ['Morning!'], false);
        $this->assertSame(100.0, $score);
        $this->assertSame('morning', $heard);
        [$score] = matcher::best([], 'Good morning', [], false);
        $this->assertSame(0.0, $score);
    }

    /**
     * Edit distance works on lists.
     */
    public function test_distance(): void {
        $this->assertSame(0, matcher::distance(['a', 'b'], ['a', 'b']));
        $this->assertSame(1, matcher::distance(['a', 'b'], ['a', 'c']));
        $this->assertSame(2, matcher::distance([], ['a', 'b']));
    }
}
