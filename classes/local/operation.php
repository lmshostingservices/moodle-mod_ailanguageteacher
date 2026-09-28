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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Persisted outbound operation claims.
 *
 * @package mod_ailanguageteacher
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_ailanguageteacher\local;

/**
 * Moodle's durable outbound claim. Never rotate a key on an ambiguous response.
 */
final class operation {
    public static function claim(int $aid, int $userid, string $kind, int $itemid, array $body,
            bool $new = false): \stdClass {
        global $DB;
        $hash = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $lock = \core\lock\lock_config::get_lock_factory('mod_ailanguageteacher_operation')
            ->get_lock("$aid:$userid:$kind:$itemid", 10);
        if (!$lock) {
            throw new \moodle_exception('operationpending', 'mod_ailanguageteacher');
        }
        try {
            $row = $DB->get_record('ailanguageteacher_operation', [
                'ailanguageteacherid' => $aid, 'userid' => $userid, 'kind' => $kind, 'itemid' => $itemid,
                'state' => 'pending',
            ]);
            if ($row) {
                if ($row->bodyhash !== $hash) {
                    throw new \moodle_exception('operationconflict', 'mod_ailanguageteacher');
                }
                return $row;
            }
            $old = $DB->get_records('ailanguageteacher_operation', [
                'ailanguageteacherid' => $aid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid, 'state' => 'complete',
            ], 'id DESC', '*', 0, 1);
            $old = $old ? reset($old) : null;
            if ($old && $kind === 'lesson' && !$new) {
                if ($old->timecreated + DAYSECS <= time()) {
                    $DB->update_record('ailanguageteacher_operation',
                        (object)['id' => $old->id, 'result' => null, 'body' => '', 'state' => 'expired']);
                    throw new \moodle_exception('operationexpired', 'mod_ailanguageteacher');
                }
                if ($old->bodyhash === $hash) {
                    return $old;
                }
            }
            if ($kind === 'lesson') {
                lesson::check_ai_rate($userid);
            }
            $row = (object)['ailanguageteacherid' => $aid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid, 'bodyhash' => $hash,
                'body' => $kind === 'tts' ? '' : json_encode($body),
                'idemkey' => \core\uuid::generate(), 'state' => 'pending', 'result' => null,
                'timecreated' => time()];
            $row->id = $DB->insert_record('ailanguageteacher_operation', $row);
            return $row;
        } finally {
            $lock->release();
        }
    }

    public static function complete(\stdClass $row, ?string $result = null): void {
        global $DB;
        $DB->update_record('ailanguageteacher_operation', (object)[
            'id' => $row->id, 'state' => 'complete', 'result' => $result,
        ]);
    }

    public static function expired(\stdClass $row): void {
        global $DB;
        $DB->update_record('ailanguageteacher_operation', (object)[
            'id' => $row->id, 'state' => 'expired', 'result' => null, 'body' => '',
        ]);
    }
}