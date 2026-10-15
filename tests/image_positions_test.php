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

use mod_ailanguageteacher\local\credentials;
use mod_ailanguageteacher\local\image_positions;
use mod_ailanguageteacher\local\manager;
use mod_ailanguageteacher\local\pictures;
use mod_ailanguageteacher\local\remote;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Label positions returned with an LMS Labs picture (mocked: nothing is charged).
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\image_positions
 */
#[CoversClass(image_positions::class)]
final class image_positions_test extends \advanced_testcase {
    /** @var array Picture requests sent. */
    protected $sent = [];

    /** @var array Answers to the picture route. */
    protected $answers = [];

    /** @var bool Whether the capabilities advertise positions. */
    protected $anchors = true;

    /**
     * Fakes LMS Labs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('aiimages', 1, 'mod_ailanguageteacher');
        credentials::$central = fn() => ['siteid' => 'site-fixture', 'apikey' => 'secret-fixture'];
        remote::$transport = function ($url, $headers, $body) {
            if (str_ends_with($url, '/images/capabilities')) {
                return [200, json_encode(['tariff' => ['image' => 2], 'anchorPositions' => $this->anchors]), []];
            }
            $this->sent[] = json_decode((string)$body, true);
            return array_shift($this->answers);
        };
    }

    /**
     * Resets the fakes.
     */
    protected function tearDown(): void {
        remote::$transport = null;
        credentials::$central = null;
        parent::tearDown();
    }

    /**
     * Only a complete, ordered list of numbers 0–100 (or null) is used.
     */
    public function test_decode(): void {
        $this->assertSame([['x' => 31.5, 'y' => 42], null], image_positions::decode('[{"x":31.5,"y":42},null]', 2));
        $this->assertNull(image_positions::decode('[{"x":31.5,"y":42}]', 2), 'Wrong count');
        $this->assertNull(image_positions::decode('[{"x":"31","y":42}]', 1), 'A string is not a number');
        $this->assertNull(image_positions::decode('[{"x":101,"y":42}]', 1), 'Out of range');
        $this->assertNull(image_positions::decode('[{"x":1,"y":2,"z":3}]', 1), 'Extra keys');
        $this->assertNull(image_positions::decode('not json', 1));
        $this->assertNull(image_positions::decode('', 1));
        $this->assertNull(image_positions::decode('[null,null,null,null,null,null,null]', 7), 'More than 6 anchors');
    }

    /**
     * An activity with one scene without a picture: Jake's phrase is not placed, Emily's was placed by the teacher.
     *
     * @return array [instance, context, scene, teacher]
     */
    protected function activity(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $gen->create_instance(['course' => $course->id, 'imagestyle' => 'illustration']);
        $scene = $gen->create_scene($instance, 'At school', [
            ['text' => 'Hi, Emily!', 'x' => 0, 'y' => 0, 'anchor' => 'Jake, the man'],
            ['text' => 'Hi, Jake!', 'x' => 12, 'y' => 34, 'anchor' => 'Emily, the woman'],
        ]);
        $DB->execute(
            'UPDATE {ailanguageteacher_phrase} SET placed = 0 WHERE sceneid = ? AND anchor = ?',
            [$scene->id, 'Jake, the man']
        );
        $DB->execute(
            'UPDATE {ailanguageteacher_phrase} SET placed = 1 WHERE sceneid = ? AND anchor = ?',
            [$scene->id, 'Emily, the woman']
        );
        $context = \context_module::instance($instance->cmid);
        get_file_storage()->delete_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id);
        return [$DB->get_record('ailanguageteacher', ['id' => $instance->id]), $context,
            $DB->get_record('ailanguageteacher_scene', ['id' => $scene->id]), $this->getDataGenerator()->create_user()];
    }

    /**
     * A real 16:10 PNG.
     *
     * @return string
     */
    protected static function png(): string {
        $im = imagecreatetruecolor(160, 100);
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /**
     * Anchors are sent only while advertised; positions go to unconfirmed labels as guesses;
     * the teacher's label is never moved; a bad header changes nothing and never fails the paid picture.
     */
    public function test_positions_applied(): void {
        global $DB;
        [$instance, $context, $scene, $teacher] = $this->activity();
        $this->answers = [[200, self::png(), [], ['x-credits-charged' => '2',
            'x-anchor-positions' => '[{"x":70,"y":40},{"x":20,"y":45}]']]];
        pictures::create($instance, $context, $scene, (int)$teacher->id);
        $this->assertSame(['Jake, the man', 'Emily, the woman'], $this->sent[0]['anchors']);
        $rows = array_values($DB->get_records('ailanguageteacher_phrase', ['sceneid' => $scene->id], 'sortorder'));
        $this->assertSame(manager::GUESSED, (int)$rows[0]->placed, 'Still a guess for the teacher to check');
        $this->assertEqualsWithDelta(70, (float)$rows[0]->x, 0.01);
        $this->assertEqualsWithDelta(40, (float)$rows[0]->y, 0.01);
        $this->assertSame(1, (int)$rows[1]->placed);
        $this->assertEqualsWithDelta(12, (float)$rows[1]->x, 0.01, 'The teacher\'s label is not moved');
        // A new picture with an unusable header: saved and charged as normal, labels unchanged.
        $this->answers = [[200, self::png(), [], ['x-credits-charged' => '2', 'x-anchor-positions' => '[{"x":"bad"}]']]];
        $result = pictures::create($instance, $context, $scene, (int)$teacher->id, true);
        $this->assertTrue($result['made']);
        $this->assertEqualsWithDelta(70, (float)$DB->get_field('ailanguageteacher_phrase', 'x', ['id' => $rows[0]->id]), 0.01);
    }

    /**
     * Without the capability flag the request is exactly the 13 Oct body.
     */
    public function test_no_anchors_when_not_advertised(): void {
        $this->anchors = false;
        [$instance, $context, $scene, $teacher] = $this->activity();
        pictures::published_price(true);
        $this->answers = [[200, self::png(), [], ['x-credits-charged' => '2']]];
        pictures::create($instance, $context, $scene, (int)$teacher->id);
        $this->assertSame(['prompt', 'style', 'maxCredits'], array_keys($this->sent[0]));
    }
}
