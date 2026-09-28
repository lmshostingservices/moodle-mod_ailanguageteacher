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

    /** Draft on the approved dedicated text route. The caller persists the body/key first. */
    public function draft(array $body, string $key): array {
        [$status, $response] = \mod_ailanguageteacher\local\remote::request(
            '/api/moodle/ai-language-teacher/lessons/draft', $body, $key
        );
        $data = json_decode($response, true);
        if ($status !== 200 || !is_array($data) || !isset($data['draft'])) {
            if ($status === 410) {
                throw new moodle_exception('operationexpired', 'mod_ailanguageteacher');
            }
            throw new moodle_exception('remoteerror', 'mod_ailanguageteacher', '',
                \mod_ailanguageteacher\local\remote::error($status, $response));
        }
        return $data['draft'];
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
            $body = $curl->get($url, [], ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 10]);
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
