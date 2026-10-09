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

use moodle_exception;

/**
 * LMS Labs account connection.
 *
 * The approved dedicated lesson-draft route is available to entitled sites.
 * The older scene/image generation interface has no approved endpoint.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs implements provider {
    /** @var string Fixed LMS Labs host. */
    public const BASE_URL = 'https://lms-labs.com';

    /** @var string Dedicated AI Language Teacher lesson draft route. */
    public const DRAFT_ROUTE = '/api/moodle/ai-language-teacher/lessons/draft';

    /**
     * @var string Charge for scenes written with the teacher's own AI assistant (same tariff as a draft, per scene).
     * Requested from LMS Labs on 8 Oct 2026; until it is live LMS Labs answers 404 and nothing is created.
     */
    public const IMPORT_ROUTE = '/api/moodle/ai-language-teacher/lessons/import';

    /** @var int Credits per delivered lesson scene (owner-approved tariff). */
    public const TEXT_CREDITS = 5;

    /** @var callable|null Replacement transport used by unit tests. */
    public static $transport = null;

    /**
     * Whether a complete LMS Labs credential pair is available (Central Config first, then this plugin's settings).
     *
     * @return bool
     */
    public function is_connected(): bool {
        return \mod_ailanguageteacher\local\credentials::find() !== null;
    }

    /**
     * Whether this site has credentials for dedicated lesson drafting.
     *
     * @return bool
     */
    public function can_generate(): bool {
        return $this->is_connected();
    }

    /**
     * Not available until LMS Labs publishes the route.
     *
     * @param string $prompt
     * @return array
     */
    public function generate_lesson(string $prompt): array {
        throw new moodle_exception('ainotavailable', 'mod_ailanguageteacher');
    }

    /**
     * Drafts one lesson scene on the approved dedicated text route (5 credits when delivered).
     *
     * The caller has stored the request (key and body) first. Anything but a delivered draft is recorded on the request
     * and reported: a refusal ends it (nothing charged), no answer or "still working" keeps it for the same key.
     *
     * @param array $body
     * @param \stdClass $operation the stored request
     * @return array the draft
     */
    public function draft(array $body, \stdClass $operation): array {
        [$status, $response, , $headers] = \mod_ailanguageteacher\local\remote::request(
            self::DRAFT_ROUTE,
            $body,
            $operation->idemkey
        );
        if ($status !== 200) {
            \mod_ailanguageteacher\local\operation::not_delivered($operation, $status, $response, $headers);
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !is_array($data['draft'] ?? null)) {
            // Delivered (and perhaps charged) but not usable here: ended, so the teacher can contact support.
            \mod_ailanguageteacher\local\operation::failed($operation);
            throw new moodle_exception('unusabledraft', 'mod_ailanguageteacher', '', (string)($data['requestId'] ?? '-'));
        }
        return $data['draft'];
    }

    /**
     * Charges for scenes written with the teacher's own AI assistant: 5 credits per scene, like a draft.
     *
     * Only the number of scenes and their titles are sent. The caller creates the scenes after this returns.
     *
     * @param array $body {sceneCount, titles}
     * @param \stdClass $operation the stored request
     * @return array {charged, balance}
     */
    public function charge_import(array $body, \stdClass $operation): array {
        [$status, $response, , $headers] = \mod_ailanguageteacher\local\remote::request(
            self::IMPORT_ROUTE,
            $body,
            $operation->idemkey
        );
        if ($status !== 200) {
            \mod_ailanguageteacher\local\operation::not_delivered($operation, $status, $response, $headers);
        }
        $data = json_decode($response, true) ?: [];
        return [
            'charged' => isset($data['creditsCharged']) && is_numeric($data['creditsCharged'])
                ? (int)$data['creditsCharged'] : self::TEXT_CREDITS * (int)$body['sceneCount'],
            'balance' => isset($data['creditsBalance']) && is_numeric($data['creditsBalance'])
                ? (int)$data['creditsBalance'] : null,
        ];
    }

    /**
     * Not available until LMS Labs publishes the route.
     *
     * @param string $prompt
     * @return string
     */
    public function generate_image(string $prompt): string {
        throw new moodle_exception('ainotavailable', 'mod_ailanguageteacher');
    }

    /**
     * Reads the site's balance (GET /api/credits, key in the X-API-Key header).
     *
     * @return array|null
     */
    public function balance(): ?array {
        global $CFG;
        $credentials = \mod_ailanguageteacher\local\credentials::find();
        if ($credentials === null) {
            return null;
        }
        $url = self::BASE_URL . '/api/credits?siteId=' . rawurlencode($credentials['siteid']);
        $headers = ['X-API-Key: ' . $credentials['apikey'], 'Accept: application/json'];
        if (self::$transport) {
            $transport = self::$transport;
            [$status, $body] = $transport($url, $headers);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader($headers);
            // Never follow a redirect: the API key header would be sent on to the new address.
            $body = $curl->get($url, [], ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 10, 'CURLOPT_FOLLOWLOCATION' => 0]);
            if ($curl->get_errno()) {
                return null;
            }
            $status = (int)($curl->get_info()['http_code'] ?? 0);
        }
        if ($status !== 200) {
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return null;
        }
        if (!empty($data['isUnlimited'])) {
            return ['unlimited' => true, 'credits' => -1];
        }
        if (isset($data['creditsRaw']) && is_numeric($data['creditsRaw'])) {
            return ['unlimited' => false, 'credits' => (int)$data['creditsRaw']];
        }
        return null;
    }
}
