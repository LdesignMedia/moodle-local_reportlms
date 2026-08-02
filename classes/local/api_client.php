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
     * @return array environment_id, api_key, signing_secret, push_path, expires_at, tier.
     * @throws \moodle_exception When Report LMS refuses the connection.
     */
    public function connect(string $siteurl, string $verificationtoken): array {
        $data = $this->post(config::CONNECT_PATH, [
            'site_url' => $siteurl,
            'verification_token' => $verificationtoken,
            // Tells Report LMS which path to fetch the token back from, and is
            // stored so it knows which plugin to expect a push from.
            'platform' => config::PLATFORM,
        ]);

        foreach (['environment_id', 'api_key', 'signing_secret'] as $required) {
            if (empty($data[$required])) {
                throw new \moodle_exception('error:connectrejected', 'local_reportlms', '', 'missing ' . $required);
            }
        }

        return $data;
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
