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
 * Whether somebody at this site has read what leaves it.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * The site's record of having read the data statement.
 *
 * One home for the whole idea, because it is read from four places that would
 * otherwise each know how it is stored: the connect call that records it, the
 * report page that decides whether to show the steps, the batch the push
 * builds, and the settings page.
 *
 * Two settings back it, and both stay here:
 *
 *   privacyackdate    when it was first read, unix seconds
 *   privacyackuserid  who read it
 *
 * The userid never leaves this site. Report LMS is told the timestamp and
 * nothing more, because a name is precisely what the statement being
 * acknowledged promises never crosses.
 */
class privacy_statement {
    /** @var string Setting holding when it was first read. */
    protected const DATE = 'privacyackdate';

    /** @var string Setting holding who read it. */
    protected const USERID = 'privacyackuserid';

    /** @var string Setting holding whether Report LMS has been told. */
    protected const SYNCED = 'privacyacksynced';

    /**
     * Whether this site holds a record.
     *
     * @return bool
     */
    public static function acknowledged(): bool {
        return self::acknowledged_at() > 0;
    }

    /**
     * When it was first read, or 0.
     *
     * @return int
     */
    public static function acknowledged_at(): int {
        return (int) get_config('local_reportlms', self::DATE);
    }

    /**
     * Who read it, or 0.
     *
     * @return int
     */
    public static function acknowledged_by(): int {
        return (int) get_config('local_reportlms', self::USERID);
    }

    /**
     * Whether Report LMS knows.
     *
     * Not what decides whether the report is shown. It was, and that was a
     * trap: knowing needs a connection, and the gate stood in front of the
     * connection. {@see acknowledged} answers the question the setup steps are
     * really asking, and this one answers whether a push can be expected to be
     * accepted.
     *
     * @return bool
     */
    public static function confirmed(): bool {
        return self::acknowledged() && (int) get_config('local_reportlms', self::SYNCED) > 0;
    }

    /**
     * Record that this user has read it.
     *
     * The first reading stands. Re-recording on every visit would turn a dated
     * record into a timestamp of the last time somebody opened the page, which
     * answers a different question and is no use to whoever has to account for
     * the decision.
     *
     * @param int $userid
     *
     * @return int When it was first read.
     */
    public static function record(int $userid): int {
        if (!self::acknowledged()) {
            set_config(self::DATE, time(), 'local_reportlms');
            set_config(self::USERID, $userid, 'local_reportlms');
        }

        /*
         * Nothing is sent from here.
         *
         * There used to be an HTTP call on this line, and it could not work in
         * the order it was asked for: it needed an API key, and this runs
         * before the site has one. The date travels inside the connect request
         * instead, and every push envelope carries it after that, so there are
         * two standing ways for Report LMS to learn it and neither is a call
         * that only exists to say one number.
         */
        return self::acknowledged_at();
    }

    /**
     * Record that the connect call carried the date, so Report LMS has it.
     *
     * This replaced a second HTTP round trip that ran straight after every
     * connect. That call needed the API key the connection had just returned,
     * which is the whole reason the date now travels inside the connection
     * instead: the endpoint could never be reached before there was a key, and
     * the setup steps ask for the reading first.
     *
     * The claim is safe even if it were somehow wrong. Every push envelope
     * carries the date as well, and Report LMS refuses a batch from a site it
     * does not know has read the statement, so a wrongly set flag costs a
     * refusal that the push path already knows how to recover from.
     *
     * @return void
     */
    public static function sent_with_connect(): void {
        if (self::acknowledged()) {
            set_config(self::SYNCED, 1, 'local_reportlms');
        }
    }

    /**
     * Record that Report LMS accepted a batch carrying the acknowledgement.
     *
     * The push envelope carries privacy_acknowledged_at, and Report LMS refuses
     * a batch from a site that has not read the statement. So an accepted batch
     * is proof it knows, and better proof than the acknowledge endpoint, which
     * can fail on its own while the pushes keep working.
     *
     * @return void
     */
    public static function accepted_in_push(): void {
        if (self::acknowledged() && (int) get_config('local_reportlms', self::SYNCED) === 0) {
            set_config(self::SYNCED, 1, 'local_reportlms');
        }
    }
}
