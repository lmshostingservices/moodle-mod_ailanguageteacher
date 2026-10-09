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
 * 2026101000 (1.3.0): repairs sites that came from 1.1.0, which kept requests in another table, so the stored
 * requests table was never created there ("Error reading from database" when creating scenes).
 *
 * @param int $oldversion the version being upgraded from
 * @return bool
 */
function xmldb_ailanguageteacher_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2026092801) {
        mod_ailanguageteacher_upgrade_operation_table();
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
    if ($oldversion < 2026101000) {
        mod_ailanguageteacher_upgrade_operation_table();
        mod_ailanguageteacher_upgrade_move_110_requests();
        // Voices that match who says each phrase, and "listen before speaking".
        $dbman = $DB->get_manager();
        $table = new xmldb_table('ailanguageteacher');
        $fields = [
            new xmldb_field('ttsvoice2', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'ttsvoice'),
            new xmldb_field('voicematch', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'ttsvoice2'),
            new xmldb_field('mustlisten', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'sounds'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('ailanguageteacher_phrase');
        $field = new xmldb_field('voicegender', XMLDB_TYPE_CHAR, '1', null, null, null, null, 'anchor');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026101000, 'ailanguageteacher');
    }
    return true;
}

/**
 * Creates the stored LMS Labs requests table when it is missing.
 */
function mod_ailanguageteacher_upgrade_operation_table(): void {
    global $DB;
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
}

/**
 * Moves what a 1.1.0 site kept in ailanguageteacher_aireq into ailanguageteacher_operation, then drops it.
 *
 * Phrase voices: delivered ones are kept as complete, so their saved audio still plays and is never bought again;
 * unresolved ones keep their key and are stored exactly as this version asks for them (item: phrase × 3 + variant;
 * body text, locale, speed, voice), so "Create voice" sends the same key again and cannot charge twice.
 * Lesson drafts: this version asks for drafts with a different body, so an unresolved 1.1.0 draft can never be sent
 * again with its key; it is kept as abandoned, with its key, for LMS Labs support.
 */
function mod_ailanguageteacher_upgrade_move_110_requests(): void {
    global $DB;
    $dbman = $DB->get_manager();
    $old = new xmldb_table('ailanguageteacher_aireq');
    if (!$dbman->table_exists($old)) {
        return;
    }
    $variants = ['normal', 'slow', 'example'];
    $rs = $DB->get_recordset_select(
        'ailanguageteacher_aireq',
        "status IN ('pending', 'uncertain', 'completed')",
        null,
        'id'
    );
    foreach ($rs as $row) {
        if ($DB->record_exists('ailanguageteacher_operation', ['idemkey' => $row->idemkey])) {
            continue;
        }
        $body = json_decode((string)$row->body, true);
        if (!is_array($body)) {
            continue;
        }
        $record = (object)['ailanguageteacherid' => $row->ailanguageteacherid, 'userid' => $row->userid,
            'kind' => $row->operation, 'itemid' => 0, 'idemkey' => $row->idemkey, 'result' => null,
            'timecreated' => (int)$row->timecreated];
        if ($row->operation === 'tts') {
            $variant = in_array((string)$row->variant, $variants, true) ? (string)$row->variant : 'normal';
            if (!$DB->record_exists('ailanguageteacher_phrase', ['id' => $row->targetid])) {
                continue;
            }
            $voice = (string)($body['voice'] ?? '');
            $request = ['text' => (string)($body['text'] ?? ''), 'locale' => (string)($body['locale'] ?? ''),
                'speed' => (string)($body['speed'] ?? 'normal'), 'voice' => $voice];
            $record->itemid = (int)$row->targetid * 3 + array_search($variant, $variants, true);
            $record->bodyhash = hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $record->body = '';
            $record->state = $row->status === 'completed' ? 'complete' : 'pending';
            $record->result = $row->status === 'completed' ? $voice : null;
        } else if ($row->operation === 'lesson' && $row->status !== 'completed') {
            $record->bodyhash = hash('sha256', (string)$row->body);
            $record->body = (string)$row->body;
            $record->state = 'abandoned';
        } else {
            continue;
        }
        $DB->insert_record('ailanguageteacher_operation', $record);
    }
    $rs->close();
    $dbman->drop_table($old);
}
