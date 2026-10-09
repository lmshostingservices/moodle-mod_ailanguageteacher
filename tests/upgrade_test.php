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

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/ailanguageteacher/db/upgrade.php');

/**
 * Regression tests for sites that came from 1.1.0 ("Error reading from database" on "Create these scenes").
 *
 * 1.1.0 (2026092802) kept its paid requests in ailanguageteacher_aireq. Its version number was above the 1.2.x step
 * that creates ailanguageteacher_operation, so that step never ran there and every paid action failed reading a
 * table that did not exist, before anything was sent to LMS Labs.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::mod_ailanguageteacher_upgrade_operation_table
 * @covers     ::mod_ailanguageteacher_upgrade_move_110_requests
 */
#[CoversFunction('mod_ailanguageteacher_upgrade_operation_table')]
#[CoversFunction('mod_ailanguageteacher_upgrade_move_110_requests')]
final class upgrade_test extends \advanced_testcase {
    /**
     * The 1.1.0 request table, as 1.1.0 installed it.
     *
     * @return \xmldb_table
     */
    protected function old_table(): \xmldb_table {
        $table = new \xmldb_table('ailanguageteacher_aireq');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ailanguageteacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('operation', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $table->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('variant', XMLDB_TYPE_CHAR, '16');
        $table->add_field('idemkey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '64');
        $table->add_field('requestid', XMLDB_TYPE_CHAR, '64');
        $table->add_field('result', XMLDB_TYPE_TEXT);
        $table->add_field('charged', XMLDB_TYPE_INTEGER, '6');
        $table->add_field('balance', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('tries', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idemkey', XMLDB_INDEX_UNIQUE, ['idemkey']);
        return $table;
    }

    /**
     * A missing stored-requests table is created; an existing one is left alone.
     */
    public function test_operation_table_created_when_missing(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('ailanguageteacher_operation');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table));
        mod_ailanguageteacher_upgrade_operation_table();
        $this->assertTrue($dbman->table_exists($table));
        // The table works as the paid actions use it.
        $id = $DB->insert_record('ailanguageteacher_operation', (object)['ailanguageteacherid' => 1, 'userid' => 2,
            'kind' => 'lesson', 'itemid' => 0, 'bodyhash' => str_repeat('a', 64), 'body' => '{}', 'idemkey' => 'k1',
            'state' => 'pending', 'timecreated' => time()]);
        mod_ailanguageteacher_upgrade_operation_table();
        $this->assertTrue($DB->record_exists('ailanguageteacher_operation', ['id' => $id]));
    }

    /**
     * 1.1.0 requests move with their keys: delivered voices stay bought, unresolved voices keep their key,
     * unresolved drafts are kept as abandoned; resolved failures are not carried over; the old table goes.
     */
    public function test_move_110_requests(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $old = $this->old_table();
        $dbman->create_table($old);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $instance = $gen->create_instance(['course' => $course->id, 'targetlang' => 'es', 'targetlocale' => 'es-ES']);
        $scene = $gen->create_scene($instance, 'S', [['text' => 'Hola', 'x' => 10, 'y' => 10],
            ['text' => 'Adiós', 'x' => 20, 'y' => 20]]);
        $phrases = array_values($DB->get_records('ailanguageteacher_phrase', ['sceneid' => $scene->id], 'sortorder'));
        $voice = 'es-ES-Chirp3-HD-Kore';
        $row = fn($op, $target, $variant, $key, $body, $status) => $DB->insert_record(
            'ailanguageteacher_aireq',
            (object)['ailanguageteacherid' => $instance->id, 'userid' => $user->id, 'operation' => $op,
            'targetid' => $target, 'variant' => $variant, 'idemkey' => $key, 'body' => json_encode($body),
            'status' => $status,
            'tries' => 1,
            'timecreated' => 100,
            'timemodified' => 100]
        );
        $tts = fn($phrase) => ['text' => $phrase->text, 'locale' => 'es-ES', 'voice' => $voice, 'speed' => 'normal'];
        $row('tts', $phrases[0]->id, 'normal', 'k-done', $tts($phrases[0]), 'completed');
        $row('tts', $phrases[1]->id, 'example', 'k-unsure', $tts($phrases[1]), 'uncertain');
        $row('tts', $phrases[1]->id, 'normal', 'k-failed', $tts($phrases[1]), 'failed');
        $row('tts', 999999, 'normal', 'k-nophrase', $tts($phrases[1]), 'pending');
        $row('lesson', 0, null, 'k-draft', ['brief' => 'x'], 'pending');
        $row('lesson', 0, null, 'k-draftdone', ['brief' => 'y'], 'completed');

        mod_ailanguageteacher_upgrade_move_110_requests();

        $this->assertFalse($dbman->table_exists($old));
        $ops = $DB->get_records('ailanguageteacher_operation', ['ailanguageteacherid' => $instance->id], '', 'idemkey, *');
        $this->assertEqualsCanonicalizing(['k-done', 'k-unsure', 'k-draft'], array_keys($ops));
        $hash = fn($phrase) => hash('sha256', json_encode(['text' => $phrase->text, 'locale' => 'es-ES',
            'speed' => 'normal', 'voice' => $voice], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame('complete', $ops['k-done']->state);
        $this->assertSame($voice, $ops['k-done']->result);
        $this->assertEquals($phrases[0]->id * 3, $ops['k-done']->itemid);
        $this->assertSame($hash($phrases[0]), $ops['k-done']->bodyhash);

        $this->assertSame('pending', $ops['k-unsure']->state);
        $this->assertEquals($phrases[1]->id * 3 + 2, $ops['k-unsure']->itemid);
        $this->assertSame($hash($phrases[1]), $ops['k-unsure']->bodyhash);
        $this->assertSame('tts', $ops['k-unsure']->kind);

        $this->assertSame('abandoned', $ops['k-draft']->state);
        $this->assertSame(json_encode(['brief' => 'x']), $ops['k-draft']->body);

        // Running the step again (no old table) changes nothing.
        mod_ailanguageteacher_upgrade_move_110_requests();
        $this->assertSame(3, $DB->count_records('ailanguageteacher_operation', ['ailanguageteacherid' => $instance->id]));
    }
}
