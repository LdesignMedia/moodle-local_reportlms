<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Minimal HTTP client for the Report LMS connect API.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Talks to Report LMS during the connect flow.
 */
class api_client {
    /** @var string Base URL of the Report LMS instance, without trailing slash. */
    protected string $baseurl;

    /**
     * Constructor.
     *
     * @param string $baseurl Report LMS base URL.
     */
    public function __construct(string $baseurl) {
        $this->baseurl = rtrim(trim($baseurl), '/');
    }

    /**
     * Connect this site and receive its keys.
     *
     * Report LMS checks the licence for this site URL, then fetches the
     * verification token back off the site before it mints anything.
     *
     * @param string $siteurl This site's wwwroot.
     * @param string $verificationtoken The token verify.php is currently serving.
     * @param int $acknowledgedat When somebody here read the data statement, or 0.
     * @return array environment_id, api_key, signing_secret, push_path, expires_at, tier.
     * @throws \moodle_exception When Report LMS refuses the connection.
     */
    public function connect(string $siteurl, string $verificationtoken, int $acknowledgedat = 0): array {
        $data = $this->post(config::CONNECT_PATH, [
            'site_url' => $siteurl,
            'verification_token' => $verificationtoken,
            // Tells Report LMS which path to fetch the token back from, and is
            // stored so it knows which plugin to expect a push from.
            'platform' => config::PLATFORM,
            // Sent at connect and not only on the first push. Report LMS holds
            // a floor below which it will not serve a site its own figures, and
            // the only other message carrying this is a push, so without it a
            // freshly connected site is told to upgrade a plugin it has just
            // installed.
            'plugin_version' => (int) get_config('local_reportlms', 'version'),
            'platform_version' => (string) get_config('', 'release'),
            /*
             * The reading rides the connection.
             *
             * Report LMS refuses data from a site it does not know has read the
             * statement, and the only other way to tell it needs an API key.
             * This is the call that mints that key, so reporting it separately
             * beforehand was impossible and reporting it afterwards left a
             * window in which a push could be refused for something the
             * administrator had already done.
             *
             * Omitted rather than sent as zero when nothing was read, so the
             * field means "this is when it happened" and never "it did not".
             */
            'privacy_acknowledged_at' => $acknowledgedat > 0 ? $acknowledgedat : null,
        ]);

        foreach (['environment_id', 'api_key', 'signing_secret', 'launch_secret'] as $required) {
            if (empty($data[$required])) {
                throw new \moodle_exception('error:connectrejected', 'local_reportlms', '', 'missing ' . $required);
            }
        }

        return $data;
    }

    /**
     * Send one signed batch to Report LMS.
     *
     * The body is passed in pre-encoded so the signature covers the exact
     * bytes that go on the wire: any re-encoding of the JSON would break it.
     * A retry must re-sign with a fresh timestamp, which callers get by
     * simply calling this again.
     *
     * @param string $pushpath Path on the Report LMS host.
     * @param string $rawbody The JSON body, already encoded.
     * @param string $apikey Bearer key identifying the environment.
     * @param string $signingsecret Secret the body signature is built with.
     * @param string $idempotencykey environment:snapshot:bodyhash, per the contract.
     * @return array Decoded response, carrying batch_id on a 202.
     * @throws \moodle_exception On transport failure or a non-2xx reply.
     */
    public function push(string $pushpath, string $rawbody, string $apikey,
            string $signingsecret, string $idempotencykey): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $timestamp = time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawbody, $signingsecret);

        // Same rationale as post(): the admin-configured first-party endpoint
        // must not be rejected by the cURL SSRF blocklist.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apikey,
            'X-RL-Timestamp: ' . $timestamp,
            'X-RL-Signature: ' . $signature,
            'Idempotency-Key: ' . $idempotencykey,
        ]);

        $response = $curl->post($this->baseurl . $pushpath, $rawbody, [
            // Batches run to a few MB on a first backfill.
            'CURLOPT_TIMEOUT' => 120,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        if ($curl->get_errno()) {
            throw new \moodle_exception('error:pushfailed', 'local_reportlms', '', $curl->error);
        }

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $decoded = json_decode((string) $response, true);

        if ($httpcode < 200 || $httpcode >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) && !empty($decoded['message'])
                ? $decoded['message']
                : 'HTTP ' . $httpcode;

            // The reason travels with the failure, so the caller can tell a
            // decision from weather. A timeout is retried; a refusal that will
            // never change on its own is acted on.
            throw new push_refused_exception(
                is_array($decoded) && !empty($decoded['errorcode']) ? (string) $decoded['errorcode'] : '',
                $message
            );
        }

        return $decoded;
    }

    /**
     * POST JSON and decode the reply.
     *
     * @param string $path Path on the Report LMS host.
     * @param array $payload Request body.
     * @return array Decoded response.
     * @throws \moodle_exception On transport failure or a non-2xx reply.
     */
    protected function post(string $path, array $payload): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // ignoresecurity: this call only ever targets the admin-configured
        // Report LMS base URL, a trusted first-party endpoint, so it must not be
        // rejected by Moodle's cURL SSRF blocklist. Without it a self-hosted
        // Report LMS on a private network, or any site with hardened
        // curlsecurityblockedhosts, fails to connect with "The URL is blocked".
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = $curl->post($this->baseurl . $path, json_encode($payload), [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        if ($curl->get_errno()) {
            throw new \moodle_exception('error:connectfailed', 'local_reportlms', '', $curl->error);
        }

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $decoded = json_decode((string) $response, true);

        if ($httpcode < 200 || $httpcode >= 300 || !is_array($decoded)) {
            // The SaaS sends a machine-readable code alongside the message, for
            // example LICENSE_INVALID or CODE_EXPIRED. Show the message, it is
            // written for the admin reading this screen.
            $message = is_array($decoded) && !empty($decoded['message'])
                ? $decoded['message']
                : 'HTTP ' . $httpcode;
            throw new \moodle_exception('error:connectrejected', 'local_reportlms', '', $message);
        }

        return $decoded;
    }
}
