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
use stdClass;

/**
 * Lesson drafts: the AI prompt, reading the JSON an AI returns, and creating scenes from it.
 *
 * Draft format:
 * {"scenes": [{"situation", "title", "context", "imageprompt",
 *   "phrases": [{"text", "romanisation", "translation", "usagenote", "example", "exampletrans", "prompt",
 *                "alternatives": [], "anchor"}],
 *   "distractors": [{"text", "romanisation", "translation"}]}]}
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson {
    /** @var int Most scenes in one draft. */
    public const MAX_SCENES = 40;

    /**
     * The body of an LMS Labs lesson draft request, always in English.
     *
     * @param stdClass $instance
     * @return array {brief, locale, level, topic}
     */
    public static function draft_request(stdClass $instance): array {
        $level = in_array($instance->cefrlevel, ['A1', 'A2'], true) ? 'beginner' :
            (in_array($instance->cefrlevel, ['B1', 'B2'], true) ? 'intermediate' : 'advanced');
        // Always English, whatever the teacher's Moodle language, so the same choices always send the same request.
        $situations = self::chosen_situations($instance, 'en');
        $brief = 'Teach a short ' . languages::name($instance->targetlang, 'en') . ' lesson for ' .
            languages::name($instance->supportlang, 'en') . ' speakers. Situation: ' .
            ($situations[0] ?? 'everyday conversation') . '. CEFR ' . $instance->cefrlevel . '.';
        return ['brief' => $brief, 'locale' => (string)$instance->targetlocale, 'level' => $level,
            'topic' => \core_text::substr($situations[0] ?? 'Everyday conversation', 0, 200)];
    }

    /**
     * Maps a delivered LMS Labs draft to one editable scene with its phrases.
     *
     * @param array $draft {title, objective, explanation, vocabulary[{term, meaning}], practice[{prompt, answer}]}
     * @param stdClass $instance
     * @return array lesson draft (scenes)
     * @throws moodle_exception lessoninvalid when the draft cannot be used
     */
    public static function from_approved(array $draft, stdClass $instance): array {
        foreach (['title', 'objective', 'explanation', 'vocabulary', 'practice'] as $field) {
            if (!isset($draft[$field])) {
                throw new moodle_exception('lessoninvalid', 'mod_ailanguageteacher');
            }
        }
        if (!is_array($draft['vocabulary']) || !is_array($draft['practice'])) {
            throw new moodle_exception('lessoninvalid', 'mod_ailanguageteacher');
        }
        $text = fn($v) => is_scalar($v) ? (string)$v : '';
        $phrases = [];
        foreach ($draft['vocabulary'] as $pair) {
            if (is_array($pair) && $text($pair['term'] ?? '') !== '') {
                $phrases[] = ['text' => $text($pair['term']), 'translation' => $text($pair['meaning'] ?? ''),
                    'usagenote' => $text($draft['explanation']), 'prompt' => $text($draft['objective'])];
            }
        }
        foreach ($draft['practice'] as $pair) {
            if (is_array($pair) && $text($pair['answer'] ?? '') !== '') {
                $phrases[] = ['text' => $text($pair['answer']), 'prompt' => $text($pair['prompt'] ?? ''),
                    'usagenote' => $text($draft['explanation'])];
            }
        }
        if (!$phrases) {
            throw new moodle_exception('lessoninvalid', 'mod_ailanguageteacher');
        }
        return ['scenes' => [['situation' => self::chosen_situations($instance)[0] ?? '',
            'title' => $text($draft['title']), 'context' => $text($draft['objective']) . "\n" . $text($draft['explanation']),
            'imageprompt' => '', 'phrases' => $phrases, 'distractors' => []]]];
    }

    /** @var int Pictures (scenes) per situation the builder offers. */
    public const MAX_SCENES_PER_SITUATION = 6;

    /**
     * The situations chosen in the builder, as display names.
     *
     * @param stdClass $instance
     * @param string|null $lang language for built-in situation names (null: the current language)
     * @return string[]
     */
    public static function chosen_situations(stdClass $instance, ?string $lang = null): array {
        $data = json_decode((string)$instance->situations, true) ?: [];
        $names = [];
        foreach ($data['keys'] ?? [] as $key) {
            if (languages::is_situation((string)$key)) {
                $names[] = languages::situation_name((string)$key, $lang);
            }
        }
        foreach ($data['custom'] ?? [] as $custom) {
            $custom = manager::clean_line($custom, 120);
            if ($custom !== '') {
                $names[] = $custom;
            }
        }
        return $names;
    }

    /**
     * Builder options (scenes per situation, phrases per scene).
     *
     * @param stdClass $instance
     * @return array
     */
    public static function options(stdClass $instance): array {
        $data = json_decode((string)$instance->situations, true) ?: [];
        return [
            'scenes' => max(1, min(self::MAX_SCENES_PER_SITUATION, (int)($data['scenes'] ?? 3))),
            'phrases' => max(1, min(manager::MAX_PHRASES, (int)($data['phrases'] ?? manager::MAX_PHRASES))),
        ];
    }

    /**
     * The instructions given to an AI assistant to draft the lesson.
     *
     * @param stdClass $instance
     * @return string
     */
    public static function prompt(stdClass $instance): string {
        $options = self::options($instance);
        $situations = self::chosen_situations($instance);
        $a = (object)[
            'target' => languages::name($instance->targetlang) . ' (' . $instance->targetlocale . ')',
            'support' => languages::name($instance->supportlang),
            'level' => $instance->cefrlevel,
            'levelguide' => get_string('levelguide_' . strtolower($instance->cefrlevel), 'mod_ailanguageteacher'),
            'situations' => $situations ? '- ' . implode("\n- ", $situations) : get_string(
                'situation_general',
                'mod_ailanguageteacher'
            ),
            'scenes' => $options['scenes'],
            'phrases' => $options['phrases'],
            'romanisation' => languages::needs_romanisation($instance->targetlang)
                ? get_string('prompt_romanise', 'mod_ailanguageteacher') : get_string('prompt_noromanise', 'mod_ailanguageteacher'),
            'style' => get_string('imagestyle_' . $instance->imagestyle, 'mod_ailanguageteacher'),
            'country' => self::setting($instance),
            'maxphrases' => manager::MAX_PHRASES,
        ];
        return get_string('lessonprompt', 'mod_ailanguageteacher', $a);
    }

    /**
     * Where the pictures are set: the country of the variety taught (es-MX gives Mexico), in English for image tools.
     * A locale without a region gives "a place where <language> is spoken".
     *
     * @param stdClass $instance
     * @return string
     */
    public static function setting(stdClass $instance): string {
        $country = languages::country((string)$instance->targetlocale);
        if ($country !== '') {
            return $country;
        }
        return get_string_manager()->get_string(
            'prompt_wherespoken',
            'mod_ailanguageteacher',
            get_string_manager()->get_string('lang_' . $instance->targetlang, 'mod_ailanguageteacher', null, 'en'),
            'en'
        );
    }

    /**
     * The full picture prompt for a scene.
     *
     * @param stdClass $instance
     * @param stdClass $scene
     * @param stdClass[] $phrases
     * @return string
     */
    public static function image_prompt(stdClass $instance, stdClass $scene, array $phrases): string {
        $anchors = [];
        foreach ($phrases as $phrase) {
            if (empty($phrase->distractor) && trim((string)$phrase->anchor) !== '') {
                $anchors[] = '- ' . $phrase->anchor;
            }
        }
        $description = trim((string)$scene->imageprompt) !== '' ? $scene->imageprompt : $scene->title;
        return get_string('imageprompt_full', 'mod_ailanguageteacher', (object)[
            'description' => $description,
            'country' => self::setting($instance),
            'style' => get_string('imagestyle_' . $instance->imagestyle, 'mod_ailanguageteacher'),
            'anchors' => $anchors ? implode("\n", $anchors) : '-',
        ]);
    }

    /**
     * Reads a lesson draft from AI output, tolerating code fences and text around the JSON.
     *
     * @param string $raw
     * @return array cleaned draft
     */
    public static function parse(string $raw): array {
        $text = trim($raw);
        // Remove a Markdown code fence (three backticks, written as \x60) around the JSON.
        $text = preg_replace('/^\x60{3}[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\x60{3}\s*$/', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new moodle_exception('lessoninvalid', 'mod_ailanguageteacher');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new moodle_exception('lessoninvalid', 'mod_ailanguageteacher');
        }
        return self::clean($data);
    }

    /**
     * Validates and cleans a decoded draft.
     *
     * @param array $data
     * @return array
     */
    public static function clean(array $data): array {
        $scenes = [];
        foreach (array_slice((array)($data['scenes'] ?? []), 0, self::MAX_SCENES) as $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $phrases = [];
            foreach ((array)($scene['phrases'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $clean = manager::clean_phrase($p + ['distractor' => 0, 'placed' => 0]);
                if ($clean->text !== '') {
                    $phrases[] = (array)$clean;
                }
            }
            $distractors = [];
            foreach ((array)($scene['distractors'] ?? []) as $p) {
                if (is_string($p)) {
                    $p = ['text' => $p];
                }
                if (!is_array($p)) {
                    continue;
                }
                $clean = manager::clean_phrase(['text' => $p['text'] ?? '', 'romanisation' => $p['romanisation'] ?? '',
                    'translation' => $p['translation'] ?? '', 'distractor' => 1]);
                if ($clean->text !== '' && count($distractors) < manager::MAX_DISTRACTORS) {
                    $distractors[] = (array)$clean;
                }
            }
            $title = manager::clean_line($scene['title'] ?? '');
            if (!$phrases || $title === '') {
                continue;
            }
            // One picture holds at most MAX_PHRASES phrases; any more continue on further pictures of the same moment.
            $chunks = array_chunk($phrases, manager::MAX_PHRASES);
            foreach ($chunks as $i => $chunk) {
                $scenes[] = [
                    'situation' => manager::clean_line($scene['situation'] ?? ''),
                    'title' => $i === 0 ? $title : \core_text::substr($title . ' (' . ($i + 1) . ')', 0, 255),
                    'context' => manager::clean_text($scene['context'] ?? '', 1000),
                    'imageprompt' => manager::clean_text($scene['imageprompt'] ?? '', 2000),
                    'phrases' => $chunk,
                    'distractors' => $i === 0 ? $distractors : [],
                ];
            }
        }
        $scenes = array_slice($scenes, 0, self::MAX_SCENES);
        if (!$scenes) {
            throw new moodle_exception('lessonempty', 'mod_ailanguageteacher');
        }
        return ['scenes' => $scenes];
    }

    /**
     * Creates situations, scenes and phrases from a cleaned draft.
     *
     * @param stdClass $instance
     * @param array $draft
     * @return array counts: scenes, phrases
     */
    public static function import(stdClass $instance, array $draft): array {
        global $DB;
        $draft = self::clean($draft);
        $transaction = $DB->start_delegated_transaction();
        $scenecount = 0;
        $phrasecount = 0;
        foreach ($draft['scenes'] as $s) {
            $situationid = manager::situation_id((int)$instance->id, $s['situation']);
            $sceneid = manager::add_scene($instance, $situationid, $s['title'], $s['context'], $s['imageprompt']);
            $scenecount++;
            $order = 0;
            foreach (array_merge($s['phrases'], $s['distractors']) as $i => $p) {
                $order++;
                $record = (object)$p;
                $record->sceneid = $sceneid;
                $record->sortorder = $order;
                $record->color = manager::PALETTE[$i % count(manager::PALETTE)];
                $record->timemodified = time();
                $DB->insert_record('ailanguageteacher_phrase', $record);
                if (empty($p['distractor'])) {
                    $phrasecount++;
                }
            }
        }
        $transaction->allow_commit();
        return ['scenes' => $scenecount, 'phrases' => $phrasecount];
    }

    /**
     * Records an AI request and enforces the per-teacher hourly limit.
     *
     * @param int $aid
     * @param int $userid
     * @param string $action
     * @param string $status
     */
    public static function log_ai(int $aid, int $userid, string $action, string $status): void {
        global $DB;
        $DB->insert_record('ailanguageteacher_ailog', (object)['ailanguageteacherid' => $aid, 'userid' => $userid,
            'action' => $action, 'status' => $status, 'timecreated' => time()]);
    }

    /**
     * Throws when the teacher has made too many AI requests in the last hour.
     *
     * @param int $userid
     */
    public static function check_ai_rate(int $userid): void {
        global $DB;
        $limit = (int)(get_config('mod_ailanguageteacher', 'airate') ?: 30);
        $count = $DB->count_records_select(
            'ailanguageteacher_ailog',
            'userid = :userid AND timecreated > :since',
            ['userid' => $userid, 'since' => time() - HOURSECS]
        );
        if ($count >= $limit) {
            throw new moodle_exception('airatelimit', 'mod_ailanguageteacher');
        }
    }
}
