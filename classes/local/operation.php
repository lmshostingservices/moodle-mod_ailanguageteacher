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

namespace mod_ailanguageteacher\local;

/**
 * Moodle's durable record of each paid LMS Labs request (claim), saved with its key before anything is sent.
 *
 * States: pending (sent, or about to be; the same key is used for every try), complete, expired (LMS Labs no longer
 * has it), failed (LMS Labs refused it and charged nothing) and abandoned (the teacher chose to start again). A key is
 * never rotated on an ambiguous answer: only a refusal, an expiry or the teacher's explicit "start again" ends a
 * pending request.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class operation {
    /**
     * The request to send for this activity, user, kind and item: the pending one (same key), a recent complete one
     * (lesson replay), or a new one.
     *
     * @param int $aid activity id
     * @param int $userid
     * @param string $kind lesson, import or tts
     * @param int $itemid
     * @param array $body exact JSON body
     * @param bool $new ask for a new lesson draft even when a recent identical one exists
     * @param bool $discard the teacher confirmed that an unfinished request with different content may be abandoned
     * @return \stdClass
     */
    public static function claim(
        int $aid,
        int $userid,
        string $kind,
        int $itemid,
        array $body,
        bool $new = false,
        bool $discard = false
    ): \stdClass {
        global $DB;
        $hash = self::hash($body);
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
                if ($row->bodyhash === $hash) {
                    return $row;
                }
                if (!$discard) {
                    throw new \moodle_exception('operationconflict', 'mod_ailanguageteacher');
                }
                self::finish($row, 'abandoned');
            }
            $old = $DB->get_records('ailanguageteacher_operation', [
                'ailanguageteacherid' => $aid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid, 'state' => 'complete',
            ], 'id DESC', '*', 0, 1);
            $old = $old ? reset($old) : null;
            if ($old && $kind === 'lesson' && !$new && $old->bodyhash === $hash && $old->timecreated + DAYSECS > time()) {
                return $old;
            }
            if ($kind === 'lesson' || $kind === 'import') {
                lesson::check_ai_rate($userid);
            }
            $row = (object)['ailanguageteacherid' => $aid, 'userid' => $userid, 'kind' => $kind,
                'itemid' => $itemid, 'bodyhash' => $hash,
                'body' => $kind === 'tts' ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'idemkey' => \core\uuid::generate(), 'state' => 'pending', 'result' => null,
                'timecreated' => time()];
            $row->id = $DB->insert_record('ailanguageteacher_operation', $row);
            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * The hash a request body is matched with.
     *
     * @param array $body
     * @return string
     */
    public static function hash(array $body): string {
        return hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Marks a request delivered, with what it produced.
     *
     * @param \stdClass $row
     * @param string|null $result
     */
    public static function complete(\stdClass $row, ?string $result = null): void {
        global $DB;
        $DB->update_record('ailanguageteacher_operation', (object)[
            'id' => $row->id, 'state' => 'complete', 'result' => $result,
        ]);
    }

    /**
     * Marks a request gone at LMS Labs (410).
     *
     * @param \stdClass $row
     */
    public static function expired(\stdClass $row): void {
        self::finish($row, 'expired');
    }

    /**
     * Ends a request that LMS Labs refused (nothing was charged), so the next click is a new request.
     *
     * @param \stdClass $row
     */
    public static function failed(\stdClass $row): void {
        self::finish($row, 'failed');
    }

    /**
     * Ends a request with a final state; its key is kept for reference.
     *
     * @param \stdClass $row
     * @param string $state
     */
    protected static function finish(\stdClass $row, string $state): void {
        global $DB;
        $DB->update_record('ailanguageteacher_operation', (object)[
            'id' => $row->id, 'state' => $state, 'result' => null, 'body' => '',
        ]);
    }

    /**
     * Records the answer to a request that did not deliver, then throws the teacher-facing message.
     *
     * @param \stdClass $row
     * @param int $status
     * @param string $body
     * @param array $headers
     * @throws \moodle_exception always
     */
    public static function not_delivered(\stdClass $row, int $status, string $body, array $headers = []): void {
        $outcome = remote::outcome($status, $body);
        if ($outcome === 'gone') {
            self::expired($row);
            $data = json_decode($body, true);
            $reference = clean_param((string)($headers['x-request-id'] ?? ''), PARAM_ALPHANUMEXT);
            if ($reference === '' && is_array($data) && is_scalar($data['requestId'] ?? null)) {
                $reference = clean_param((string)$data['requestId'], PARAM_ALPHANUMEXT);
            }
            $reference = $reference !== '' ? substr($reference, 0, 64) : (string)$row->idemkey;
            throw new \moodle_exception(
                $row->kind === 'tts' ? 'speechresultnotretained' : 'operationexpired',
                'mod_ailanguageteacher',
                '',
                $reference
            );
        }
        if ($outcome === 'refused') {
            self::failed($row);
        }
        remote::fail($status, $body, $headers);
    }
}
