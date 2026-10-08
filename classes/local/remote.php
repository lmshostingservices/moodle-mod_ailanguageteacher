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

/**
 * Fixed-host server-to-server transport to LMS Labs. Credentials are never exposed to JavaScript.
 *
 * Redirects are never followed, so the credential headers can only ever reach lms-labs.com. A transport hook permits
 * request/response fixtures without paid calls.
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class remote {
    /** @var string Fixed LMS Labs host. */
    public const HOST = 'https://lms-labs.com';

    /** @var string[] Error codes that leave the outcome unknown (the same key must be asked again). */
    public const UNCERTAIN = ['SETTLEMENT_UNCONFIRMED', 'DEADLINE_EXCEEDED'];

    /** @var string[] Documented 5xx codes LMS Labs uses when the provider failed before anything was charged. */
    public const PROVIDER_REFUSED = ['PROVIDER_UNAVAILABLE', 'PROVIDER_FAILED', 'PROVIDER_RATE_LIMITED',
        'INVALID_PROVIDER_RESULT', 'SPEECH_FAILED', 'IMAGE_FAILED'];

    /**
     * @var callable|null Replacement transport for unit tests: fn($url, $headers, $body) => [status, body, info,
     * headers]; headers is optional.
     */
    public static $transport = null;

    /**
     * Sends one request. Nothing is retried here.
     *
     * @param string $path route on the fixed host
     * @param array|null $body JSON body (POST), or null for GET
     * @param string $key Idempotency-Key (POST)
     * @param string $accept Accept header
     * @return array [status, body, info, headers (lower-case names)]
     * @throws \moodle_exception remoteuncertain when no answer arrived
     */
    public static function request(
        string $path,
        ?array $body = null,
        string $key = '',
        string $accept = 'application/json'
    ): array {
        global $CFG;
        $pair = credentials::resolve();
        $headers = ['X-Site-ID: ' . $pair['siteid'], 'X-API-Key: ' . $pair['apikey'], 'Accept: ' . $accept];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $url = self::HOST . $path;
        if (self::$transport !== null) {
            $answer = (self::$transport)($url, $headers, $body === null ? null : json_encode($body));
            return [(int)$answer[0], (string)$answer[1], $answer[2] ?? [], array_change_key_case($answer[3] ?? [])];
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader($headers);
        // Never follow a redirect: the credential headers would be sent on to the new address.
        $opts = ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 45, 'CURLOPT_FOLLOWLOCATION' => 0];
        $response = $body === null ? $curl->get($url, [], $opts) : $curl->post($url, json_encode($body), $opts);
        if ($curl->get_errno()) {
            throw new \moodle_exception('remoteuncertain', 'mod_ailanguageteacher');
        }
        $info = $curl->get_info();
        return [(int)($info['http_code'] ?? 0), (string)$response, $info,
            array_change_key_case((array)$curl->getResponse())];
    }

    /**
     * The upper-case error code of an answer, or REMOTE_ERROR.
     *
     * @param int $status
     * @param string $body
     * @return string
     */
    public static function error(int $status, string $body): string {
        $data = json_decode($body, true);
        $code = $data['error']['code'] ?? $data['error'] ?? 'REMOTE_ERROR';
        return is_string($code) && preg_match('/^[A-Z_]+$/', $code) ? $code : 'REMOTE_ERROR';
    }

    /**
     * What a non-200 answer means for a stored request.
     *
     * pending (202: ask again later with the same key), uncertain (no definite answer: ask again with the same key),
     * gone (410), or refused (LMS Labs said no and charged nothing: the request is finished, and a new click is a new
     * request).
     *
     * @param int $status
     * @param string $body
     * @return string pending|uncertain|gone|refused
     */
    public static function outcome(int $status, string $body): string {
        $code = self::error($status, $body);
        if ($status === 202) {
            return 'pending';
        }
        if ($status === 410) {
            return 'gone';
        }
        // An unexpected server error is never taken as "not charged": only documented provider refusals are a no.
        if (
            $status === 0 || in_array($code, self::UNCERTAIN, true) ||
                ($status >= 500 && !in_array($code, self::PROVIDER_REFUSED, true))
        ) {
            return 'uncertain';
        }
        return 'refused';
    }

    /**
     * Throws the right teacher-facing exception for a non-200 answer (the caller has already updated the request).
     *
     * @param int $status
     * @param string $body
     * @param array $headers
     * @throws \moodle_exception
     */
    public static function fail(int $status, string $body, array $headers = []): void {
        $outcome = self::outcome($status, $body);
        if ($outcome === 'pending') {
            $wait = max(1, min(60, (int)($headers['retry-after'] ?? 5)));
            throw new \moodle_exception('remotepending', 'mod_ailanguageteacher', '', $wait);
        }
        if ($outcome === 'uncertain') {
            throw new \moodle_exception('remoteuncertain', 'mod_ailanguageteacher');
        }
        $code = $status === 404 ? 'NOT_LIVE' : self::error($status, $body);
        $known = ['INSUFFICIENT_CREDITS', 'NO_ENTITLEMENT', 'INVALID_CREDENTIALS', 'RATE_LIMITED', 'NOT_LIVE',
            'IDEMPOTENCY_CONFLICT'];
        if ($status === 402) {
            $code = 'INSUFFICIENT_CREDITS';
        } else if ($status === 401) {
            $code = 'INVALID_CREDENTIALS';
        } else if ($status === 403 && !in_array($code, $known, true)) {
            $code = 'NO_ENTITLEMENT';
        } else if ($status === 429) {
            $code = 'RATE_LIMITED';
        } else if ($status === 409) {
            $code = 'IDEMPOTENCY_CONFLICT';
        }
        $key = in_array($code, $known, true) ? 'remoterefused_' . strtolower($code) : 'remoteerror';
        throw new \moodle_exception($key, 'mod_ailanguageteacher', '', $code);
    }
}
