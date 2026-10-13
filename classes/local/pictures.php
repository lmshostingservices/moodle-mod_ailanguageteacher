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

use stdClass;

/**
 * LMS Labs AI pictures for scenes (owner-approved 13 Oct 2026: 2 credits per picture).
 *
 * Contract (LMS Labs, 13 Oct 2026): the free GET images/capabilities publishes {"tariff": {"image": 2}}; POST images
 * takes exactly {prompt, style, maxCredits: 2} and answers a 1600 × 1000 PNG with X-Credits-Charged. A picture is
 * asked for only while the published price is exactly the approved one. Every request is stored with its key and
 * exact body before it is sent, is never retried automatically, and "Check again" sends the same key and body.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pictures {
    /** @var string Picture route. */
    public const ROUTE = '/api/moodle/ai-language-teacher/images';

    /** @var string Free price route. */
    public const CAPABILITIES_ROUTE = '/api/moodle/ai-language-teacher/images/capabilities';

    /** @var int Credits per picture (owner-approved 13 Oct 2026). LMS Labs must publish the same price. */
    public const CREDITS = 2;

    /** @var int Longest prompt LMS Labs accepts, in code points. */
    public const MAX_PROMPT = 2000;

    /** @var int Largest picture accepted (LMS Labs: at most 5 MiB). */
    public const MAX_BYTES = 5242880;

    /** @var string[] Styles LMS Labs accepts. */
    public const STYLES = ['illustration', 'photo'];

    /** @var int Seconds the published price is kept before it is read again (teachers' pages only). */
    public const PRICE_TTL = 3600;

    /**
     * Whether an administrator has switched on LMS Labs AI pictures (off until LMS Labs publishes them).
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)get_config('mod_ailanguageteacher', 'aiimages') && credentials::find() !== null;
    }

    /**
     * The price per picture LMS Labs publishes, read from the free route at most once an hour; null when it does not
     * publish one (route not live, no answer, or no price in the answer).
     *
     * @param bool $fresh read it again now
     * @return int|null
     */
    public static function published_price(bool $fresh = false): ?int {
        $kept = (string)get_config('mod_ailanguageteacher', 'imageprice');
        $time = (int)get_config('mod_ailanguageteacher', 'imagepricetime');
        if (!$fresh && $time > time() - self::PRICE_TTL) {
            return ctype_digit($kept) ? (int)$kept : null;
        }
        $price = null;
        $anchors = false;
        try {
            [$status, $body] = remote::request(self::CAPABILITIES_ROUTE, null, '', 'application/json', 8);
            $data = json_decode($body, true);
            if ($status === 200 && is_array($data) && is_int($data['tariff']['image'] ?? null)) {
                $price = (int)$data['tariff']['image'];
                $anchors = ($data['anchorPositions'] ?? false) === true;
            }
        } catch (\moodle_exception $e) {
            $price = null;
        }
        set_config('imageprice', $price === null ? '' : (string)$price, 'mod_ailanguageteacher');
        set_config('imagepricetime', time(), 'mod_ailanguageteacher');
        set_config('imageanchors', $anchors ? 1 : 0, 'mod_ailanguageteacher');
        return $price;
    }

    /**
     * Why no new picture may be asked for: the price LMS Labs publishes when it differs from the approved price, 0
     * when it publishes none (unknown), or null when it publishes the approved price.
     *
     * @return int|null
     */
    public static function price_hold(): ?int {
        $price = self::published_price();
        if ($price === null) {
            return 0;
        }
        return $price !== self::CREDITS ? $price : null;
    }

    /**
     * The picture request for a scene: an English prompt of at most 2,000 code points, the activity's style and the
     * confirmed ceiling. The same scene gives the same body, so an unresolved request is asked about again unchanged.
     *
     * @param stdClass $instance
     * @param stdClass $scene
     * @return array {prompt, style, maxCredits}
     */
    public static function body(stdClass $instance, stdClass $scene): array {
        $phrases = manager::get_phrases([(int)$scene->id])[$scene->id] ?? [];
        $style = in_array((string)$instance->imagestyle, self::STYLES, true) ? (string)$instance->imagestyle : 'illustration';
        $prompt = lesson::image_prompt($instance, $scene, $phrases);
        if (\core_text::strlen($prompt) > self::MAX_PROMPT) {
            // Shorten the scene description, never the rules or the things to point at.
            $copy = clone $scene;
            $over = \core_text::strlen($prompt) - self::MAX_PROMPT + 1;
            $description = trim((string)$scene->imageprompt) !== '' ? (string)$scene->imageprompt : (string)$scene->title;
            $copy->imageprompt = rtrim(\core_text::substr($description, 0, max(0, \core_text::strlen($description) - $over)))
                . '…';
            $prompt = lesson::image_prompt($instance, $copy, $phrases);
            $prompt = \core_text::substr($prompt, 0, self::MAX_PROMPT);
        }
        $body = ['prompt' => $prompt, 'style' => $style, 'maxCredits' => self::CREDITS];
        self::published_price();
        if (get_config('mod_ailanguageteacher', 'imageanchors')) {
            $anchors = [];
            foreach ($phrases as $phrase) {
                $anchor = (string)($phrase->anchor ?? '');
                if (empty($phrase->distractor) && trim($anchor) !== '' && \core_text::strlen($anchor) <= 120
                        && !preg_match('/[\x00-\x1f\x7f]/', $anchor) && !in_array($anchor, $anchors, true)) {
                    $anchors[] = $anchor;
                }
            }
            if ($anchors) {
                $body['anchors'] = array_slice($anchors, 0, 6);
            }
        }
        return $body;
    }

    /**
     * Creates (or asks again about) one scene's picture. Called only after the teacher confirmed the price.
     *
     * @param stdClass $instance
     * @param \context $context
     * @param stdClass $scene
     * @param int $userid
     * @param bool $replace make a new picture even when the scene has one
     * @param bool $discard the teacher confirmed an unfinished request with other content may be abandoned
     * @return array {url, charged (int|null), made (bool)}
     */
    public static function create(
        stdClass $instance,
        \context $context,
        stdClass $scene,
        int $userid,
        bool $replace = false,
        bool $discard = false
    ): array {
        global $DB;
        [$url] = manager::get_scene_image($context, (int)$scene->id);
        if ($url && !$replace) {
            // Already has a picture: never buy one it does not need (another tab may have made it).
            return ['url' => $url, 'charged' => null, 'made' => false];
        }
        $body = self::body($instance, $scene);
        $prior = $DB->get_record('ailanguageteacher_operation', ['ailanguageteacherid' => $instance->id,
            'userid' => $userid, 'kind' => 'image', 'itemid' => $scene->id, 'state' => 'pending']);
        if ($prior && !$discard) {
            $stored = json_decode((string)$prior->body, true);
            if (is_array($stored) && ($stored['prompt'] ?? null) === $body['prompt']
                    && ($stored['style'] ?? null) === $body['style']
                    && ($stored['maxCredits'] ?? null) === $body['maxCredits']) {
                // A capability-cache refresh must not change the body of "Check again".
                $body = $stored;
            }
        }
        $pending = $DB->record_exists('ailanguageteacher_operation', ['ailanguageteacherid' => $instance->id,
            'kind' => 'image', 'itemid' => $scene->id, 'state' => 'pending', 'bodyhash' => operation::hash($body)]);
        if (!$pending && ($published = self::price_hold()) !== null) {
            // A new picture only at the approved price; an unresolved one is still asked about.
            throw new \moodle_exception(
                $published ? 'pictures_pricehold' : 'pictures_priceunknown',
                'mod_ailanguageteacher',
                '',
                ['published' => $published, 'approved' => self::CREDITS]
            );
        }
        $op = operation::claim((int)$instance->id, $userid, 'image', (int)$scene->id, $body, false, $discard);
        [$status, $answer, , $headers] = remote::request(
            self::ROUTE,
            json_decode((string)$op->body, true),
            $op->idemkey,
            'image/png, image/webp, image/jpeg, application/json',
            120
        );
        if ($status !== 200) {
            operation::not_delivered($op, $status, $answer, $headers);
        }
        $charged = isset($headers['x-credits-charged']) && ctype_digit((string)$headers['x-credits-charged'])
            ? (int)$headers['x-credits-charged'] : null;
        // A delivered answer ends the request whatever happens next: it may have been charged, so it is never asked for
        // again on its own (the same key would only answer 410).
        $usable = strlen($answer) <= self::MAX_BYTES && manager::image_signature(substr($answer, 0, 16)) !== null;
        if ($usable) {
            try {
                manager::save_scene_image_bytes($context, $scene, $answer);
            } catch (\moodle_exception $e) {
                $usable = false;
            }
        }
        operation::complete($op, json_encode(['charged' => $charged, 'saved' => $usable]));
        if (!$usable) {
            throw new \moodle_exception('aibadimage_charged', 'mod_ailanguageteacher', '', $op->idemkey);
        }
        $sent = json_decode((string)$op->body, true);
        if (!empty($sent['anchors'])) {
            try {
                image_positions::apply($scene, $sent['anchors'], (string)($headers['x-anchor-positions'] ?? ''));
            } catch (\Throwable $e) {
                // Optional placement cannot turn a saved, paid picture into a failed request.
            }
        }
        [$url] = manager::get_scene_image($context, (int)$scene->id);
        return ['url' => (string)$url, 'charged' => $charged, 'made' => true];
    }
}
