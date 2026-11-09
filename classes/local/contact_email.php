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
 * The address Report LMS writes to about this site's licence and service.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * One home for the contact address and for what Report LMS says about it.
 *
 * Two settings back it:
 *
 *   contactemail     the address this site gives, empty when it gives none
 *   contactemailset  what Report LMS last said about holding one: '1' or '0',
 *                    and absent when it has not said or is too old to say
 *
 * Absent counts as "has one". A Report LMS from before this feature never
 * answers the question, and a site must never be held up on a question nobody
 * can answer.
 *
 * A third, lastcontacthash, remembers what Report LMS has accepted so a changed
 * address goes out on the next push even when no learner moved.
 */
class contact_email {
    /** @var int Seconds a health answer is trusted before the report page asks again. */
    public const CHECK_TTL = 300;

    /**
     * The address this site gives, or an empty string.
     *
     * @return string
     */
    public static function current(): string {
        return trim((string) get_config('local_reportlms', 'contactemail'));
    }

    /**
     * Keep an address, if it is one.
     *
     * @param string $email
     *
     * @return bool Whether it was accepted and stored.
     */
    public static function save(string $email): bool {
        $email = trim($email);

        if ($email === '' || !validate_email($email)) {
            return false;
        }

        set_config('contactemail', $email, 'local_reportlms');

        return true;
    }

    /**
     * Whether the address has changed since Report LMS last accepted it.
     *
     * @return bool
     */
    public static function unsent(): bool {
        $current = self::current();

        return $current !== '' && sha1($current) !== (string) get_config('local_reportlms', 'lastcontacthash');
    }

    /**
     * Remember that Report LMS accepted this address.
     *
     * @param string $email
     *
     * @return void
     */
    public static function mark_sent(string $email): void {
        set_config('lastcontacthash', sha1($email), 'local_reportlms');
    }

    /**
     * Whether Report LMS has said it holds no contact for this site.
     *
     * @return bool False for "has one" and for "has not said".
     */
    public static function reportlms_has_none(): bool {
        return get_config('local_reportlms', 'contactemailset') === '0';
    }

    /**
     * Keep what a health answer says about the contact.
     *
     * A missing or non-boolean field clears the record, which reads as unknown.
     *
     * @param array $health The decoded health answer.
     *
     * @return void
     */
    public static function store_health(array $health): void {
        if (array_key_exists('contact_email_set', $health) && is_bool($health['contact_email_set'])) {
            set_config('contactemailset', $health['contact_email_set'] ? '1' : '0', 'local_reportlms');

            return;
        }

        unset_config('contactemailset', 'local_reportlms');
    }

    /**
     * Ask Report LMS how things stand, at most once per CHECK_TTL unless forced.
     *
     * Never throws: a Report LMS that cannot be reached leaves the answer as it
     * was, and the report page does not depend on it.
     *
     * @param api_client|null $client Optional client override for tests.
     * @param bool $force Ask even when the last answer is recent.
     *
     * @return void
     */
    public static function refresh(?api_client $client = null, bool $force = false): void {
        $checkedat = (int) get_config('local_reportlms', 'contactemailcheckedat');

        if (!$force && $checkedat > time() - self::CHECK_TTL) {
            return;
        }

        $apikey = trim((string) get_config('local_reportlms', 'apikey'));

        if ($apikey === '') {
            return;
        }

        // Set before asking, so a Report LMS that is down is not asked again by
        // every page load.
        set_config('contactemailcheckedat', time(), 'local_reportlms');

        try {
            $client = $client ?? new api_client(config::baseurl());
            self::store_health($client->health($apikey));
        } catch (\moodle_exception $exception) {
            return;
        }
    }

    /**
     * Whether this viewer should be asked for a contact instead of seeing the report.
     *
     * Only a site administrator, only on a connected site, and only when this
     * site has no address and Report LMS has said it holds none either.
     *
     * @param api_client|null $client Optional client override for tests.
     *
     * @return bool
     */
    public static function must_ask(?api_client $client = null): bool {
        if (!connect_manager::is_connected() || self::current() !== '') {
            return false;
        }

        if (!has_capability('moodle/site:config', \context_system::instance())) {
            return false;
        }

        self::refresh($client);

        return self::reportlms_has_none();
    }
}
