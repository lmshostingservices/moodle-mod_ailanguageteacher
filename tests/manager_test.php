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

namespace mod_ailanguageteacher;

use mod_ailanguageteacher\local\languages;
use mod_ailanguageteacher\local\manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for lesson content: scenes, phrases, pictures and recordings.
 *
 * @package    mod_ailanguageteacher
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ailanguageteacher\local\manager
 * @covers     \mod_ailanguageteacher\local\languages
 */
#[CoversClass(manager::class)]
#[CoversClass(languages::class)]
final class manager_test extends \advanced_testcase {
    /**
     * File signatures.
     */
    public function test_signatures(): void {
        global $CFG;
        $png = file_get_contents($CFG->dirroot . '/mod/ailanguageteacher/tests/fixtures/scene.png');
        $this->assertSame('png', manager::image_signature(substr($png, 0, 16)));
        $this->assertSame('jpg', manager::image_signature("\xFF\xD8\xFF\xE0xxxxxxxxxxxx"));
        $this->assertSame('webp', manager::image_signature('RIFF1234WEBPVP8 '));
        $this->assertNull(manager::image_signature('<svg xmlns="http'));
        $this->assertSame('webm', manager::audio_signature("\x1A\x45\xDF\xA3xxxx"));
        $this->assertSame('ogg', manager::audio_signature('OggSxxxx'));
        $this->assertSame('m4a', manager::audio_signature("\0\0\0\x20ftypM4A "));
        $this->assertNull(manager::audio_signature('<?php echo 1;'));
    }

    /**
     * Saving a scene cleans and limits phrases; removing a phrase removes its progress.
     */
    public function test_save_scene(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lt = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $scene = $lt->create_scene($instance, 'Arriving', [['text' => 'Hi', 'x' => 10, 'y' => 10]]);
        $ids = manager::save_scene($scene, ['title' => '  New title ', 'context' => 'x'], [
            ['text' => ' Good   morning <script>x</script>', 'x' => 150, 'y' => -3, 'placed' => 1, 'color' => 'red',
                'alternatives' => "Morning\n\nMorning\nHiya"],
            ['text' => 'Night', 'distractor' => 1, 'placed' => 1, 'x' => 50],
            ['text' => ''],
        ]);
        $this->assertCount(2, $ids);
        $first = $DB->get_record('ailanguageteacher_phrase', ['id' => $ids[0]]);
        $this->assertSame('Good morning x', $first->text);
        $this->assertEquals(100, $first->x);
        $this->assertEquals(0, $first->y);
        $this->assertSame('', $first->color);
        $this->assertSame("Morning\nHiya", $first->alternatives);
        $second = $DB->get_record('ailanguageteacher_phrase', ['id' => $ids[1]]);
        $this->assertSame('0', (string)$second->placed);
        $this->assertSame('New title', $DB->get_field('ailanguageteacher_scene', 'title', ['id' => $scene->id]));
        $this->assertSame(0, $DB->count_records('ailanguageteacher_phrase', ['text' => 'Hi']));

        $too = array_fill(0, manager::MAX_PHRASES + 1, ['text' => 'x']);
        $this->expectException(\moodle_exception::class);
        manager::save_scene($scene, ['title' => 't'], $too);
    }

    /**
     * Deleting and moving scenes.
     */
    public function test_delete_and_move(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lt = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $a = $lt->create_scene($instance, 'A', [['text' => 'One', 'x' => 1, 'y' => 1]], 'S1');
        $b = $lt->create_scene($instance, 'B', [['text' => 'Two', 'x' => 1, 'y' => 1]], 'S2');
        manager::move_scene($b, -1);
        $this->assertSame(['B', 'A'], array_values(array_column(manager::get_scenes((int)$instance->id), 'title')));
        $cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id);
        $context = \context_module::instance($cm->id);
        $this->assertNotNull(manager::get_scene_file($context, (int)$a->id));
        manager::delete_scene($context, $a);
        $this->assertNull(manager::get_scene_file($context, (int)$a->id));
        $this->assertSame(1, $DB->count_records('ailanguageteacher_phrase'));
        $this->assertSame(1, $DB->count_records('ailanguageteacher_situation'), 'Empty situations are removed.');
    }

    /**
     * Picture and recording uploads are checked.
     */
    public function test_upload_checks(): void {
        global $CFG;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lt = $this->getDataGenerator()->get_plugin_generator('mod_ailanguageteacher');
        $instance = $lt->create_instance(['course' => $course->id]);
        $scene = $lt->create_scene($instance, 'A', [['text' => 'One', 'x' => 1, 'y' => 1]]);
        $cm = get_coursemodule_from_instance('ailanguageteacher', $instance->id);
        $context = \context_module::instance($cm->id);
        manager::save_scene_image_bytes(
            $context,
            $scene,
            file_get_contents($CFG->dirroot . '/mod/ailanguageteacher/tests/fixtures/scene.png')
        );
        $this->assertStringEndsWith('.png', manager::get_scene_file($context, (int)$scene->id)->get_filename());
        $phrase = $GLOBALS['DB']->get_record('ailanguageteacher_phrase', ['sceneid' => $scene->id]);
        $url = manager::save_recording($context, $phrase, 'OggS' . str_repeat('a', 200));
        $this->assertStringContainsString('/phraseaudio/', $url);
        try {
            manager::save_recording($context, $phrase, '<?php phpinfo(); ' . str_repeat('a', 200));
            $this->fail('Expected an exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('badrecording', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        manager::save_scene_image_bytes($context, $scene, '<svg><script>alert(1)</script></svg>');
    }

    /**
     * Settings are normalised.
     */
    public function test_prepare_instance_data(): void {
        $data = manager::prepare_instance_data((object)['targetlang' => 'xx', 'targetlocale' => 'fr-FR', 'supportlang' => 'th',
            'cefrlevel' => 'Z9', 'passscore' => 500, 'repetitions' => 0, 'allowstudy' => 0, 'allowpractice' => 0,
            'allowtest' => 0]);
        $this->assertSame('en', $data->targetlang);
        $this->assertSame('en-GB', $data->targetlocale);
        $this->assertSame('A1', $data->cefrlevel);
        $this->assertSame(100, $data->passscore);
        $this->assertSame(3, $data->repetitions);
        $this->assertSame(1, $data->allowpractice);
    }

    /**
     * The language catalogue is consistent.
     */
    public function test_catalogue(): void {
        foreach (languages::LANGUAGES as $code => $info) {
            $this->assertTrue(get_string_manager()->string_exists('lang_' . $code, 'mod_ailanguageteacher'), $code);
            foreach ($info[1] as $locale) {
                $this->assertNotSame($locale, languages::locale_name($locale));
            }
        }
        foreach (languages::SITUATIONS as $group => $keys) {
            $this->assertTrue(get_string_manager()->string_exists('sitgroup_' . $group, 'mod_ailanguageteacher'));
            foreach ($keys as $key) {
                $this->assertTrue(languages::is_situation($key));
                $this->assertNotEmpty(languages::situation_name($key));
            }
        }
        $this->assertTrue(languages::no_spaces('th'));
        $this->assertTrue(languages::is_rtl('ar'));
        $this->assertFalse(languages::is_locale('en', 'fr-FR'));
    }
}
