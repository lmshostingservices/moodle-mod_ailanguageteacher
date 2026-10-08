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

/**
 * Upgrade steps for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps between the installed version and this one.
 *
 * 2026092801 (1.0.2): stored LMS Labs requests. 2026100800 (1.2.0): the activity's phrase voice.
 *
 * @param int $oldversion the version being upgraded from
 * @return bool
 */
function xmldb_ailanguageteacher_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2026092801) {
        $table = new xmldb_table('ailanguageteacher_operation');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ailanguageteacherid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('bodyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('idemkey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
        $table->add_field('state', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL);
        $table->add_field('result', XMLDB_TYPE_TEXT);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key(
            'ailanguageteacherid',
            XMLDB_KEY_FOREIGN,
            ['ailanguageteacherid'],
            'ailanguageteacher',
            ['id']
        );
        $table->add_index('requestkey', XMLDB_INDEX_UNIQUE, ['idemkey']);
        $table->add_index(
            'scope',
            XMLDB_INDEX_NOTUNIQUE,
            ['ailanguageteacherid', 'userid', 'kind', 'itemid', 'state']
        );
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026092801, 'ailanguageteacher');
    }
    if ($oldversion < 2026100800) {
        // The LMS Labs voice used for all of the activity's phrase audio (chosen once in the Voices step).
        $table = new xmldb_table('ailanguageteacher');
        $field = new xmldb_field('ttsvoice', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'imagestyle');
        $dbman = $DB->get_manager();
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026100800, 'ailanguageteacher');
    }
    return true;
}
