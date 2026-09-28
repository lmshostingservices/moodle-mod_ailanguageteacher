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

use mod_ailanguageteacher\local\ai\lmslabs;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the LMS Labs connection (balance only; no network).
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\ai\lmslabs
 */
#[CoversClass(lmslabs::class)]
final class lmslabs_test extends \advanced_testcase {
    /**
     * Resets the transport.
     */
    protected function tearDown(): void {
        lmslabs::$transport = null;
        parent::tearDown();
    }

    /**
     * Only the dedicated draft operation is available; legacy generation has no route.
     */
    public function test_legacy_generation_off(): void {
        $this->resetAfterTest();
        set_config('lmslabssiteid', 'site', 'mod_ailanguageteacher');
        set_config('lmslabsapikey', 'key', 'mod_ailanguageteacher');
        $p = new lmslabs();
        $this->assertTrue($p->is_connected());
        $this->assertTrue($p->can_generate());
        $this->expectException(\moodle_exception::class);
        $p->generate_lesson('x');
    }

    /**
     * The balance is read with the key in the X-API-Key header, never the URL.
     */
    public function test_balance(): void {
        $this->resetAfterTest();
        $p = new lmslabs();
        $this->assertNull($p->balance(), 'Not connected.');
        set_config('lmslabssiteid', 'My Site', 'mod_ailanguageteacher');
        set_config('lmslabsapikey', 'secret', 'mod_ailanguageteacher');
        $seen = [];
        lmslabs::$transport = function ($url, $headers) use (&$seen) {
            $seen = [$url, $headers];
            return [200, json_encode(['siteId' => 'My Site', 'credits' => 120, 'creditsRaw' => 120, 'isUnlimited' => false])];
        };
        $this->assertSame(['unlimited' => false, 'credits' => 120], $p->balance());
        $this->assertSame('https://lms-labs.com/api/credits?siteId=My%20Site', $seen[0]);
        $this->assertStringNotContainsString('secret', $seen[0]);
        $this->assertContains('X-API-Key: secret', $seen[1]);
        lmslabs::$transport = fn() => [200, json_encode(['credits' => 'unlimited', 'creditsRaw' => -1, 'isUnlimited' => true])];
        $this->assertSame(['unlimited' => true, 'credits' => -1], $p->balance());
        lmslabs::$transport = fn() => [401, json_encode(['error' => 'Invalid credentials'])];
        $this->assertNull($p->balance());
    }
}
