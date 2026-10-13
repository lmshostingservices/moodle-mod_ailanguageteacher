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
use moodle_exception;
use mod_ailanguageteacher\local\pictures;

/**
 * Creates a scene picture with LMS Labs AI (2 credits per picture, confirmed by the teacher first).
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_image extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sceneid' => new external_value(PARAM_INT, 'Scene id'),
            'replace' => new external_value(
                PARAM_BOOL,
                'Make a new picture even when the scene has one',
                VALUE_DEFAULT,
                false
            ),
            'discard' => new external_value(
                PARAM_BOOL,
                'The teacher confirmed an unfinished request may be abandoned',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Creates one scene picture with LMS Labs (2 credits), after the teacher confirmed the price.
     *
     * @param int $sceneid
     * @param bool $replace
     * @param bool $discard
     * @return array
     */
    public static function execute(int $sceneid, bool $replace = false, bool $discard = false): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['sceneid' => $sceneid, 'replace' => $replace, 'discard' => $discard]
        );
        [, , $instance, $context, $scene] = self::load_scene($params['sceneid'], 'useai');
        if (!pictures::enabled()) {
            throw new moodle_exception('pictures_notenabled', 'mod_ailanguageteacher');
        }
        $result = pictures::create($instance, $context, $scene, (int)$USER->id, $params['replace'], $params['discard']);
        return ['url' => $result['url'], 'charged' => $result['charged'] ?? -1, 'made' => $result['made']];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'Picture URL'),
            'charged' => new external_value(PARAM_INT, 'Credits LMS Labs reported, -1 when it did not say'),
            'made' => new external_value(PARAM_BOOL, 'A new picture was made (false: the scene already had one)'),
        ]);
    }
}
