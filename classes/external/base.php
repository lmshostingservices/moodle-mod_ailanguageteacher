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

use core_external\external_api;

/**
 * Shared loading and permission checks for the plugin's web services.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Loads an activity by course module id, validates the context and checks a capability.
     *
     * @param int $cmid
     * @param string $capability short name, such as "attempt"
     * @return array [course, cm, instance, context]
     */
    protected static function load_cm(int $cmid, string $capability): array {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'ailanguageteacher');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/ailanguageteacher:' . $capability, $context);
        // The whole plugin needs this site to be unlocked with LMS Labs.
        \mod_ailanguageteacher\local\unlock::require_active();
        $instance = $DB->get_record('ailanguageteacher', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$course, $cm, $instance, $context];
    }

    /**
     * Loads the current user's attempt with its activity.
     *
     * @param int $attemptid
     * @return array [course, cm, instance, context, attempt]
     */
    protected static function load_attempt(int $attemptid): array {
        global $DB, $USER;
        $attempt = \mod_ailanguageteacher\local\learning::get_user_attempt($attemptid, (int)$USER->id);
        $cm = get_coursemodule_from_instance('ailanguageteacher', $attempt->ailanguageteacherid, 0, false, MUST_EXIST);
        [$course, $cm, $instance, $context] = self::load_cm((int)$cm->id, 'attempt');
        return [$course, $cm, $instance, $context, $attempt];
    }

    /**
     * Loads a scene for a teacher who can manage the activity.
     *
     * @param int $sceneid
     * @param string $capability
     * @return array [course, cm, instance, context, scene]
     */
    protected static function load_scene(int $sceneid, string $capability = 'manage'): array {
        global $DB;
        $scene = $DB->get_record('ailanguageteacher_scene', ['id' => $sceneid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('ailanguageteacher', $scene->ailanguageteacherid, 0, false, MUST_EXIST);
        [$course, $cm, $instance, $context] = self::load_cm((int)$cm->id, 'manage');
        if ($capability !== 'manage') {
            require_capability('mod/ailanguageteacher:' . $capability, $context);
        }
        return [$course, $cm, $instance, $context, $scene];
    }

    /**
     * Loads a phrase for a teacher who can manage the activity.
     *
     * @param int $phraseid
     * @return array [course, cm, instance, context, phrase]
     */
    protected static function load_phrase(int $phraseid): array {
        global $DB;
        $phrase = $DB->get_record('ailanguageteacher_phrase', ['id' => $phraseid], '*', MUST_EXIST);
        [$course, $cm, $instance, $context] = self::load_scene((int)$phrase->sceneid);
        return [$course, $cm, $instance, $context, $phrase];
    }
}
