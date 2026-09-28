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

/**
 * Removes the teacher's recording from a phrase (the generated voice is used again).
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_phrase_audio extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phraseid' => new external_value(PARAM_INT, 'Phrase id'),
        ]);
    }

    /**
     * Deletes.
     *
     * @param int $phraseid
     * @return array
     */
    public static function execute(int $phraseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['phraseid' => $phraseid]);
        [, , , $context, $phrase] = self::load_phrase($params['phraseid']);
        get_file_storage()->delete_area_files($context->id, 'mod_ailanguageteacher', 'phraseaudio', $phrase->id);
        return ['done' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'done' => new external_value(PARAM_BOOL, 'Done'),
        ]);
    }
}
