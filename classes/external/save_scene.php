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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_ailanguageteacher\local\manager;

/**
 * Saves a scene and its phrases from the scene editor.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_scene extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sceneid' => new external_value(PARAM_INT, 'Scene id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'context' => new external_value(PARAM_TEXT, 'What is happening', VALUE_DEFAULT, ''),
            'imageprompt' => new external_value(PARAM_TEXT, 'Picture description', VALUE_DEFAULT, ''),
            'situationid' => new external_value(PARAM_INT, 'Situation id', VALUE_DEFAULT, 0),
            'phrases' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Phrase id, 0 for new', VALUE_DEFAULT, 0),
                'text' => new external_value(PARAM_TEXT, 'Phrase'),
                'romanisation' => new external_value(PARAM_TEXT, 'Romanisation', VALUE_DEFAULT, ''),
                'translation' => new external_value(PARAM_TEXT, 'Meaning', VALUE_DEFAULT, ''),
                'usagenote' => new external_value(PARAM_TEXT, 'When to use it', VALUE_DEFAULT, ''),
                'example' => new external_value(PARAM_TEXT, 'Example', VALUE_DEFAULT, ''),
                'exampletrans' => new external_value(PARAM_TEXT, 'Example meaning', VALUE_DEFAULT, ''),
                'prompt' => new external_value(PARAM_TEXT, 'Speaking cue', VALUE_DEFAULT, ''),
                'alternatives' => new external_value(PARAM_TEXT, 'Accepted answers, one per line', VALUE_DEFAULT, ''),
                'anchor' => new external_value(PARAM_TEXT, 'Where it belongs in the picture', VALUE_DEFAULT, ''),
                'voicegender' => new external_value(
                    PARAM_ALPHA,
                    'Who says it: f, m or empty (from where it belongs)',
                    VALUE_DEFAULT,
                    ''
                ),
                'x' => new external_value(PARAM_FLOAT, 'X percent', VALUE_DEFAULT, 0),
                'y' => new external_value(PARAM_FLOAT, 'Y percent', VALUE_DEFAULT, 0),
                'placed' => new external_value(PARAM_INT, 'Placed on the picture', VALUE_DEFAULT, 0),
                'color' => new external_value(PARAM_TEXT, 'Colour', VALUE_DEFAULT, ''),
                'distractor' => new external_value(PARAM_INT, 'Distractor', VALUE_DEFAULT, 0),
            ]), 'Phrases', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Saves.
     *
     * @param int $sceneid
     * @param string $title
     * @param string $context
     * @param string $imageprompt
     * @param int $situationid
     * @param array $phrases
     * @return array
     */
    public static function execute(
        int $sceneid,
        string $title,
        string $context = '',
        string $imageprompt = '',
        int $situationid = 0,
        array $phrases = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['sceneid' => $sceneid, 'title' => $title,
            'context' => $context, 'imageprompt' => $imageprompt, 'situationid' => $situationid, 'phrases' => $phrases]);
        [, , , , $scene] = self::load_scene($params['sceneid']);
        $ids = manager::save_scene($scene, $params, $params['phrases']);
        return ['ids' => $ids];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ids' => new external_multiple_structure(new external_value(PARAM_INT, 'Saved phrase id in order')),
        ]);
    }
}
