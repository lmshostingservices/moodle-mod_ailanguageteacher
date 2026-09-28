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

namespace mod_ailanguageteacher\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\ai\factory;
use mod_ailanguageteacher\local\lesson;
use mod_ailanguageteacher\local\operation;
use moodle_exception;

/**
 * Drafts scenes and phrases with the site's AI provider. The draft is returned for the teacher to review; nothing is
 * saved until import_lesson.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_lesson extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'newdraft' => new external_value(PARAM_BOOL, 'Explicitly draft another lesson', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Generates.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid, bool $newdraft = false): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'newdraft' => $newdraft]);
        [, , $instance, $context] = self::load_cm($params['cmid'], 'manage');
        require_capability('mod/ailanguageteacher:useai', $context);
        $provider = factory::get();
        if (!$provider->can_generate()) {
            throw new moodle_exception('ainotavailable', 'mod_ailanguageteacher');
        }
        $body = lesson::draft_request($instance);
        if (!confirm_sesskey()) {
            throw new moodle_exception('invalidsesskey');
        }
        $operation = operation::claim((int)$instance->id, (int)$USER->id, 'lesson', 0, $body, $newdraft);
        if ($operation->result !== null) {
            return ['draft' => $operation->result];
        }
        try {
            $draft = lesson::clean(lesson::from_approved($provider->draft($body, $operation->idemkey), $instance));
            $json = json_encode($draft, JSON_UNESCAPED_UNICODE);
            operation::complete($operation, $json);
        } catch (\Throwable $e) {
            if ($e instanceof moodle_exception && $e->errorcode === 'operationexpired') {
                operation::expired($operation);
            }
            throw $e;
        }
        lesson::log_ai((int)$instance->id, (int)$USER->id, 'lesson', 'ok');
        return ['draft' => $json];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'draft' => new external_value(PARAM_TEXT, 'Cleaned lesson draft as JSON'),
        ]);
    }
}
