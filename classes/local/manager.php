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

namespace mod_ailanguageteacher\local;

use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Lesson content: situations, scenes, phrases, pictures and audio files.
 *
 * Learner attempts and progress are in {@see learning}.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string[] Default phrase colour palette. */
    public const PALETTE = ['#6366F1', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#EC4899', '#8B5CF6', '#14B8A6',
        '#F97316', '#84CC16', '#06B6D4', '#D946EF'];

    /** @var int Phrases on one picture: one moment, such as a greeting and its reply. More phrases need a new picture. */
    public const MAX_PHRASES = 2;

    /** @var int Distractors (phrases that do not belong) on one picture. */
    public const MAX_DISTRACTORS = 2;

    /** @var string[] Accepted picture types. */
    public const IMAGE_TYPES = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    /** @var string[] File areas served by pluginfile. */
    public const FILEAREAS = ['sceneimage', 'phraseaudio', 'ttsaudio'];

    /** @var int Most pictures accepted in one upload. */
    public const MAX_UPLOAD_IMAGES = 100;

    /** @var int Largest unpacked size of an uploaded ZIP, in bytes. */
    public const MAX_ZIP_BYTES = 209715200;

    /** @var int Largest teacher recording, in bytes. */
    public const MAX_RECORDING_BYTES = 2097152;

    /** @var int Grade method: highest attempt. */
    public const GRADE_HIGHEST = 1;

    /** @var int Grade method: average of attempts. */
    public const GRADE_AVERAGE = 2;

    /** @var int Grade method: first attempt. */
    public const GRADE_FIRST = 3;

    /** @var int Grade method: last attempt. */
    public const GRADE_LAST = 4;

    /**
     * Normalises activity settings before saving.
     *
     * @param stdClass $data
     * @return stdClass
     */
    public static function prepare_instance_data(stdClass $data): stdClass {
        $flags = ['allowstudy', 'allowpractice', 'allowtest', 'sequential', 'speaking', 'consecutive', 'testlistening',
            'testspeaking', 'shufflelabels', 'sounds', 'mustlisten', 'voicematch', 'leaderboard', 'showromanisation',
            'completionstudy', 'completionmastery', 'completionfinish'];
        foreach ($flags as $flag) {
            if (property_exists($data, $flag)) {
                $data->$flag = empty($data->$flag) ? 0 : 1;
            }
        }
        if (
            isset($data->allowstudy, $data->allowpractice, $data->allowtest) && !$data->allowstudy && !$data->allowpractice
                && !$data->allowtest
        ) {
            $data->allowpractice = 1;
        }
        if (isset($data->targetlang) && !languages::is_language($data->targetlang)) {
            $data->targetlang = 'en';
        }
        if (isset($data->targetlang)) {
            if (empty($data->targetlocale) || !languages::is_locale($data->targetlang, $data->targetlocale)) {
                $data->targetlocale = languages::default_locale($data->targetlang);
            }
        }
        if (isset($data->supportlang) && !languages::is_language($data->supportlang)) {
            $data->supportlang = 'en';
        }
        if (isset($data->cefrlevel) && !in_array($data->cefrlevel, languages::LEVELS, true)) {
            $data->cefrlevel = 'A1';
        }
        if (isset($data->imagestyle) && !in_array($data->imagestyle, ['illustration', 'photo'], true)) {
            $data->imagestyle = 'illustration';
        }
        $ranges = ['passscore' => [40, 100, 80], 'repetitions' => [1, 10, 3], 'maxspeaktries' => [1, 50, 8]];
        foreach ($ranges as $field => [$min, $max, $default]) {
            if (property_exists($data, $field)) {
                $value = (int)$data->$field;
                $data->$field = $value ? max($min, min($max, $value)) : $default;
            }
        }
        if (!isset($data->grade)) {
            $data->grade = 100;
        }
        if (property_exists($data, 'timelimit')) {
            $data->timelimit = empty($data->timelimit) ? 0 : (int)$data->timelimit;
        }
        return $data;
    }

    /**
     * Marks the activity viewed and triggers the event.
     *
     * @param stdClass $instance
     * @param stdClass $course
     * @param \cm_info|stdClass $cm
     * @param \context_module $context
     */
    public static function view(stdClass $instance, stdClass $course, $cm, \context_module $context): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $event = \mod_ailanguageteacher\event\course_module_viewed::create([
            'objectid' => $instance->id,
            'context' => $context,
        ]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('ailanguageteacher', $instance);
        $event->trigger();
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);
    }

    /**
     * Applies the grading method to a list of attempts (ordered by attempt number).
     *
     * @param array $attempts
     * @param int $method
     * @return float percentage
     */
    public static function calculate_percent(array $attempts, int $method): float {
        $attempts = array_values($attempts);
        if (!$attempts) {
            return 0.0;
        }
        switch ($method) {
            case self::GRADE_AVERAGE:
                $sum = 0;
                foreach ($attempts as $a) {
                    $sum += (float)$a->grade;
                }
                return $sum / count($attempts);
            case self::GRADE_FIRST:
                return (float)$attempts[0]->grade;
            case self::GRADE_LAST:
                return (float)$attempts[count($attempts) - 1]->grade;
            default:
                $max = 0.0;
                foreach ($attempts as $a) {
                    $max = max($max, (float)$a->grade);
                }
                return $max;
        }
    }

    /**
     * User grades computed from finished Test attempts.
     *
     * @param stdClass $instance
     * @param int $userid 0 for all users
     * @return array userid => stdClass(userid, rawgrade, dategraded, datesubmitted)
     */
    public static function get_user_grades(stdClass $instance, int $userid = 0): array {
        global $DB;
        $params = ['aid' => $instance->id, 'kind' => 'test', 'state' => 'finished'];
        $where = 'ailanguageteacherid = :aid AND kind = :kind AND state = :state';
        if ($userid) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $grades = [];
        $current = null;
        $list = [];
        $flush = function () use (&$grades, &$current, &$list, $instance) {
            if ($current === null || !$list) {
                return;
            }
            $percent = self::calculate_percent($list, (int)$instance->grademethod);
            $last = end($list);
            $grade = new stdClass();
            $grade->userid = $current;
            $grade->rawgrade = $instance->grade > 0 ? round($percent * $instance->grade / 100, 5) : null;
            $grade->dategraded = $last->timefinish;
            $grade->datesubmitted = $last->timefinish;
            $grades[$current] = $grade;
        };
        $rs = $DB->get_recordset_select(
            'ailanguageteacher_attempt',
            $where,
            $params,
            'userid, attempt',
            'id, userid, attempt, grade, timefinish'
        );
        try {
            foreach ($rs as $attempt) {
                if ((int)$attempt->userid !== $current) {
                    $flush();
                    $current = (int)$attempt->userid;
                    $list = [];
                }
                $list[] = $attempt;
            }
            $flush();
        } finally {
            $rs->close();
        }
        return $grades;
    }

    /**
     * Situations of an activity, ordered.
     *
     * @param int $aid
     * @return stdClass[]
     */
    public static function get_situations(int $aid): array {
        global $DB;
        return $DB->get_records('ailanguageteacher_situation', ['ailanguageteacherid' => $aid], 'sortorder ASC, id ASC');
    }

    /**
     * Scenes of an activity, ordered.
     *
     * @param int $aid
     * @return stdClass[]
     */
    public static function get_scenes(int $aid): array {
        global $DB;
        return $DB->get_records('ailanguageteacher_scene', ['ailanguageteacherid' => $aid], 'sortorder ASC, id ASC');
    }

    /**
     * Phrases grouped by scene id.
     *
     * @param int[] $sceneids
     * @return array sceneid => stdClass[]
     */
    public static function get_phrases(array $sceneids): array {
        global $DB;
        $result = array_fill_keys($sceneids, []);
        if (!$sceneids) {
            return $result;
        }
        [$insql, $params] = $DB->get_in_or_equal($sceneids, SQL_PARAMS_NAMED);
        $phrases = $DB->get_records_select('ailanguageteacher_phrase', "sceneid $insql", $params, 'sortorder ASC, id ASC');
        foreach ($phrases as $phrase) {
            $result[$phrase->sceneid][] = $phrase;
        }
        return $result;
    }

    /**
     * Placed, non-distractor phrases of a scene in pin order (top to bottom, so leader lines don't cross).
     *
     * @param stdClass[] $phrases
     * @return stdClass[]
     */
    public static function order_pins(array $phrases): array {
        $pins = array_values(array_filter($phrases, fn($p) => empty($p->distractor) && !empty($p->placed)));
        usort($pins, fn($a, $b) => ((float)$a->y <=> (float)$b->y) ?: ((float)$a->x <=> (float)$b->x));
        return $pins;
    }

    /**
     * Scenes that are ready to learn (a picture and at least one placed phrase), with their pins.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array list of [scene, pins, distractors]
     */
    public static function ready_scenes(stdClass $instance, \context $context): array {
        $scenes = self::get_scenes($instance->id);
        $phrases = self::get_phrases(array_keys($scenes));
        $out = [];
        foreach ($scenes as $scene) {
            $pins = self::order_pins($phrases[$scene->id]);
            if (!$pins || !self::get_scene_file($context, (int)$scene->id)) {
                continue;
            }
            $distractors = array_values(array_filter($phrases[$scene->id], fn($p) => !empty($p->distractor)));
            $out[] = [$scene, $pins, $distractors];
        }
        return $out;
    }

    /**
     * Colour of a phrase, falling back to the palette.
     *
     * @param stdClass $phrase
     * @param int $index
     * @return string
     */
    public static function phrase_color(stdClass $phrase, int $index): string {
        if (!empty($phrase->color) && preg_match('/^#[0-9a-fA-F]{6}$/', $phrase->color)) {
            return strtoupper($phrase->color);
        }
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * The stored picture of a scene.
     *
     * @param \context $context
     * @param int $sceneid
     * @return \stored_file|null
     */
    public static function get_scene_file(\context $context, int $sceneid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_ailanguageteacher',
            'sceneimage',
            $sceneid,
            'sortorder, id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Picture information for a scene.
     *
     * @param \context $context
     * @param int $sceneid
     * @return array [url|null, width, height]
     */
    public static function get_scene_image(\context $context, int $sceneid): array {
        $file = self::get_scene_file($context, $sceneid);
        if (!$file) {
            return [null, 16, 10];
        }
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_ailanguageteacher',
            'sceneimage',
            $sceneid,
            $file->get_filepath(),
            $file->get_filename()
        );
        $url->param('rev', $file->get_timemodified());
        $info = $file->get_imageinfo();
        $width = !empty($info['width']) ? (int)$info['width'] : 1600;
        $height = !empty($info['height']) ? (int)$info['height'] : 1000;
        return [$url->out(false), $width, $height];
    }

    /**
     * Checks that bytes really are a picture of an accepted type.
     *
     * @param string $head the first bytes of the file (at least 16)
     * @return string|null the extension (png, jpg, gif, webp) or null
     */
    public static function image_signature(string $head): ?string {
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    /**
     * Whether an image file on disk is a real, readable picture.
     *
     * @param string $path
     * @return bool
     */
    protected static function valid_image_path(string $path): bool {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 16);
        fclose($handle);
        if (self::image_signature($head) === null) {
            return false;
        }
        $info = getimagesize($path);
        return !empty($info[0]) && !empty($info[1]);
    }

    /**
     * Whether a stored file is a real picture.
     *
     * @param \stored_file $file
     * @return bool
     */
    protected static function valid_image_file(\stored_file $file): bool {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return false;
        }
        $head = (string)fread($handle, 16);
        fclose($handle);
        return self::image_signature($head) !== null && $file->is_valid_image();
    }

    /**
     * Makes a readable title from a file name.
     *
     * @param string $filename
     * @return string
     */
    public static function title_from_filename(string $filename): string {
        $title = preg_replace('/\.[^.]+$/', '', $filename);
        $title = trim(preg_replace('/[_\-]+/', ' ', $title));
        return \core_text::substr($title !== '' ? $title : $filename, 0, 255);
    }

    /**
     * Collects the pictures in a draft area, including pictures inside ZIP files.
     *
     * @param int $draftitemid
     * @return array list of [name, file|path]
     */
    protected static function draft_images(int $draftitemid): array {
        global $USER;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        $sources = [];
        foreach ($files as $file) {
            $filename = $file->get_filename();
            if (preg_match('/\.zip$/i', $filename)) {
                $packer = get_file_packer('application/zip');
                $list = $file->list_files($packer);
                if (!is_array($list)) {
                    throw new moodle_exception('zipinvalid', 'mod_ailanguageteacher');
                }
                $unpacked = 0;
                $images = 0;
                foreach ($list as $entry) {
                    $unpacked += (int)$entry->size;
                    if (!$entry->is_directory && preg_match('/\.(png|jpe?g|gif|webp)$/i', $entry->pathname)) {
                        $images++;
                    }
                }
                if ($unpacked > self::MAX_ZIP_BYTES || $images > self::MAX_UPLOAD_IMAGES) {
                    throw new moodle_exception('ziptoolarge', 'mod_ailanguageteacher', '', self::MAX_UPLOAD_IMAGES);
                }
                $tmpdir = make_request_directory();
                $file->extract_to_pathname($packer, $tmpdir);
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpdir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $path) {
                    $name = $path->getFilename();
                    if (
                        !$path->isLink() && $path->isFile() && strpos($name, '.') !== 0 &&
                        strpos($path->getPathname(), '__MACOSX') === false &&
                        preg_match('/\.(png|jpe?g|gif|webp)$/i', $name) && self::valid_image_path($path->getPathname())
                    ) {
                        $sources[] = ['name' => $name, 'path' => $path->getPathname()];
                    }
                }
            } else if (self::valid_image_file($file)) {
                $sources[] = ['name' => $filename, 'file' => $file];
            }
        }
        if (count($sources) > self::MAX_UPLOAD_IMAGES) {
            throw new moodle_exception('ziptoolarge', 'mod_ailanguageteacher', '', self::MAX_UPLOAD_IMAGES);
        }
        usort($sources, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $sources;
    }

    /**
     * Returns the id of a situation with this name, creating it when needed.
     *
     * @param int $aid
     * @param string $name
     * @return int
     */
    public static function situation_id(int $aid, string $name): int {
        global $DB;
        $name = \core_text::substr(trim(clean_param($name, PARAM_TEXT)), 0, 255);
        if ($name === '') {
            $name = get_string('situation_general', 'mod_ailanguageteacher');
        }
        foreach (self::get_situations($aid) as $situation) {
            if (\core_text::strtolower($situation->name) === \core_text::strtolower($name)) {
                return (int)$situation->id;
            }
        }
        $sortorder = (int)$DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {ailanguageteacher_situation} WHERE ailanguageteacherid = :aid',
            ['aid' => $aid]
        );
        return (int)$DB->insert_record('ailanguageteacher_situation', (object)[
            'ailanguageteacherid' => $aid,
            'name' => $name,
            'sortorder' => $sortorder + 1,
        ]);
    }

    /**
     * Adds a scene at the end of the activity (or at the end of its situation).
     *
     * @param stdClass $instance
     * @param int $situationid
     * @param string $title
     * @param string $context
     * @param string $imageprompt
     * @return int scene id
     */
    public static function add_scene(
        stdClass $instance,
        int $situationid,
        string $title,
        string $context = '',
        string $imageprompt = ''
    ): int {
        global $DB;
        $sortorder = (int)$DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {ailanguageteacher_scene} WHERE ailanguageteacherid = :aid',
            ['aid' => $instance->id]
        );
        return (int)$DB->insert_record('ailanguageteacher_scene', (object)[
            'ailanguageteacherid' => $instance->id,
            'situationid' => $situationid,
            'sortorder' => $sortorder + 1,
            'title' => \core_text::substr(
                trim($title) !== '' ? trim($title) : get_string('newscene', 'mod_ailanguageteacher'),
                0,
                255
            ),
            'context' => $context,
            'imageprompt' => $imageprompt,
            'timemodified' => time(),
        ]);
    }

    /**
     * File record for a scene picture.
     *
     * @param \context $context
     * @param int $sceneid
     * @param string $filename
     * @return array
     */
    protected static function image_record(\context $context, int $sceneid, string $filename): array {
        return [
            'contextid' => $context->id,
            'component' => 'mod_ailanguageteacher',
            'filearea' => 'sceneimage',
            'itemid' => $sceneid,
            'filepath' => '/',
            'filename' => clean_param($filename, PARAM_FILE) ?: 'scene.png',
        ];
    }

    /**
     * Replaces a scene picture with the first picture in a draft area.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param int $draftitemid
     * @return bool whether a picture was saved
     */
    public static function replace_scene_image(\context $context, stdClass $scene, int $draftitemid): bool {
        global $DB;
        $sources = self::draft_images($draftitemid);
        if (!$sources) {
            return false;
        }
        $source = reset($sources);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id);
        $record = self::image_record($context, (int)$scene->id, $source['name']);
        if (isset($source['file'])) {
            $fs->create_file_from_storedfile($record, $source['file']);
        } else {
            $fs->create_file_from_pathname($record, $source['path']);
        }
        $DB->set_field('ailanguageteacher_scene', 'timemodified', time(), ['id' => $scene->id]);
        return true;
    }

    /**
     * Saves picture bytes (from an AI provider) as the scene picture, after checking they are a real picture.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param string $bytes
     */
    public static function save_scene_image_bytes(\context $context, stdClass $scene, string $bytes): void {
        global $DB;
        $type = self::image_signature(substr($bytes, 0, 16));
        if ($type === null || strlen($bytes) > 20 * 1048576) {
            throw new moodle_exception('aibadimage', 'mod_ailanguageteacher');
        }
        $dir = make_request_directory();
        $path = $dir . '/scene.' . $type;
        file_put_contents($path, $bytes);
        if (!self::valid_image_path($path)) {
            throw new moodle_exception('aibadimage', 'mod_ailanguageteacher');
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id);
        $fs->create_file_from_pathname(self::image_record($context, (int)$scene->id, 'scene-' . time() . '.' . $type), $path);
        $DB->set_field('ailanguageteacher_scene', 'timemodified', time(), ['id' => $scene->id]);
    }

    /**
     * File manager options for scene pictures.
     *
     * @param int $maxfiles
     * @return array
     */
    public static function image_filemanager_options(int $maxfiles = -1): array {
        global $CFG;
        require_once($CFG->dirroot . '/repository/lib.php');
        return [
            'subdirs' => 0,
            'maxfiles' => $maxfiles,
            'accepted_types' => $maxfiles === 1 ? self::IMAGE_TYPES : array_merge(self::IMAGE_TYPES, ['.zip']),
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Deletes a scene, its phrases, picture and audio. Learner records for its phrases go too.
     *
     * @param \context $context
     * @param stdClass $scene
     */
    public static function delete_scene(\context $context, stdClass $scene): void {
        global $DB;
        $fs = get_file_storage();
        $phraseids = $DB->get_fieldset_select('ailanguageteacher_phrase', 'id', 'sceneid = :sid', ['sid' => $scene->id]);
        foreach ($phraseids as $phraseid) {
            $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'phraseaudio', $phraseid);
            $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'ttsaudio', $phraseid);
        }
        if ($phraseids) {
            [$insql, $params] = $DB->get_in_or_equal($phraseids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('ailanguageteacher_progress', "phraseid $insql", $params);
        }
        $DB->delete_records('ailanguageteacher_phrase', ['sceneid' => $scene->id]);
        $DB->delete_records('ailanguageteacher_scene', ['id' => $scene->id]);
        $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'sceneimage', $scene->id);
        self::normalise_sortorder((int)$scene->ailanguageteacherid);
        self::remove_empty_situations((int)$scene->ailanguageteacherid);
    }

    /**
     * Removes situations that no longer have scenes.
     *
     * @param int $aid
     */
    public static function remove_empty_situations(int $aid): void {
        global $DB;
        $sql = 'SELECT s.id
                  FROM {ailanguageteacher_situation} s
             LEFT JOIN {ailanguageteacher_scene} sc ON sc.situationid = s.id
                 WHERE s.ailanguageteacherid = :aid AND sc.id IS NULL';
        $ids = $DB->get_fieldset_sql($sql, ['aid' => $aid]);
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('ailanguageteacher_situation', "id $insql", $params);
        }
    }

    /**
     * Re-numbers scene sort order 1..n.
     *
     * @param int $aid
     */
    public static function normalise_sortorder(int $aid): void {
        global $DB;
        $i = 0;
        foreach (self::get_scenes($aid) as $scene) {
            $i++;
            if ((int)$scene->sortorder !== $i) {
                $DB->set_field('ailanguageteacher_scene', 'sortorder', $i, ['id' => $scene->id]);
            }
        }
    }

    /**
     * Moves a scene earlier or later.
     *
     * @param stdClass $scene
     * @param int $direction -1 earlier, 1 later
     */
    public static function move_scene(stdClass $scene, int $direction): void {
        global $DB;
        self::normalise_sortorder((int)$scene->ailanguageteacherid);
        $scenes = array_values(self::get_scenes((int)$scene->ailanguageteacherid));
        foreach ($scenes as $index => $s) {
            if ((int)$s->id === (int)$scene->id) {
                $target = $index + $direction;
                if (isset($scenes[$target])) {
                    $DB->set_field('ailanguageteacher_scene', 'sortorder', $scenes[$target]->sortorder, ['id' => $s->id]);
                    $DB->set_field('ailanguageteacher_scene', 'sortorder', $s->sortorder, ['id' => $scenes[$target]->id]);
                }
                return;
            }
        }
    }

    /**
     * Cleans one line of text.
     *
     * @param mixed $value
     * @param int $length
     * @return string
     */
    public static function clean_line($value, int $length = 255): string {
        $value = trim(preg_replace('/\s+/u', ' ', clean_param((string)$value, PARAM_TEXT)));
        return \core_text::substr($value, 0, $length);
    }

    /**
     * Cleans multi-line text.
     *
     * @param mixed $value
     * @param int $length
     * @return string
     */
    public static function clean_text($value, int $length = 4000): string {
        $lines = preg_split('/\R/u', clean_param((string)$value, PARAM_TEXT));
        $lines = array_map(fn($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), $lines);
        return \core_text::substr(trim(implode("\n", $lines)), 0, $length);
    }

    /**
     * Saves a scene and its phrases (scene editor).
     *
     * @param stdClass $scene
     * @param array $data title, context, imageprompt, situationid
     * @param array $phrases list of phrase arrays
     * @return int[] saved phrase ids in the order given
     */
    public static function save_scene(stdClass $scene, array $data, array $phrases): array {
        global $DB;
        $pins = count(array_filter($phrases, fn($p) => empty($p['distractor']) && trim((string)$p['text']) !== ''));
        $distractors = count(array_filter($phrases, fn($p) => !empty($p['distractor']) && trim((string)$p['text']) !== ''));
        if ($pins > self::MAX_PHRASES || $distractors > self::MAX_DISTRACTORS) {
            throw new moodle_exception(
                'toomanyphrases',
                'mod_ailanguageteacher',
                '',
                ['phrases' => self::MAX_PHRASES, 'distractors' => self::MAX_DISTRACTORS]
            );
        }
        $transaction = $DB->start_delegated_transaction();
        $title = self::clean_line($data['title'] ?? '');
        $scene->title = $title !== '' ? $title : $scene->title;
        $scene->context = self::clean_text($data['context'] ?? '', 1000);
        $scene->imageprompt = self::clean_text($data['imageprompt'] ?? '', 2000);
        if (
            !empty($data['situationid']) && $DB->record_exists('ailanguageteacher_situation', [
                'id' => $data['situationid'], 'ailanguageteacherid' => $scene->ailanguageteacherid])
        ) {
            $scene->situationid = (int)$data['situationid'];
        }
        $scene->timemodified = time();
        $DB->update_record('ailanguageteacher_scene', $scene);

        $existing = $DB->get_records('ailanguageteacher_phrase', ['sceneid' => $scene->id], '', 'id');
        $keep = [];
        $ids = [];
        $sortorder = 0;
        foreach ($phrases as $item) {
            $record = self::clean_phrase($item);
            if ($record->text === '') {
                continue;
            }
            $sortorder++;
            $record->sceneid = $scene->id;
            $record->sortorder = $sortorder;
            $record->timemodified = time();
            if (!empty($item['id']) && isset($existing[$item['id']])) {
                $record->id = (int)$item['id'];
                $DB->update_record('ailanguageteacher_phrase', $record);
            } else {
                $record->id = $DB->insert_record('ailanguageteacher_phrase', $record);
            }
            $keep[$record->id] = true;
            $ids[] = (int)$record->id;
        }
        $removed = array_diff(array_keys($existing), array_keys($keep));
        if ($removed) {
            [$insql, $params] = $DB->get_in_or_equal($removed, SQL_PARAMS_NAMED);
            $DB->delete_records_select('ailanguageteacher_phrase', "id $insql", $params);
            $DB->delete_records_select('ailanguageteacher_progress', "phraseid $insql", $params);
            $cm = get_coursemodule_from_instance('ailanguageteacher', $scene->ailanguageteacherid, 0, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            $fs = get_file_storage();
            foreach ($removed as $phraseid) {
                $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'phraseaudio', $phraseid);
                $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'ttsaudio', $phraseid);
            }
        }
        $transaction->allow_commit();
        return $ids;
    }

    /**
     * Cleans the fields of one phrase from the editor or an import.
     *
     * @param array $item
     * @return stdClass
     */
    public static function clean_phrase(array $item): stdClass {
        $alternatives = $item['alternatives'] ?? '';
        if (is_array($alternatives)) {
            $alternatives = implode("\n", $alternatives);
        }
        $alternatives = implode("\n", array_slice(array_values(array_unique(array_filter(array_map(
            fn($l) => self::clean_line($l),
            preg_split('/\R/u', (string)$alternatives)
        ), fn($l) => $l !== ''))), 0, 10));
        $placed = !empty($item['placed']) && empty($item['distractor']);
        return (object)[
            'text' => self::clean_line($item['text'] ?? ''),
            'romanisation' => self::clean_line($item['romanisation'] ?? ''),
            'translation' => self::clean_line($item['translation'] ?? ''),
            'usagenote' => self::clean_text($item['usagenote'] ?? '', 1000),
            'example' => self::clean_line($item['example'] ?? ''),
            'exampletrans' => self::clean_line($item['exampletrans'] ?? ''),
            'prompt' => self::clean_line($item['prompt'] ?? ''),
            'alternatives' => $alternatives,
            'anchor' => self::clean_line($item['anchor'] ?? ''),
            'voicegender' => in_array($item['voicegender'] ?? '', ['f', 'm'], true) ? $item['voicegender'] : null,
            'x' => $placed ? max(0, min(100, round((float)($item['x'] ?? 0), 4))) : 0,
            'y' => $placed ? max(0, min(100, round((float)($item['y'] ?? 0), 4))) : 0,
            'placed' => $placed ? 1 : 0,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($item['color'] ?? '')) ? strtoupper($item['color']) : '',
            'distractor' => empty($item['distractor']) ? 0 : 1,
        ];
    }

    /**
     * Data for the scene editor.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $scene
     * @return array
     */
    public static function editor_data(stdClass $instance, \context $context, stdClass $scene): array {
        [$url, $w, $h] = self::get_scene_image($context, (int)$scene->id);
        $phrases = self::get_phrases([$scene->id])[$scene->id];
        $out = [];
        foreach ($phrases as $i => $phrase) {
            $out[] = [
                'id' => (int)$phrase->id,
                'text' => $phrase->text,
                'romanisation' => (string)$phrase->romanisation,
                'translation' => (string)$phrase->translation,
                'usagenote' => (string)$phrase->usagenote,
                'example' => (string)$phrase->example,
                'exampletrans' => (string)$phrase->exampletrans,
                'prompt' => (string)$phrase->prompt,
                'alternatives' => (string)$phrase->alternatives,
                'anchor' => (string)$phrase->anchor,
                'voicegender' => (string)($phrase->voicegender ?? ''),
                'x' => (float)$phrase->x,
                'y' => (float)$phrase->y,
                'placed' => (int)$phrase->placed,
                'color' => self::phrase_color($phrase, $i),
                'distractor' => (int)$phrase->distractor,
                'recording' => self::recording_url($context, (int)$phrase->id),
            ];
        }
        $situations = [];
        foreach (self::get_situations($instance->id) as $situation) {
            $situations[] = ['id' => (int)$situation->id, 'name' => format_string(
                $situation->name,
                true,
                ['context' => $context]
            )];
        }
        return [
            'sceneid' => (int)$scene->id,
            'title' => $scene->title,
            'context' => (string)$scene->context,
            'imageprompt' => (string)$scene->imageprompt,
            'situationid' => (int)$scene->situationid,
            'situations' => $situations,
            'image' => $url,
            'width' => $w,
            'height' => $h,
            'phrases' => $out,
            'palette' => self::PALETTE,
        ];
    }

    /**
     * URL of the teacher's recording of a phrase, if there is one.
     *
     * @param \context $context
     * @param int $phraseid
     * @return string
     */
    public static function recording_url(\context $context, int $phraseid): string {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_ailanguageteacher',
            'phraseaudio',
            $phraseid,
            'id DESC',
            false
        );
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_ailanguageteacher',
            'phraseaudio',
            $phraseid,
            '/',
            $file->get_filename()
        )->out(false);
    }

    /**
     * Detects the container format of a recording from its first bytes.
     *
     * @param string $head
     * @return string|null file extension
     */
    public static function audio_signature(string $head): ?string {
        if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
            return 'webm';
        }
        if (strncmp($head, 'OggS', 4) === 0) {
            return 'ogg';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return 'm4a';
        }
        if (strncmp($head, 'ID3', 3) === 0 || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
            return 'mp3';
        }
        return null;
    }

    /**
     * Saves the teacher's recording of a phrase.
     *
     * @param \context $context
     * @param stdClass $phrase
     * @param string $bytes
     * @return string URL of the saved recording
     */
    public static function save_recording(\context $context, stdClass $phrase, string $bytes): string {
        $type = self::audio_signature(substr($bytes, 0, 16));
        if ($type === null || strlen($bytes) > self::MAX_RECORDING_BYTES || strlen($bytes) < 64) {
            throw new moodle_exception('badrecording', 'mod_ailanguageteacher');
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_ailanguageteacher', 'phraseaudio', $phrase->id);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_ailanguageteacher',
            'filearea' => 'phraseaudio',
            'itemid' => $phrase->id,
            'filepath' => '/',
            'filename' => 'recording-' . time() . '.' . $type,
        ], $bytes);
        return self::recording_url($context, (int)$phrase->id);
    }
}
