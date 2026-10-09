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
 * External functions for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_ailanguageteacher_voice_choices' => [
        'classname' => \mod_ailanguageteacher\external\voice_choices::class,
        'description' => 'Available live speech voices for the teacher.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage,mod/ailanguageteacher:useai',
    ],
    'mod_ailanguageteacher_quote_audio' => [
        'classname' => \mod_ailanguageteacher\external\quote_audio::class,
        'description' => 'Prices phrase voices before the teacher confirms (free; nothing is made).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage,mod/ailanguageteacher:useai',
    ],
    'mod_ailanguageteacher_create_audio' => [
        'classname' => \mod_ailanguageteacher\external\create_audio::class,
        'description' => 'Teacher explicitly creates one charged phrase audio file.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage,mod/ailanguageteacher:useai',
    ],
    'mod_ailanguageteacher_start_attempt' => [
        'classname' => \mod_ailanguageteacher\external\start_attempt::class,
        'description' => 'Starts a practice or test attempt and returns the player data.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_submit_scene' => [
        'classname' => \mod_ailanguageteacher\external\submit_scene::class,
        'description' => 'Submits and marks one test stage of a scene.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_practice_event' => [
        'classname' => \mod_ailanguageteacher\external\practice_event::class,
        'description' => 'Records a practice step for a phrase: matched, hint or move on.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_assess_speech' => [
        'classname' => \mod_ailanguageteacher\external\assess_speech::class,
        'description' => 'Scores the learner saying a phrase.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_get_audio' => [
        'classname' => \mod_ailanguageteacher\external\get_audio::class,
        'description' => 'Returns the audio for a phrase, creating it if needed.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:view',
    ],
    'mod_ailanguageteacher_finish_attempt' => [
        'classname' => \mod_ailanguageteacher\external\finish_attempt::class,
        'description' => 'Finishes and grades an attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_record_study' => [
        'classname' => \mod_ailanguageteacher\external\record_study::class,
        'description' => 'Records the phrases a learner has studied.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_reset_progress' => [
        'classname' => \mod_ailanguageteacher\external\reset_progress::class,
        'description' => 'Clears the learner\'s own practice progress.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:attempt',
    ],
    'mod_ailanguageteacher_save_scene' => [
        'classname' => \mod_ailanguageteacher\external\save_scene::class,
        'description' => 'Saves a scene and its phrases.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage',
    ],
    'mod_ailanguageteacher_save_phrase_audio' => [
        'classname' => \mod_ailanguageteacher\external\save_phrase_audio::class,
        'description' => 'Saves a teacher recording for a phrase.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage',
    ],
    'mod_ailanguageteacher_delete_phrase_audio' => [
        'classname' => \mod_ailanguageteacher\external\delete_phrase_audio::class,
        'description' => 'Removes a teacher recording from a phrase.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage',
    ],
    'mod_ailanguageteacher_generate_lesson' => [
        'classname' => \mod_ailanguageteacher\external\generate_lesson::class,
        'description' => 'Drafts scenes and phrases with AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:useai',
    ],
    'mod_ailanguageteacher_import_lesson' => [
        'classname' => \mod_ailanguageteacher\external\import_lesson::class,
        'description' => 'Creates scenes and phrases from a lesson draft.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:manage',
    ],
    'mod_ailanguageteacher_generate_image' => [
        'classname' => \mod_ailanguageteacher\external\generate_image::class,
        'description' => 'Creates a scene picture with AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/ailanguageteacher:useai',
    ],
];
