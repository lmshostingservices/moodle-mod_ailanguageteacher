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

use mod_ailanguageteacher\local\speech\factory;
use mod_ailanguageteacher\local\speech\matcher;
use mod_ailanguageteacher\local\speech\service;
use mod_ailanguageteacher\local\speech\wav;
use moodle_exception;
use stdClass;

/**
 * The learning journey: Study, Practice (match, listen, say it until mastered) and Test (match, listen, speak).
 *
 * Phrase states, stored per learner in ailanguageteacher_progress:
 * unseen → studied → matched → mastered, or needspractice when the learner moves on without mastering it.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learning {
    /**
     * The traffic-light colour of a progress bar: red below the activity's amber number, amber from it, green from its
     * green number.
     *
     * @param float $percent 0 to 100
     * @param stdClass|null $instance
     * @return string red, amber or green
     */
    public static function bar_tone(float $percent, ?stdClass $instance): string {
        $amber = max(1, min(99, (int)($instance->barsamber ?? 40)));
        $green = max($amber + 1, min(100, (int)($instance->barsgreen ?? 70)));
        return $percent >= $green ? 'green' : ($percent >= $amber ? 'amber' : 'red');
    }

    /** @var string Phrase not opened yet. */
    public const STATE_UNSEEN = 'unseen';

    /** @var string Phrase card opened in Study. */
    public const STATE_STUDIED = 'studied';

    /** @var string Phrase placed correctly in Practice, speaking not finished. */
    public const STATE_MATCHED = 'matched';

    /** @var string Phrase mastered: placed and said well enough, often enough. */
    public const STATE_MASTERED = 'mastered';

    /** @var string Learner moved on without mastering the phrase. */
    public const STATE_NEEDSPRACTICE = 'needspractice';

    /** @var int Seconds of grace allowed after a time limit expires (network latency). */
    public const TIME_GRACE = 30;

    /** @var int Speaking tries allowed per Test item (the best counts). */
    public const TEST_SPEAK_TRIES = 2;

    /** @var int Default speaking requests per learner per minute. */
    public const SPEECH_RATE = 20;

    /**
     * Formats one line of learner-facing content as plain text (escaped later by Mustache or textContent).
     *
     * @param string|null $text
     * @param \context $context
     * @return string
     */
    protected static function line(?string $text, \context $context): string {
        return format_string((string)$text, true, ['context' => $context, 'escape' => false]);
    }

    /**
     * Formats multi-line learner-facing content as plain text (line breaks kept; shown with white-space: pre-line).
     *
     * @param string|null $text
     * @param \context $context
     * @return string
     */
    protected static function para(?string $text, \context $context): string {
        if (trim((string)$text) === '') {
            return '';
        }
        $lines = preg_split('/\R/u', (string)$text);
        return implode("\n", array_map(fn($l) => format_string($l, true, ['context' => $context, 'escape' => false]), $lines));
    }

    /**
     * Phrase details shown on the phrase card (Study and Practice).
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $phrase
     * @return array
     */
    protected static function details(stdClass $instance, \context $context, stdClass $phrase): array {
        return [
            'phraseid' => (int)$phrase->id,
            'text' => self::line($phrase->text, $context),
            'romanisation' => $instance->showromanisation ? self::line($phrase->romanisation, $context) : '',
            'translation' => self::line($phrase->translation, $context),
            'usagenote' => self::para($phrase->usagenote, $context),
            'example' => self::line($phrase->example, $context),
            'exampletrans' => self::line($phrase->exampletrans, $context),
            'audio' => audio::existing_url($instance, $context, $phrase, 'normal'),
            'hasexample' => trim((string)$phrase->example) !== '',
        ];
    }

    /**
     * Common scene fields.
     *
     * @param \context $context
     * @param stdClass $scene
     * @param array $situations id => stdClass
     * @return array
     */
    protected static function scene_base(\context $context, stdClass $scene, array $situations): array {
        [$url, $w, $h] = manager::get_scene_image($context, (int)$scene->id);
        $situation = $situations[$scene->situationid] ?? null;
        return [
            'id' => (int)$scene->id,
            'title' => self::line($scene->title, $context),
            'situation' => $situation ? self::line($situation->name, $context) : '',
            'context' => self::para($scene->context, $context),
            'image' => $url,
            'width' => $w,
            'height' => $h,
        ];
    }

    /**
     * Each learner's progress on the phrases of an activity.
     *
     * @param int $aid
     * @param int $userid
     * @return array phraseid => stdClass
     */
    public static function get_progress(int $aid, int $userid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('ailanguageteacher_progress', ['ailanguageteacherid' => $aid, 'userid' => $userid]) as $row) {
            $out[(int)$row->phraseid] = $row;
        }
        return $out;
    }

    /**
     * Returns the learner's progress row for a phrase, creating it if needed.
     *
     * @param int $aid
     * @param int $userid
     * @param int $phraseid
     * @return stdClass
     */
    protected static function progress_row(int $aid, int $userid, int $phraseid): stdClass {
        global $DB;
        $row = $DB->get_record('ailanguageteacher_progress', ['userid' => $userid, 'phraseid' => $phraseid]);
        if ($row) {
            return $row;
        }
        $row = (object)[
            'ailanguageteacherid' => $aid,
            'userid' => $userid,
            'phraseid' => $phraseid,
            'state' => self::STATE_UNSEEN,
            'matchtries' => 0,
            'hints' => 0,
            'hintlevel' => 0,
            'speaktries' => 0,
            'successes' => 0,
            'bestscore' => null,
            'lastscore' => null,
            'timestudied' => 0,
            'timemastered' => 0,
            'timemodified' => time(),
        ];
        $row->id = $DB->insert_record('ailanguageteacher_progress', $row);
        return $row;
    }

    /**
     * Study data: every phrase shown in place.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array
     */
    public static function study_data(stdClass $instance, \context $context): array {
        $situations = manager::get_situations($instance->id);
        $out = [];
        foreach (manager::ready_scenes($instance, $context) as [$scene, $pins]) {
            $items = [];
            foreach ($pins as $n => $phrase) {
                $items[] = [
                    'token' => 'p' . $phrase->id,
                    'x' => (float)$phrase->x,
                    'y' => (float)$phrase->y,
                    'number' => $n + 1,
                    'color' => manager::phrase_color($phrase, $n),
                ] + self::details($instance, $context, $phrase);
            }
            $out[] = self::scene_base($context, $scene, $situations) + ['pins' => $items, 'chips' => []];
        }
        return ['kind' => 'study', 'attemptid' => 0, 'scenes' => $out];
    }

    /**
     * Whether the learner has finished Study.
     *
     * @param int $aid
     * @param int $userid
     * @return bool
     */
    public static function has_studied(int $aid, int $userid): bool {
        global $DB;
        return $DB->record_exists('ailanguageteacher_attempt', ['ailanguageteacherid' => $aid, 'userid' => $userid,
            'kind' => 'study', 'state' => 'finished']);
    }

    /**
     * Practice progress counts for the learner over the phrases that are currently ready.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param int $userid
     * @return array total, mastered, needspractice, done (mastered or moved on)
     */
    public static function practice_counts(stdClass $instance, \context $context, int $userid): array {
        $progress = self::get_progress((int)$instance->id, $userid);
        $total = 0;
        $mastered = 0;
        $needs = 0;
        foreach (manager::ready_scenes($instance, $context) as [$scene, $pins]) {
            foreach ($pins as $phrase) {
                $total++;
                $state = $progress[$phrase->id]->state ?? self::STATE_UNSEEN;
                if ($state === self::STATE_MASTERED) {
                    $mastered++;
                } else if ($state === self::STATE_NEEDSPRACTICE) {
                    $needs++;
                }
            }
        }
        return ['total' => $total, 'mastered' => $mastered, 'needspractice' => $needs, 'done' => $mastered + $needs];
    }

    /**
     * Whether a mode is locked for the learner because the activity unlocks modes in order.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param string $kind
     * @param int $userid
     * @return bool
     */
    public static function is_locked(stdClass $instance, \context $context, string $kind, int $userid): bool {
        if (!$instance->sequential || has_capability('mod/ailanguageteacher:manage', $context, $userid)) {
            return false;
        }
        if ($kind === 'practice') {
            return $instance->allowstudy && !self::has_studied((int)$instance->id, $userid);
        }
        if ($kind === 'test') {
            if ($instance->allowpractice) {
                $counts = self::practice_counts($instance, $context, $userid);
                return $counts['total'] === 0 || $counts['done'] < $counts['total'];
            }
            return $instance->allowstudy && !self::has_studied((int)$instance->id, $userid);
        }
        return false;
    }

    /**
     * Records the phrases a learner opened in Study, and finishes Study when every scene has been seen.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param int $userid
     * @param int[] $phraseids
     * @param bool $complete
     */
    public static function record_study(
        stdClass $instance,
        $cm,
        stdClass $course,
        \context $context,
        int $userid,
        array $phraseids,
        bool $complete
    ): void {
        global $DB, $CFG;
        $valid = [];
        foreach (manager::ready_scenes($instance, $context) as [$scene, $pins]) {
            foreach ($pins as $phrase) {
                $valid[(int)$phrase->id] = true;
            }
        }
        $now = time();
        foreach (array_unique(array_map('intval', $phraseids)) as $phraseid) {
            if (!isset($valid[$phraseid])) {
                continue;
            }
            $row = self::progress_row((int)$instance->id, $userid, $phraseid);
            if ($row->state === self::STATE_UNSEEN) {
                $row->state = self::STATE_STUDIED;
            }
            if (!$row->timestudied) {
                $row->timestudied = $now;
            }
            $row->timemodified = $now;
            $DB->update_record('ailanguageteacher_progress', $row);
        }
        if ($complete && !self::has_studied((int)$instance->id, $userid)) {
            $DB->insert_record('ailanguageteacher_attempt', (object)[
                'ailanguageteacherid' => $instance->id,
                'userid' => $userid,
                'attempt' => 1,
                'kind' => 'study',
                'state' => 'finished',
                'tokenmap' => '',
                'stages' => '',
                'timestart' => $now,
                'timefinish' => $now,
                'correct' => 0,
                'total' => 0,
                'grade' => null,
                'duration' => 0,
            ]);
            require_once($CFG->libdir . '/completionlib.php');
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
            }
        }
    }

    /**
     * Clears the learner's own practice progress (keeps Study and Test history).
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param int $userid
     */
    public static function reset_progress(stdClass $instance, $cm, stdClass $course, int $userid): void {
        global $DB, $CFG;
        $rows = $DB->get_records('ailanguageteacher_progress', ['ailanguageteacherid' => $instance->id, 'userid' => $userid]);
        foreach ($rows as $row) {
            $row->state = $row->timestudied ? self::STATE_STUDIED : self::STATE_UNSEEN;
            $row->matchtries = 0;
            $row->hints = 0;
            $row->hintlevel = 0;
            $row->speaktries = 0;
            $row->successes = 0;
            $row->bestscore = null;
            $row->lastscore = null;
            $row->timemastered = 0;
            $row->timemodified = time();
            $DB->update_record('ailanguageteacher_progress', $row);
        }
        require_once($CFG->libdir . '/completionlib.php');
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Summary of a learner's Test attempts.
     *
     * @param stdClass $instance
     * @param int $userid
     * @return array
     */
    public static function user_summary(stdClass $instance, int $userid): array {
        global $DB;
        $tests = $DB->get_records(
            'ailanguageteacher_attempt',
            ['ailanguageteacherid' => $instance->id, 'userid' => $userid, 'kind' => 'test'],
            'attempt ASC'
        );
        $finished = array_filter($tests, fn($a) => $a->state === 'finished');
        $best = null;
        foreach ($finished as $a) {
            $best = $best === null ? (float)$a->grade : max($best, (float)$a->grade);
        }
        $max = (int)$instance->maxattempts;
        return [
            'attemptsused' => count($tests),
            'maxattempts' => $max,
            'attemptsleft' => $max ? max(0, $max - count($tests)) : -1,
            'best' => $best === null ? null : round($best, 1),
            'final' => $finished ? round(manager::calculate_percent($finished, (int)$instance->grademethod), 1) : null,
        ];
    }

    /**
     * Which Test stages a new attempt includes.
     *
     * @param stdClass $instance
     * @param bool $canlisten the browser can play or speak audio
     * @param bool $canspeak the browser can record or recognise speech
     * @return string[]
     */
    public static function test_stages(stdClass $instance, bool $canlisten, bool $canspeak): array {
        $stages = ['match'];
        if ($instance->testlistening && ($canlisten || audio::has_service_tts($instance->targetlocale))) {
            $stages[] = 'listen';
        }
        $mode = audio::speaking_mode($instance->targetlocale);
        if ($instance->testspeaking && $canspeak && $mode !== 'self') {
            $stages[] = 'speak';
        }
        return $stages;
    }

    /**
     * Starts a Practice or Test attempt and returns the player data.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param string $kind practice|test
     * @param int $userid
     * @param bool $canlisten
     * @param bool $canspeak
     * @return array
     */
    public static function start_attempt(
        stdClass $instance,
        $cm,
        stdClass $course,
        \context $context,
        string $kind,
        int $userid,
        bool $canlisten = true,
        bool $canspeak = true
    ): array {
        global $DB;
        if (!in_array($kind, ['practice', 'test'], true)) {
            throw new moodle_exception('invalidmode', 'mod_ailanguageteacher');
        }
        if (($kind === 'practice' && !$instance->allowpractice) || ($kind === 'test' && !$instance->allowtest)) {
            throw new moodle_exception('modedisabled', 'mod_ailanguageteacher');
        }
        if (self::is_locked($instance, $context, $kind, $userid)) {
            throw new moodle_exception('modelocked', 'mod_ailanguageteacher');
        }

        // Close unfinished attempts in the same mode (they are graded as they stand).
        $open = $DB->get_records('ailanguageteacher_attempt', ['ailanguageteacherid' => $instance->id, 'userid' => $userid,
            'kind' => $kind, 'state' => 'inprogress']);
        foreach ($open as $attempt) {
            self::finish_attempt($instance, $cm, $course, $context, $attempt);
        }
        if ($kind === 'test' && $instance->maxattempts) {
            $used = $DB->count_records('ailanguageteacher_attempt', ['ailanguageteacherid' => $instance->id,
                'userid' => $userid, 'kind' => 'test']);
            if ($used >= $instance->maxattempts) {
                throw new moodle_exception('nomoreattempts', 'mod_ailanguageteacher');
            }
        }
        $ready = manager::ready_scenes($instance, $context);
        if (!$ready) {
            throw new moodle_exception('noscenes', 'mod_ailanguageteacher');
        }
        $stages = $kind === 'test' ? self::test_stages($instance, $canlisten, $canspeak) : ['match'];
        $situations = manager::get_situations($instance->id);
        $progress = $kind === 'practice' ? self::get_progress((int)$instance->id, $userid) : [];
        $map = ['pins' => [], 'chips' => [], 'listen' => [], 'speak' => [], 'scenes' => []];
        $payload = [];
        $total = 0;
        foreach ($ready as [$scene, $pins, $distractors]) {
            $all = array_merge($pins, $distractors);
            $chips = [];
            $chiptoken = [];
            $details = [];
            foreach ($all as $i => $phrase) {
                $token = 'c' . random_string(12);
                $map['chips'][$token] = (int)$phrase->id;
                $chiptoken[$phrase->id] = $token;
                $chip = [
                    'token' => $token,
                    'text' => self::line($phrase->text, $context),
                    'romanisation' => $instance->showromanisation ? self::line($phrase->romanisation, $context) : '',
                    'color' => manager::phrase_color($phrase, $i),
                ];
                if ($kind === 'practice') {
                    $chip['translation'] = self::line($phrase->translation, $context);
                    $row = $progress[$phrase->id] ?? null;
                    $details[] = ['token' => $token, 'state' => $row->state ?? self::STATE_UNSEEN,
                        'successes' => (int)($row->successes ?? 0), 'speaktries' => (int)($row->speaktries ?? 0),
                        'bestscore' => isset($row->bestscore) ? round((float)$row->bestscore) : -1,
                        'hints' => (int)($row->hints ?? 0)] + self::details($instance, $context, $phrase);
                }
                $chips[] = $chip;
            }
            if ($instance->shufflelabels) {
                shuffle($chips);
            }
            $pinlist = [];
            $answers = [];
            $listen = [];
            $speak = [];
            foreach ($pins as $n => $phrase) {
                $token = 'p' . random_string(12);
                $map['pins'][$token] = (int)$phrase->id;
                $pinlist[] = ['token' => $token, 'x' => (float)$phrase->x, 'y' => (float)$phrase->y, 'number' => $n + 1];
                $map['scenes'][$scene->id][] = (int)$phrase->id;
                if ($kind === 'practice') {
                    $answers[] = ['pin' => $token, 'chip' => $chiptoken[$phrase->id]];
                }
                $total++;
                if ($kind === 'test' && in_array('listen', $stages, true)) {
                    $ltoken = 'l' . random_string(12);
                    $map['listen'][$ltoken] = (int)$phrase->id;
                    // The phrase text is only sent when the browser has to speak it itself.
                    $hasaudio = audio::has_service_tts($instance->targetlocale)
                        || manager::recording_url($context, (int)$phrase->id) !== '';
                    $listen[] = [
                        'token' => $ltoken,
                        'text' => $hasaudio ? '' : self::line($phrase->text, $context),
                        'audio' => audio::existing_url($instance, $context, $phrase, 'normal'),
                    ];
                    $total++;
                }
                if ($kind === 'test' && in_array('speak', $stages, true)) {
                    $stoken = 's' . random_string(12);
                    $map['speak'][$stoken] = (int)$phrase->id;
                    $cue = trim((string)$phrase->prompt) !== '' ? $phrase->prompt : $phrase->translation;
                    $speak[] = ['token' => $stoken, 'pin' => $token, 'prompt' => self::line($cue, $context)];
                    $total++;
                }
            }
            if ($listen) {
                shuffle($listen);
            }
            $payload[] = self::scene_base($context, $scene, $situations) + [
                'pins' => $pinlist,
                'chips' => $chips,
                'answers' => $answers,
                'details' => $details,
                'listen' => $listen,
                'speak' => $speak,
            ];
        }
        $attemptno = 1 + (int)$DB->get_field_sql(
            'SELECT MAX(attempt) FROM {ailanguageteacher_attempt}
              WHERE ailanguageteacherid = :aid AND userid = :userid AND kind = :kind',
            ['aid' => $instance->id, 'userid' => $userid, 'kind' => $kind]
        );
        $attempt = (object)[
            'ailanguageteacherid' => $instance->id,
            'userid' => $userid,
            'attempt' => $attemptno,
            'kind' => $kind,
            'state' => 'inprogress',
            'tokenmap' => json_encode($map),
            'stages' => implode(',', $stages),
            'timestart' => time(),
            'timefinish' => 0,
            'correct' => 0,
            'total' => $total,
            'grade' => null,
            'duration' => 0,
        ];
        $attempt->id = $DB->insert_record('ailanguageteacher_attempt', $attempt);
        return [
            'kind' => $kind,
            'attemptid' => (int)$attempt->id,
            'attempt' => $attemptno,
            'timelimit' => $kind === 'test' ? (int)$instance->timelimit : 0,
            'stages' => $stages,
            'total' => $total,
            'scenes' => $payload,
        ];
    }

    /**
     * Loads an attempt and checks it belongs to the user.
     *
     * @param int $attemptid
     * @param int $userid
     * @return stdClass
     */
    public static function get_user_attempt(int $attemptid, int $userid): stdClass {
        global $DB;
        $attempt = $DB->get_record('ailanguageteacher_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        if ((int)$attempt->userid !== $userid) {
            throw new moodle_exception('notyourattempt', 'mod_ailanguageteacher');
        }
        return $attempt;
    }

    /**
     * Checks an attempt can still take answers.
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     */
    protected static function require_open(stdClass $instance, stdClass $attempt): void {
        if ($attempt->state !== 'inprogress') {
            throw new moodle_exception('attemptclosed', 'mod_ailanguageteacher');
        }
        if (
            $attempt->kind === 'test' && $instance->timelimit > 0
                && time() > $attempt->timestart + $instance->timelimit + self::TIME_GRACE
        ) {
            throw new moodle_exception('timeexpired', 'mod_ailanguageteacher');
        }
    }

    /**
     * Whether the learner has a Test open right now (Listen audio is then only given out through Test tokens).
     *
     * @param stdClass $instance
     * @param int $userid
     * @return bool
     */
    public static function test_in_progress(stdClass $instance, int $userid): bool {
        global $DB;
        $window = $instance->timelimit > 0 ? $instance->timelimit + self::TIME_GRACE : 3 * HOURSECS;
        return $DB->record_exists_select(
            'ailanguageteacher_attempt',
            'ailanguageteacherid = :aid AND userid = :userid AND kind = :kind AND state = :state AND timestart > :since',
            ['aid' => $instance->id, 'userid' => $userid, 'kind' => 'test', 'state' => 'inprogress',
                'since' => time() - $window]
        );
    }

    /**
     * Resolves a phrase reference sent by the player.
     *
     * "p<id>" is a phrase of this activity (Study and Practice). In a Test, only the attempt's own tokens work.
     *
     * @param stdClass $instance
     * @param string $ref
     * @param stdClass|null $attempt
     * @param string $purpose listen|speak
     * @return stdClass phrase
     */
    public static function resolve_phrase(stdClass $instance, string $ref, ?stdClass $attempt, string $purpose): stdClass {
        global $DB;
        $phraseid = 0;
        if ($attempt && $attempt->kind === 'test') {
            $map = json_decode((string)$attempt->tokenmap, true) ?: [];
            $phraseid = (int)($map[$purpose][$ref] ?? 0);
        } else if (preg_match('/^p(\d+)$/', $ref, $m)) {
            $phraseid = (int)$m[1];
        } else if ($attempt) {
            $map = json_decode((string)$attempt->tokenmap, true) ?: [];
            $phraseid = (int)($map['chips'][$ref] ?? 0);
        }
        if (!$phraseid) {
            throw new moodle_exception('invalidphrase', 'mod_ailanguageteacher');
        }
        $sql = 'SELECT p.*
                  FROM {ailanguageteacher_phrase} p
                  JOIN {ailanguageteacher_scene} s ON s.id = p.sceneid
                 WHERE p.id = :pid AND s.ailanguageteacherid = :aid';
        $phrase = $DB->get_record_sql($sql, ['pid' => $phraseid, 'aid' => $instance->id]);
        if (!$phrase) {
            throw new moodle_exception('invalidphrase', 'mod_ailanguageteacher');
        }
        return $phrase;
    }

    /**
     * Records one Practice step for a phrase.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param stdClass $attempt
     * @param string $chiptoken
     * @param string $action matched|hint|moveon
     * @param int $tries drops until correct (matched)
     * @param int $hintlevel
     * @return array state, successes
     */
    public static function practice_event(
        stdClass $instance,
        $cm,
        stdClass $course,
        \context $context,
        stdClass $attempt,
        string $chiptoken,
        string $action,
        int $tries,
        int $hintlevel
    ): array {
        global $DB, $CFG;
        if ($attempt->kind !== 'practice') {
            throw new moodle_exception('invalidmode', 'mod_ailanguageteacher');
        }
        self::require_open($instance, $attempt);
        $phrase = self::resolve_phrase($instance, $chiptoken, $attempt, 'chips');
        if ($phrase->distractor) {
            throw new moodle_exception('invalidphrase', 'mod_ailanguageteacher');
        }
        $row = self::progress_row((int)$instance->id, (int)$attempt->userid, (int)$phrase->id);
        $now = time();
        $changedcompletion = false;
        if ($action === 'hint') {
            $row->hints++;
            $row->hintlevel = max((int)$row->hintlevel, max(1, min(4, $hintlevel)));
        } else if ($action === 'matched') {
            $row->matchtries = max(1, $tries);
            if (in_array($row->state, [self::STATE_UNSEEN, self::STATE_STUDIED], true)) {
                $row->state = self::STATE_MATCHED;
            }
            if (!$instance->speaking && $row->state !== self::STATE_MASTERED) {
                $row->state = self::STATE_MASTERED;
                $row->timemastered = $now;
                $changedcompletion = true;
            }
            if (
                !$DB->record_exists('ailanguageteacher_response', ['attemptid' => $attempt->id, 'phraseid' => $phrase->id,
                    'stage' => 'match'])
            ) {
                $DB->insert_record('ailanguageteacher_response', (object)[
                    'attemptid' => $attempt->id,
                    'sceneid' => $phrase->sceneid,
                    'phraseid' => $phrase->id,
                    'stage' => 'match',
                    'answerid' => $phrase->id,
                    'score' => null,
                    'correct' => $tries <= 1 ? 1 : 0,
                    'tries' => max(1, $tries),
                    'hints' => max(0, $hintlevel),
                    'timecreated' => $now,
                ]);
            }
        } else if ($action === 'moveon') {
            if ($row->state !== self::STATE_MASTERED) {
                $row->state = self::STATE_NEEDSPRACTICE;
                $changedcompletion = true;
            }
        } else {
            throw new moodle_exception('invalidaction', 'mod_ailanguageteacher');
        }
        $row->timemodified = $now;
        $DB->update_record('ailanguageteacher_progress', $row);
        if ($changedcompletion) {
            require_once($CFG->libdir . '/completionlib.php');
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
        }
        return ['state' => $row->state, 'successes' => (int)$row->successes];
    }

    /**
     * Throws when the learner has sent too many speaking requests in the last minute.
     *
     * @param int $userid
     */
    protected static function check_speech_rate(int $userid): void {
        global $DB;
        $limit = (int)(get_config('mod_ailanguageteacher', 'speechrate') ?: self::SPEECH_RATE);
        $recent = $DB->count_records_select(
            'ailanguageteacher_speech',
            'userid = :userid AND timecreated > :since',
            ['userid' => $userid, 'since' => time() - 60]
        );
        if ($recent >= $limit) {
            throw new moodle_exception('speechratelimit', 'mod_ailanguageteacher');
        }
    }

    /**
     * Accepted spoken answers for a phrase.
     *
     * @param stdClass $phrase
     * @return string[]
     */
    public static function alternatives(stdClass $phrase): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$phrase->alternatives))));
    }

    /**
     * Checks a spoken answer (which words were recognised), and updates Practice mastery or the Test response.
     *
     * Silence, or speech that cannot be made out, gets no mark and does not count as a try.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param int $userid
     * @param string $kind study|practice|test
     * @param stdClass|null $attempt required for practice and test
     * @param string $ref phrase reference
     * @param string $wav recording for the site speech service
     * @param string[] $transcripts what the browser's own speech recognition heard
     * @param bool $selfcheck the learner compared their own recording (no automatic check available)
     * @return array
     */
    public static function assess(
        stdClass $instance,
        $cm,
        stdClass $course,
        \context $context,
        int $userid,
        string $kind,
        ?stdClass $attempt,
        string $ref,
        string $wav,
        array $transcripts,
        bool $selfcheck
    ): array {
        global $DB, $CFG;
        if ($kind !== 'study') {
            if (!$attempt || $attempt->kind !== $kind) {
                throw new moodle_exception('invalidmode', 'mod_ailanguageteacher');
            }
            self::require_open($instance, $attempt);
        }
        $phrase = self::resolve_phrase(
            $instance,
            $ref,
            $kind === 'study' ? null : $attempt,
            $kind === 'test' ? 'speak' : 'chips'
        );
        if ($phrase->distractor) {
            throw new moodle_exception('invalidphrase', 'mod_ailanguageteacher');
        }
        self::check_speech_rate($userid);
        $mode = audio::speaking_mode($instance->targetlocale);
        $chars = languages::no_spaces($instance->targetlang);
        $alternatives = self::alternatives($phrase);
        // Status of this try: ok (words were compared), nospeech or unintelligible (no score, the try does not count).
        $status = service::STATUS_OK;
        $score = null;
        $recognised = '';
        $check = ['found' => 0, 'total' => 0, 'units' => []];
        if ($mode === 'service' && $wav !== '') {
            wav::validate($wav);
            // One paid transcription at a time per learner and phrase: a repeated submission is refused, not resent.
            $lock = \core\lock\lock_config::get_lock_factory('mod_ailanguageteacher_stt')->get_lock($userid . '-' . $phrase->id, 0);
            if (!$lock) {
                throw new moodle_exception('speechinprogress', 'mod_ailanguageteacher');
            }
            try {
                $reply = factory::get()->transcribe(
                    $wav,
                    $instance->targetlocale,
                    array_merge([(string)$phrase->text], $alternatives),
                    \core\uuid::generate()
                );
            } finally {
                $lock->release();
            }
            $status = (string)($reply['status'] ?? service::STATUS_UNINTELLIGIBLE);
            $transcripts = array_slice(array_values(array_filter(array_map(
                fn($t) => trim((string)$t),
                (array)($reply['alternatives'] ?? [])
            ), 'strlen')), 0, 5);
            if ($status === service::STATUS_OK && !$transcripts) {
                $status = service::STATUS_UNINTELLIGIBLE;
            }
            $provider = 'service';
        } else if ($mode !== 'service' && ($transcripts || !$selfcheck)) {
            if ($mode === 'self' && !$transcripts) {
                throw new moodle_exception('nospeech', 'mod_ailanguageteacher');
            }
            $transcripts = array_values(array_filter(array_map('trim', $transcripts), 'strlen'));
            $status = $transcripts ? service::STATUS_OK : service::STATUS_NOSPEECH;
            $provider = 'browser';
        } else if ($selfcheck && $kind !== 'test') {
            $provider = 'self';
        } else {
            throw new moodle_exception('nospeech', 'mod_ailanguageteacher');
        }
        if ($provider !== 'self' && $status === service::STATUS_OK) {
            [$score, $heard, $target] = matcher::best($transcripts, (string)$phrase->text, $alternatives, $chars);
            $recognised = \core_text::substr(clean_param($heard, PARAM_TEXT), 0, 255);
            $check = matcher::recognised($heard, $target, $chars);
        }
        // Silence or unclear speech gets no score and does not count as a try.
        $counted = $provider === 'self' || $status === service::STATUS_OK;
        $pass = (int)$instance->passscore;
        $passed = $provider === 'self' ? true : ($score !== null && $score >= $pass);
        $now = time();
        if ($counted) {
            $DB->insert_record('ailanguageteacher_speech', (object)[
                'ailanguageteacherid' => $instance->id,
                'userid' => $userid,
                'phraseid' => $phrase->id,
                'attemptid' => $attempt->id ?? 0,
                'kind' => $kind,
                'provider' => $provider,
                'score' => $score,
                'recognised' => $recognised,
                'passed' => $passed ? 1 : 0,
                'timecreated' => $now,
            ]);
        }

        $out = [
            'provider' => $provider,
            'status' => $provider === 'self' ? 'ok' : $status,
            'counted' => $counted ? 1 : 0,
            'score' => $score === null ? -1 : (int)round((float)$score),
            'recognised' => $recognised,
            'found' => (int)$check['found'],
            'total' => (int)$check['total'],
            'units' => $check['units'],
            'unit' => $chars ? 'char' : 'word',
            'passed' => $passed ? 1 : 0,
            'passscore' => $pass,
            'successes' => 0,
            'required' => (int)$instance->repetitions,
            'mastered' => 0,
            'canmoveon' => 0,
            'triesleft' => 0,
        ];

        if (!$counted) {
            // Nothing is recorded: progress, mastery and Test tries are unchanged.
            if ($kind === 'practice') {
                $row = self::progress_row((int)$instance->id, $userid, (int)$phrase->id);
                $out['successes'] = (int)$row->successes;
                $out['mastered'] = $row->state === self::STATE_MASTERED ? 1 : 0;
                $out['canmoveon'] = $row->speaktries >= $instance->maxspeaktries ? 1 : 0;
            } else if ($kind === 'test') {
                $response = $DB->get_record('ailanguageteacher_response', ['attemptid' => $attempt->id,
                    'phraseid' => $phrase->id, 'stage' => 'speak']);
                $out['triesleft'] = max(0, self::TEST_SPEAK_TRIES - (int)($response->tries ?? 0));
                $out['units'] = [];
                $out['found'] = 0;
                $out['total'] = 0;
            }
            return $out;
        }
        if ($kind === 'practice') {
            $row = self::progress_row((int)$instance->id, $userid, (int)$phrase->id);
            $row->speaktries++;
            if ($score !== null) {
                $row->lastscore = $score;
                $row->bestscore = $row->bestscore === null ? $score : max((float)$row->bestscore, $score);
            }
            if ($passed) {
                $row->successes++;
            } else if ($instance->consecutive) {
                $row->successes = 0;
            }
            $newlymastered = false;
            if ($row->state !== self::STATE_MASTERED && $row->successes >= $instance->repetitions) {
                $row->state = self::STATE_MASTERED;
                $row->timemastered = $now;
                $newlymastered = true;
            }
            $row->timemodified = $now;
            $DB->update_record('ailanguageteacher_progress', $row);
            $out['successes'] = (int)$row->successes;
            $out['mastered'] = $row->state === self::STATE_MASTERED ? 1 : 0;
            $out['canmoveon'] = $row->speaktries >= $instance->maxspeaktries ? 1 : 0;
            if ($newlymastered) {
                require_once($CFG->libdir . '/completionlib.php');
                $completion = new \completion_info($course);
                if ($completion->is_enabled($cm)) {
                    $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
                }
            }
        } else if ($kind === 'test') {
            $response = $DB->get_record('ailanguageteacher_response', ['attemptid' => $attempt->id,
                'phraseid' => $phrase->id, 'stage' => 'speak']);
            if ($response && $response->tries >= self::TEST_SPEAK_TRIES) {
                throw new moodle_exception('notriesleft', 'mod_ailanguageteacher');
            }
            $score = (float)($score ?? 0);
            if ($response) {
                $response->tries++;
                $response->score = max((float)$response->score, $score);
                $response->correct = $response->score >= $pass ? 1 : 0;
                $DB->update_record('ailanguageteacher_response', $response);
            } else {
                $response = (object)[
                    'attemptid' => $attempt->id,
                    'sceneid' => $phrase->sceneid,
                    'phraseid' => $phrase->id,
                    'stage' => 'speak',
                    'answerid' => 0,
                    'score' => $score,
                    'correct' => $score >= $pass ? 1 : 0,
                    'tries' => 1,
                    'hints' => 0,
                    'timecreated' => $now,
                ];
                $response->id = $DB->insert_record('ailanguageteacher_response', $response);
            }
            $out['triesleft'] = max(0, self::TEST_SPEAK_TRIES - (int)$response->tries);
            // No marking details are revealed during a Test.
            $out['units'] = [];
            $out['found'] = 0;
            $out['total'] = 0;
            $out['score'] = -1;
            $out['recognised'] = '';
            $out['passed'] = 0;
        }
        return $out;
    }

    /**
     * Marks one Test stage of a scene: match (pin → chip) or listen (audio item → pin).
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @param int $sceneid
     * @param string $stage
     * @param array $answers list of [item, answer]
     * @return int answers recorded
     */
    public static function submit_scene(
        stdClass $instance,
        stdClass $attempt,
        int $sceneid,
        string $stage,
        array $answers
    ): int {
        global $DB;
        if ($attempt->kind !== 'test') {
            throw new moodle_exception('invalidmode', 'mod_ailanguageteacher');
        }
        self::require_open($instance, $attempt);
        $stages = explode(',', (string)$attempt->stages);
        if (!in_array($stage, ['match', 'listen'], true) || !in_array($stage, $stages, true)) {
            throw new moodle_exception('invalidstage', 'mod_ailanguageteacher');
        }
        $map = json_decode((string)$attempt->tokenmap, true) ?: [];
        $scenephrases = array_map('intval', $map['scenes'][$sceneid] ?? []);
        if (!$scenephrases) {
            throw new moodle_exception('invalidscene', 'mod_ailanguageteacher');
        }
        if (
            $DB->record_exists('ailanguageteacher_response', ['attemptid' => $attempt->id, 'sceneid' => $sceneid,
                'stage' => $stage])
        ) {
            throw new moodle_exception('scenealreadysubmitted', 'mod_ailanguageteacher');
        }
        [$insql, $params] = $DB->get_in_or_equal($scenephrases, SQL_PARAMS_NAMED);
        $phrases = $DB->get_records_select('ailanguageteacher_phrase', "id $insql", $params);
        $given = [];
        foreach ($answers as $a) {
            $given[(string)($a['item'] ?? '')] = (string)($a['answer'] ?? '');
        }
        $now = time();
        $count = 0;
        $same = function (int $a, int $b) use ($DB): bool {
            if ($a === $b) {
                return true;
            }
            $texts = $DB->get_records_list('ailanguageteacher_phrase', 'id', [$a, $b], '', 'id, text');
            return count($texts) === 2 && matcher::normalise($texts[$a]->text) === matcher::normalise($texts[$b]->text);
        };
        if ($stage === 'match') {
            foreach ($map['pins'] as $pintoken => $phraseid) {
                if (!in_array((int)$phraseid, $scenephrases, true) || !isset($phrases[$phraseid])) {
                    continue;
                }
                $chosen = (int)($map['chips'][$given[$pintoken] ?? ''] ?? 0);
                // A phrase from another scene counts as blank.
                $chosenscene = $chosen ? (int)$DB->get_field('ailanguageteacher_phrase', 'sceneid', ['id' => $chosen]) : 0;
                if ($chosenscene !== $sceneid) {
                    $chosen = 0;
                }
                $correct = $chosen && $same($chosen, (int)$phraseid);
                $DB->insert_record('ailanguageteacher_response', (object)[
                    'attemptid' => $attempt->id, 'sceneid' => $sceneid, 'phraseid' => $phraseid, 'stage' => 'match',
                    'answerid' => $chosen, 'score' => null, 'correct' => $correct ? 1 : 0, 'tries' => 1, 'hints' => 0,
                    'timecreated' => $now,
                ]);
                $count++;
            }
        } else {
            foreach ($map['listen'] as $ltoken => $phraseid) {
                if (!in_array((int)$phraseid, $scenephrases, true) || !isset($phrases[$phraseid])) {
                    continue;
                }
                $pinphrase = (int)($map['pins'][$given[$ltoken] ?? ''] ?? 0);
                if ($pinphrase && !in_array($pinphrase, $scenephrases, true)) {
                    $pinphrase = 0;
                }
                $correct = $pinphrase && $same($pinphrase, (int)$phraseid);
                $DB->insert_record('ailanguageteacher_response', (object)[
                    'attemptid' => $attempt->id, 'sceneid' => $sceneid, 'phraseid' => $phraseid, 'stage' => 'listen',
                    'answerid' => $pinphrase, 'score' => null, 'correct' => $correct ? 1 : 0, 'tries' => 1, 'hints' => 0,
                    'timecreated' => $now,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Credit for one Test speaking answer: full at the pass score, half from 60% of it.
     *
     * @param float $score
     * @param int $pass
     * @return float
     */
    public static function speak_credit(float $score, int $pass): float {
        if ($score >= $pass) {
            return 1.0;
        }
        return $score >= $pass * 0.6 ? 0.5 : 0.0;
    }

    /**
     * Finishes an attempt, grades it and updates gradebook and completion.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param \context $context
     * @param stdClass $attempt
     * @return array summary
     */
    public static function finish_attempt(
        stdClass $instance,
        $cm,
        stdClass $course,
        \context $context,
        stdClass $attempt
    ): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/mod/ailanguageteacher/lib.php');
        if ($attempt->state !== 'finished') {
            $now = time();
            $duration = $now - $attempt->timestart;
            if ($attempt->kind === 'test' && $instance->timelimit > 0) {
                $duration = min($duration, (int)$instance->timelimit);
            }
            if ($attempt->kind === 'test') {
                $correct = 0.0;
                $rs = $DB->get_recordset(
                    'ailanguageteacher_response',
                    ['attemptid' => $attempt->id],
                    '',
                    'id, stage, correct, score'
                );
                foreach ($rs as $r) {
                    $correct += $r->stage === 'speak' ? self::speak_credit((float)$r->score, (int)$instance->passscore)
                        : (int)$r->correct;
                }
                $rs->close();
                $total = max(1, (int)$attempt->total);
            } else {
                $counts = self::practice_counts($instance, $context, (int)$attempt->userid);
                $correct = $counts['mastered'];
                $total = max(1, $counts['total']);
                $attempt->total = $counts['total'];
            }
            $attempt->state = 'finished';
            $attempt->correct = round($correct, 2);
            $attempt->grade = round(min(100, $correct / $total * 100), 5);
            $attempt->timefinish = $now;
            $attempt->duration = $duration;
            $DB->update_record('ailanguageteacher_attempt', $attempt);
            if ($attempt->kind === 'test') {
                ailanguageteacher_update_grades($instance, $attempt->userid);
            }
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
            }
            $event = \mod_ailanguageteacher\event\attempt_finished::create([
                'objectid' => $attempt->id,
                'context' => $context,
                'relateduserid' => $attempt->userid,
                'other' => ['kind' => $attempt->kind, 'grade' => $attempt->grade],
            ]);
            $event->add_record_snapshot('ailanguageteacher_attempt', $attempt);
            $event->trigger();
        }
        $summary = self::user_summary($instance, (int)$attempt->userid);
        $out = [
            'attemptid' => (int)$attempt->id,
            'kind' => $attempt->kind,
            'correct' => round((float)$attempt->correct, 1),
            'total' => (int)$attempt->total,
            'percent' => round((float)$attempt->grade, 1),
            'duration' => (int)$attempt->duration,
            'attemptsleft' => $summary['attemptsleft'],
            'best' => $summary['best'] ?? 0,
            'leaderboard' => [],
            'review' => [],
            'practice' => ['mastered' => 0, 'needspractice' => 0, 'total' => 0, 'avgscore' => -1, 'hints' => 0],
        ];
        if ($attempt->kind === 'test') {
            $out['review'] = self::review($instance, $context, $attempt);
            if ($instance->leaderboard) {
                $out['leaderboard'] = self::leaderboard($instance, $cm, (int)$attempt->userid);
            }
        } else {
            $counts = self::practice_counts($instance, $context, (int)$attempt->userid);
            $avg = $DB->get_field_sql(
                'SELECT AVG(bestscore) FROM {ailanguageteacher_progress}
                  WHERE ailanguageteacherid = :aid AND userid = :userid AND bestscore IS NOT NULL',
                ['aid' => $instance->id, 'userid' => $attempt->userid]
            );
            $hints = (int)$DB->get_field_sql(
                'SELECT SUM(hints) FROM {ailanguageteacher_progress} WHERE ailanguageteacherid = :aid AND userid = :userid',
                ['aid' => $instance->id, 'userid' => $attempt->userid]
            );
            $out['practice'] = ['mastered' => $counts['mastered'], 'needspractice' => $counts['needspractice'],
                'total' => $counts['total'], 'avgscore' => $avg === null || $avg === false ? -1 : (int)round((float)$avg),
                'hints' => $hints];
        }
        return $out;
    }

    /**
     * Answer review for a finished Test: each phrase with its match, listen and speak results.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $attempt
     * @return array
     */
    public static function review(stdClass $instance, \context $context, stdClass $attempt): array {
        global $DB;
        $map = json_decode((string)$attempt->tokenmap, true) ?: [];
        $stages = explode(',', (string)$attempt->stages);
        $responses = [];
        $rs = $DB->get_recordset('ailanguageteacher_response', ['attemptid' => $attempt->id]);
        foreach ($rs as $r) {
            $responses[$r->phraseid][$r->stage] = $r;
        }
        $rs->close();
        $sceneids = array_map('intval', array_keys($map['scenes'] ?? []));
        if (!$sceneids) {
            return [];
        }
        $scenes = $DB->get_records_list('ailanguageteacher_scene', 'id', $sceneids);
        $allids = [];
        foreach ($map['scenes'] as $ids) {
            $allids = array_merge($allids, array_map('intval', $ids));
        }
        $phrases = $DB->get_records_list('ailanguageteacher_phrase', 'id', array_unique(array_merge(
            $allids,
            array_map(fn($r) => (int)($r['match']->answerid ?? 0), $responses)
        )));
        $out = [];
        foreach ($map['scenes'] as $sceneid => $ids) {
            if (!isset($scenes[$sceneid])) {
                continue;
            }
            $rows = [];
            foreach ($ids as $n => $phraseid) {
                if (!isset($phrases[$phraseid])) {
                    continue;
                }
                $p = $phrases[$phraseid];
                $r = $responses[$phraseid] ?? [];
                $chosen = isset($r['match']) && $r['match']->answerid && isset($phrases[$r['match']->answerid])
                    ? self::line($phrases[$r['match']->answerid]->text, $context) : '';
                $rows[] = [
                    'number' => $n + 1,
                    'text' => self::line($p->text, $context),
                    'translation' => self::line($p->translation, $context),
                    'match' => !empty($r['match']->correct) ? 1 : 0,
                    'chosen' => $chosen,
                    'haslisten' => in_array('listen', $stages, true) ? 1 : 0,
                    'listen' => !empty($r['listen']->correct) ? 1 : 0,
                    'hasspeak' => in_array('speak', $stages, true) ? 1 : 0,
                    'speak' => isset($r['speak']) ? (int)round((float)$r['speak']->score) : -1,
                    'speakpass' => !empty($r['speak']->correct) ? 1 : 0,
                ];
            }
            [$image] = manager::get_scene_image($context, (int)$sceneid);
            $out[] = ['title' => self::line($scenes[$sceneid]->title, $context), 'rows' => $rows, 'image' => (string)$image,
                'correct' => count(array_filter($rows, fn($row) => $row['match'])), 'total' => count($rows)];
        }
        return $out;
    }

    /**
     * Top scores (best Test attempt per learner), limited to the learner's groups in separate-groups mode.
     *
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param int $currentuserid
     * @param int $limit
     * @return array
     */
    public static function leaderboard(stdClass $instance, $cm, int $currentuserid, int $limit = 10): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $params = ['aid' => $instance->id, 'kind' => 'test', 'state' => 'finished'];
        $groupjoin = '';
        $context = \context_module::instance($cm->id);
        if (
            groups_get_activity_groupmode($cm) == SEPARATEGROUPS && !has_capability(
                'moodle/site:accessallgroups',
                $context,
                $currentuserid
            )
        ) {
            $groups = array_keys(groups_get_all_groups($cm->course, $currentuserid, $cm->groupingid));
            if (!$groups) {
                return [];
            }
            [$gsql, $gparams] = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grp');
            $groupjoin = "JOIN (SELECT DISTINCT gm.userid FROM {groups_members} gm WHERE gm.groupid $gsql) g ON g.userid = u.id";
            $params += $gparams;
        }
        $sql = "SELECT a.id, a.userid, a.grade, a.duration, $userfields
                  FROM {ailanguageteacher_attempt} a
                  JOIN {user} u ON u.id = a.userid
                       $groupjoin
                 WHERE a.ailanguageteacherid = :aid AND a.kind = :kind AND a.state = :state AND u.deleted = 0
              ORDER BY a.grade DESC, a.duration ASC, a.timefinish ASC, a.id ASC";
        $seen = [];
        $out = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        try {
            foreach ($rs as $row) {
                if (isset($seen[$row->userid])) {
                    continue;
                }
                $seen[$row->userid] = true;
                $initial = \core_text::substr((string)$row->lastname, 0, 1);
                $out[] = [
                    'rank' => count($out) + 1,
                    'name' => trim($row->firstname . ' ' . ($initial !== '' ? $initial . '.' : '')),
                    'percent' => round((float)$row->grade, 1),
                    'duration' => (int)$row->duration,
                    'me' => (int)$row->userid === $currentuserid ? 1 : 0,
                ];
                if (count($out) >= $limit) {
                    break;
                }
            }
        } finally {
            $rs->close();
        }
        return $out;
    }

    /**
     * Deletes every learner's records for an activity.
     *
     * @param int $aid
     */
    public static function delete_all_user_data(int $aid): void {
        global $DB;
        $attemptids = $DB->get_fieldset_select(
            'ailanguageteacher_attempt',
            'id',
            'ailanguageteacherid = :aid',
            ['aid' => $aid]
        );
        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('ailanguageteacher_response', "attemptid $insql", $params);
        }
        $DB->delete_records('ailanguageteacher_attempt', ['ailanguageteacherid' => $aid]);
        $DB->delete_records('ailanguageteacher_progress', ['ailanguageteacherid' => $aid]);
        $DB->delete_records('ailanguageteacher_speech', ['ailanguageteacherid' => $aid]);
    }

    /**
     * Deletes one learner's records for an activity.
     *
     * @param int $aid
     * @param int $userid
     */
    public static function delete_user_data_for_user(int $aid, int $userid): void {
        global $DB;
        $attemptids = $DB->get_fieldset_select(
            'ailanguageteacher_attempt',
            'id',
            'ailanguageteacherid = :aid AND userid = :userid',
            ['aid' => $aid, 'userid' => $userid]
        );
        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('ailanguageteacher_response', "attemptid $insql", $params);
        }
        $DB->delete_records('ailanguageteacher_attempt', ['ailanguageteacherid' => $aid, 'userid' => $userid]);
        $DB->delete_records('ailanguageteacher_progress', ['ailanguageteacherid' => $aid, 'userid' => $userid]);
        $DB->delete_records('ailanguageteacher_speech', ['ailanguageteacherid' => $aid, 'userid' => $userid]);
        $DB->delete_records('ailanguageteacher_ailog', ['ailanguageteacherid' => $aid, 'userid' => $userid]);
    }
}
