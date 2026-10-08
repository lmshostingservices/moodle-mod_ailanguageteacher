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
 * Site settings for mod_ailanguageteacher.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $component = 'mod_ailanguageteacher';

    // Activation is part of this page: status, "Check access" and "Unlock" (confirmed on the next step).
    $settings->add(new \mod_ailanguageteacher\admin\setting_activation("$component/activation"));

    $settings->add(new admin_setting_heading(
        "$component/speechheading",
        get_string('settings_speech', $component),
        get_string('settings_speech_desc', $component)
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/browserspeech",
        get_string('browserspeech', $component),
        get_string('browserspeech_desc', $component),
        1
    ));
    $settings->add(new admin_setting_configtext(
        "$component/speechrate",
        get_string('speechrate', $component),
        get_string('speechrate_desc', $component),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        "$component/aiheading",
        get_string('settings_ai', $component),
        get_string('settings_ai_desc', $component) . '<p><strong>' .
            get_string(\mod_ailanguageteacher\local\credentials::status(), $component) . '</strong></p>'
    ));
    $settings->add(new admin_setting_configtext(
        "$component/lmslabssiteid",
        get_string('lmslabssiteid', $component),
        get_string('lmslabssiteid_desc', $component),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        "$component/lmslabsapikey",
        get_string('lmslabsapikey', $component),
        get_string('lmslabsapikey_desc', $component),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        "$component/airate",
        get_string('airate', $component),
        get_string('airate_desc', $component),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        "$component/defaultsheading",
        get_string('settings_defaults', $component),
        ''
    ));
    $languages = \mod_ailanguageteacher\local\languages::options();
    $settings->add(new admin_setting_configselect(
        "$component/defaulttargetlang",
        get_string('targetlang', $component),
        '',
        'en',
        $languages
    ));
    $settings->add(new admin_setting_configselect(
        "$component/defaultsupportlang",
        get_string('supportlang', $component),
        '',
        'en',
        $languages
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/defaultsounds",
        get_string('sounds', $component),
        get_string('sounds_desc', $component),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        "$component/defaultleaderboard",
        get_string('leaderboard', $component),
        get_string('leaderboard_desc', $component),
        0
    ));
}
