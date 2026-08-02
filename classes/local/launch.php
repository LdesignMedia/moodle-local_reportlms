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
 * Mints the short-lived token that lets this site embed the dashboard.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Builds the embed URL for the person looking at the page.
 *
 * The token is the whole authorisation envelope. Report LMS has no user
 * accounts, so what this says about the viewer is exactly what they get to
 * see, which is why the scope is decided here, inside Moodle, where the
 * organisation structure actually lives.
 */
class launch {
    /** @var int Seconds a token stays valid. It only has to survive one page load. */
    public const TTL = 300;

    /**
     * The URL to put in the iframe.
     *
     * @return string
     */
    public static function embed_url(): string {
        $environmentid = (string) get_config('local_reportlms', 'environmentid');
        $embedpath = (string) get_config('local_reportlms', 'embedpath');

        if ($embedpath === '') {
            $embedpath = config::DEFAULT_EMBED_PATH;
        }

        // The separator is passed explicitly. Moodle sets PHP's
        // arg_separator.output to &amp;, so the default would return entities,
        // html_writer would escape them again, and the parameter would arrive
        // named "amp;token".
        return config::baseurl() . $embedpath . '?' . http_build_query([
            'env' => $environmentid,
            'token' => self::mint(),
        ], '', '&');
    }

    /**
     * Build and sign the token.
     *
     * @return string
     */
    protected static function mint(): string {
        global $CFG;

        $viewer = self::viewer();
        $now = time();

        $claims = [
            'iss' => $CFG->wwwroot,
            'env' => (string) get_config('local_reportlms', 'environmentid'),
            'sub' => $viewer['sub'],
            'role' => $viewer['role'],
            'scope' => $viewer['scope'],
            'iat' => $now,
            'exp' => $now + self::TTL,
            // One-time id. A launch URL ends up in browser history, in referrer
            // headers and in any proxy log on the way, so it must not be
            // reusable once it has been spent.
            'jti' => random_string(32),
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $input = self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims));

        // Signed with the launch secret, never the push signing secret. This
        // one travels through a browser, so a leak must not also grant the
        // ability to push data.
        $secret = (string) get_config('local_reportlms', 'launchsecret');
        $signature = self::b64(hash_hmac('sha256', $input, $secret, true));

        return $input . '.' . $signature;
    }

    /**
     * Who is looking, and what they are allowed to see.
     *
     * For now every viewer is treated as the site administrator with the whole
     * organisation in scope, which is why view.php only lets administrators in
     * at all. Widening the page before this method is finished would hand every
     * viewer the entire organisation.
     *
     * TODO: derive the role and scope from the org tree, so a manager's token
     * carries only the nodes they manage and the SaaS scopes every query to
     * that subtree. Depends on the manager definition in 86cay1v20.
     *
     * @return array{sub: string, role: string, scope: array}
     */
    protected static function viewer(): array {
        return [
            'sub' => pseudonym::learner(2),
            'role' => 'organisation',
            // Empty means the whole organisation. A manager's token will carry
            // their node ids here.
            'scope' => [],
        ];
    }

    /**
     * Base64url, as JWT uses.
     *
     * @param string $raw
     * @return string
     */
    protected static function b64(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
