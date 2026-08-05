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
 * otherwise each know how it is stored: the web service the frame calls, the
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
     * This, and not {@see acknowledged}, is what decides whether the report is
     * shown. Report LMS refuses data from a site it does not know has read the
     * statement, so a site whose answer never arrived would otherwise sit in
     * front of a report that is never going to fill, with nothing on screen
     * saying why.
     *
     * @return bool
     */
    public static function confirmed(): bool {
        return self::acknowledged() && (int) get_config('local_reportlms', self::SYNCED) > 0;
    }

    /**
     * Record that this user has read it, and tell Report LMS.
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

        // Reported every time, not only the first. Report LMS keeps the
        // earliest date it is given, so a repeat is harmless, and it makes this
        // self-healing: a site whose first report was lost, or that read the
        // statement before this reporting existed, catches up the next time
        // anyone confirms rather than staying refused forever.
        self::report(self::acknowledged_at());

        return self::acknowledged_at();
    }

    /**
     * Tell Report LMS, on its own endpoint.
     *
     * Not carried on the next push: the push is precisely what this unblocks,
     * since Report LMS refuses batches from a site that has not read the
     * statement. Delivering the fact on the refused message would leave the
     * site waiting a full cron cycle after somebody had already done the one
     * thing being asked of them.
     *
     * Never fatal. The reading is recorded locally either way and the push
     * payload carries it as a fallback, so a Report LMS that is briefly
     * unreachable delays the report rather than losing the answer.
     *
     * @param int $acknowledgedat
     *
     * @return bool Whether Report LMS was told.
     */
    protected static function report(int $acknowledgedat): bool {
        $apikey = trim((string) get_config('local_reportlms', 'apikey'));

        if ($apikey === '') {
            // Not connected yet, so there is nobody to tell. The connect itself
            // is what carries the state across in that order.
            return false;
        }

        try {
            (new api_client(config::baseurl()))->acknowledge_privacy($acknowledgedat, $apikey);
            set_config(self::SYNCED, 1, 'local_reportlms');

            return true;
        } catch (\Throwable $exception) {
            debugging(
                'local_reportlms: could not report the data statement acknowledgement: '
                    . $exception->getMessage(),
                DEBUG_DEVELOPER
            );

            return false;
        }
    }
}
