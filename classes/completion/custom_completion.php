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

declare(strict_types=1);

namespace mod_ailanguageteacher\completion;

use core_completion\activity_custom_completion;
use mod_ailanguageteacher\local\learning;

/**
 * Custom completion rules: study every scene, master every phrase, finish the Test.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $instance = $DB->get_record('ailanguageteacher', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $userid = (int)$this->userid;
        if ($rule === 'completionstudy') {
            $done = learning::has_studied((int)$instance->id, $userid);
        } else if ($rule === 'completionmastery') {
            $context = \context_module::instance($this->cm->id);
            $counts = learning::practice_counts($instance, $context, $userid);
            $done = $counts['total'] > 0 && $counts['mastered'] >= $counts['total'];
        } else {
            $done = $DB->record_exists('ailanguageteacher_attempt', ['ailanguageteacherid' => $instance->id,
                'userid' => $userid, 'kind' => $instance->allowtest ? 'test' : 'practice', 'state' => 'finished']);
        }
        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Custom rules defined by this module.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionstudy', 'completionmastery', 'completionfinish'];
    }

    /**
     * Human readable descriptions of the active rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionstudy' => get_string('completiondetail:study', 'mod_ailanguageteacher'),
            'completionmastery' => get_string('completiondetail:mastery', 'mod_ailanguageteacher'),
            'completionfinish' => get_string('completiondetail:finish', 'mod_ailanguageteacher'),
        ];
    }

    /**
     * Sort order of completion rules on the activity page.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionstudy', 'completionmastery', 'completionfinish', 'completionusegrade',
            'completionpassgrade'];
    }
}
