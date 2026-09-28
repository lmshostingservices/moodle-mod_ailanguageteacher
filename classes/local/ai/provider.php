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

namespace mod_ailanguageteacher\local\ai;

/**
 * An AI service that drafts lessons and scene pictures for teachers.
 *
 * Learners never trigger AI generation: only teachers with mod/ailanguageteacher:useai, from Moodle PHP.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider {
    /**
     * Whether this provider can generate lessons and pictures on this site right now.
     *
     * @return bool
     */
    public function can_generate(): bool;

    /**
     * Drafts a lesson. Returns the decoded lesson JSON (see {@see \mod_ailanguageteacher\local\lesson}).
     *
     * @param string $prompt the full lesson instructions
     * @return array
     */
    public function generate_lesson(string $prompt): array;

    /**
     * Creates a scene picture.
     *
     * @param string $prompt picture description
     * @return string PNG, JPEG or WebP bytes
     */
    public function generate_image(string $prompt): string;

    /**
     * Remaining balance, for information only (it can change before any charge).
     *
     * @return array|null ['unlimited' => bool, 'credits' => int] or null when unknown
     */
    public function balance(): ?array;
}
