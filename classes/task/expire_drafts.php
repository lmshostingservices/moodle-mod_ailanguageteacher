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
 * Expires temporary draft data.
 *
 * @package mod_ailanguageteacher
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_ailanguageteacher\task;

/** Remove Moodle's temporary plaintext draft and request brief after 24 hours. */
class expire_drafts extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'Expire temporary language lesson drafts';
    }
    public function execute(): void {
        global $DB;
        $DB->execute("UPDATE {ailanguageteacher_operation}
                         SET body = '', result = NULL, state = 'expired'
                       WHERE kind = :kind AND state = :state AND timecreated <= :before",
            ['kind' => 'lesson', 'state' => 'complete', 'before' => time() - DAYSECS]);
    }
}