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

use moodle_url;
use stdClass;

/**
 * The teacher's set-up path: nine steps in a fixed order, with Back and Next only.
 *
 * 1 language, 2 situations, 3 learners, 4 level and size, 5 create the scenes, 6 pictures, 7 voices, 8 check the
 * scenes (place the phrases), 9 finish.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setuppath {
    /** @var string[] step names, in order (string keys setupstep_<name>). */
    public const STEPS = ['language', 'situations', 'learners', 'level', 'create', 'pictures', 'voices', 'check', 'finish'];

    /** @var int The create step. */
    public const CREATE = 5;

    /** @var int The pictures step. */
    public const PICTURES = 6;

    /** @var int The voices step. */
    public const VOICES = 7;

    /** @var int The check step. */
    public const CHECK = 8;

    /** @var int The finish step. */
    public const FINISH = 9;

    /**
     * The page for a step.
     *
     * @param int $cmid
     * @param int $step 1 to 9
     * @return moodle_url
     */
    public static function url(int $cmid, int $step): moodle_url {
        $builder = '/mod/ailanguageteacher/builder.php';
        $scenes = '/mod/ailanguageteacher/scenes.php';
        if ($step <= 4) {
            return new moodle_url($builder, ['id' => $cmid, 'start' => max(1, $step)]);
        }
        return match ($step) {
            self::CREATE => new moodle_url($builder, ['id' => $cmid, 'step' => 'build']),
            self::PICTURES => new moodle_url($scenes, ['id' => $cmid, 'step' => 'pictures']),
            self::VOICES => new moodle_url($scenes, ['id' => $cmid, 'step' => 'voices']),
            self::CHECK => new moodle_url($scenes, ['id' => $cmid, 'step' => 'check']),
            default => new moodle_url($builder, ['id' => $cmid, 'step' => 'finish']),
        };
    }

    /**
     * Template data for the step bar. The bar only shows progress: steps are reached with Back and Next.
     *
     * @param int $current 1 to 9
     * @return array
     */
    public static function bar(int $current): array {
        $steps = [];
        foreach (self::STEPS as $i => $name) {
            $n = $i + 1;
            $steps[] = [
                'number' => $n,
                'name' => get_string('setupstep_' . $name, 'mod_ailanguageteacher'),
                'done' => $n < $current,
                'current' => $n === $current,
                'dot' => $n <= 4 ? $n : 0,
            ];
        }
        return ['steps' => $steps, 'current' => $current, 'total' => count(self::STEPS)];
    }

    /**
     * Where the set-up path is up to.
     *
     * @param array $state from {@see self::state()}
     * @param bool $choicessaved whether the builder choices have been saved at least once
     * @return int step 1 to 9
     */
    public static function resume_step(array $state, bool $choicessaved): int {
        if (!$state['scenes']) {
            return $choicessaved ? self::CREATE : 1;
        }
        if ($state['nopicture']) {
            return self::PICTURES;
        }
        if ($state['novoice']) {
            return self::VOICES;
        }
        if ($state['unplaced']) {
            return self::CHECK;
        }
        return self::FINISH;
    }

    /**
     * Counts what is still missing.
     *
     * @param stdClass $instance
     * @param \context $context
     * @return array scenes, nopicture (scene ids), unplaced (scene ids with no phrase placed on the picture), novoice
     *     (phrase ids without a voice, only when LMS Labs voices exist for the locale), voices (whether they do),
     *     ready (number of scenes learners can open)
     */
    public static function state(stdClass $instance, \context $context): array {
        $scenes = manager::get_scenes((int)$instance->id);
        $phrases = manager::get_phrases(array_keys($scenes));
        $voices = audio::has_service_tts((string)$instance->targetlocale);
        $nopicture = [];
        $unplaced = [];
        $novoice = [];
        $ready = 0;
        foreach ($scenes as $scene) {
            $picture = manager::get_scene_file($context, (int)$scene->id) !== null;
            $placed = (bool)manager::order_pins($phrases[$scene->id]);
            if (!$picture) {
                $nopicture[] = (int)$scene->id;
            }
            if (!$placed) {
                $unplaced[] = (int)$scene->id;
            }
            if ($voices) {
                foreach ($phrases[$scene->id] as $phrase) {
                    if (
                        empty($phrase->distractor) && trim((string)$phrase->text) !== '' &&
                            audio::existing_url($instance, $context, $phrase, 'normal') === ''
                    ) {
                        $novoice[] = (int)$phrase->id;
                    }
                }
            }
            $ready += $picture && $placed ? 1 : 0;
        }
        return ['scenes' => count($scenes), 'nopicture' => $nopicture, 'unplaced' => $unplaced, 'novoice' => $novoice,
            'voices' => $voices, 'ready' => $ready];
    }
}
