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

    /** @var string Where the SaaS serves the embedded dashboard. */
    public const DEFAULT_EMBED_PATH = '/embed';

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
