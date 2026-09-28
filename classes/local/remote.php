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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Fixed LMS Labs transport.
 *
 * @package mod_ailanguageteacher
 * @copyright 2026 LMS Hosting Services
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_ailanguageteacher\local;

/**
 * Fixed-host server-to-server transport. Credentials are never exposed to JavaScript.
 *
 * A transport hook permits request/response fixtures without paid calls.
 */
final class remote {
    public const HOST = 'https://lms-labs.com';
    public static $transport = null;

    public static function request(string $path, ?array $body = null, string $key = ''): array {
        global $CFG;
        $pair = credentials::resolve();
        $headers = ['X-Site-ID: ' . $pair['siteid'], 'X-API-Key: ' . $pair['apikey'],
            'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $url = self::HOST . $path;
        if (self::$transport !== null) {
            return (self::$transport)($url, $headers, $body === null ? null : json_encode($body));
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader($headers);
        $opts = ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 45];
        $response = $body === null ? $curl->get($url, [], $opts) : $curl->post($url, json_encode($body), $opts);
        if ($curl->get_errno()) {
            throw new \moodle_exception('remoteuncertain', 'mod_ailanguageteacher');
        }
        return [(int)($curl->get_info()['http_code'] ?? 0), (string)$response,
            $curl->get_info()];
    }

    public static function error(int $status, string $body): string {
        $data = json_decode($body, true);
        $code = $data['error']['code'] ?? $data['error'] ?? 'REMOTE_ERROR';
        return is_string($code) && preg_match('/^[A-Z_]+$/', $code) ? $code : 'REMOTE_ERROR';
    }
}