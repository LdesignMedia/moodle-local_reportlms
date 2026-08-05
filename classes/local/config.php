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
 * Connection defaults for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Single source for the Report LMS endpoints an admin never has to think about.
 *
 * The base URL and push path are not shown on the plugin settings page. They are
 * only editable in advanced mode (settings.php?section=local_reportlms&mode=advanced)
 * and can be pinned for good in config.php with:
 *
 *     $CFG->forced_plugin_settings['local_reportlms']['baseurl'] = 'https://reportlms.example.com';
 */
class config {
    /** @var string Report LMS service the plugin talks to unless an admin overrides it. */
    public const DEFAULT_BASEURL = 'https://reportlms.example.com';

    /** @var string Batch push endpoint on the Report LMS host. */
    public const DEFAULT_PUSHPATH = '/api/v1/push/batch';

    /** @var string What this plugin reports itself as on the wire. */
    public const PLATFORM = 'moodle';

    /** @var string Where the plugin asks to be connected. */
    public const CONNECT_PATH = '/api/v1/connect';

    /** @var string Where a site reports that somebody has read the data statement. */
    public const ACKNOWLEDGE_PATH = '/api/v1/privacy-acknowledgement';

    /** @var string Where the SaaS serves the embedded dashboard. */
    public const DEFAULT_EMBED_PATH = '/embed';

    /** @var string Where the SaaS explains what to do before a site is connected. */
    public const START_PATH = '/start';

    /** @var string Public path this plugin serves its verification token on. */
    public const VERIFY_PATH = '/local/reportlms/verify.php';

    /**
     * The Report LMS base URL, without a trailing slash.
     *
     * @return string
     */
    public static function baseurl(): string {
        $baseurl = trim((string) get_config('local_reportlms', 'baseurl'));
        if ($baseurl === '') {
            $baseurl = self::DEFAULT_BASEURL;
        }

        return rtrim($baseurl, '/');
    }

    /**
     * The Report LMS origin: scheme, host and port, nothing else.
     *
     * postMessage compares origins, not URLs, so a base URL carrying a path
     * would never match an incoming event.origin and label resolution would
     * silently do nothing.
     *
     * @return string
     */
    public static function origin(): string {
        $parts = parse_url(self::baseurl());

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Where to send somebody whose site is not connected yet.
     *
     * Carries this site's address so the page can tell which steps are already
     * done, and the language so the steps are not in English on a Dutch site.
     *
     * @return string
     */
    /**
     * Whether somebody here has read what leaves this site.
     *
     * @return bool
     */
    public static function privacy_acknowledged(): bool {
        // What Report LMS knows, not merely what this site recorded: it is
        // the refusal on that side that the report waits on.
        return privacy_statement::confirmed();
    }

    /**
     * @return string
     */
    public static function start_url(): string {
        global $CFG;

        return self::baseurl() . self::START_PATH . '?' . http_build_query([
            'site' => $CFG->wwwroot,
            'lang' => current_language(),
            // This site's own answer, which is the one that counts. A row on
            // the Report LMS side can still hold a usable key after a site
            // disconnected locally, so asking it would tick a step the
            // administrator has not taken.
            'connected' => connect_manager::is_connected() ? 1 : 0,
            // Whether somebody here has read what leaves this site. Held on
            // this side, so the steps have to be told.
            'acknowledged' => privacy_statement::confirmed() ? 1 : 0,
        ], '', '&');
    }

    /**
     * The batch push path, with a leading slash.
     *
     * @return string
     */
    public static function pushpath(): string {
        $pushpath = trim((string) get_config('local_reportlms', 'pushpath'));
        if ($pushpath === '') {
            $pushpath = self::DEFAULT_PUSHPATH;
        }

        return '/' . ltrim($pushpath, '/');
    }

    /**
     * The absolute batch push endpoint.
     *
     * @return string
     */
    public static function pushendpoint(): string {
        return self::baseurl() . self::pushpath();
    }

}
