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
 * Waiting longer each time something keeps failing.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

/**
 * An hour, then two, then four, never more than a day.
 *
 * For calls a quiet site makes on its own: the health call and the daily
 * heartbeat. A key that has ended, or a Report LMS that keeps refusing, would
 * otherwise get the same call every fifteen minutes for as long as the site
 * runs. One success starts the count again.
 *
 * Kept in two plugin settings per call: {name}fails and {name}failedat.
 */
class backoff {
    /** @var int Seconds to wait after the first failure. */
    public const FIRST = HOURSECS;

    /** @var int The longest wait. */
    public const CAP = DAYSECS;

    /** @var string[] Every call that keeps a count, so a new connection can start them all again. */
    public const NAMES = ['health', 'heartbeat'];

    /**
     * How long to wait after this many earlier tries.
     *
     * @param int $tries Earlier consecutive tries, 0 for the first.
     * @return int Seconds.
     */
    public static function wait(int $tries): int {
        // Past five doublings the cap applies anyway; this keeps the power small.
        return (int) min(self::FIRST * (2 ** min(max($tries, 0), 6)), self::CAP);
    }

    /**
     * Whether the call may be made now.
     *
     * @param string $name
     * @return bool
     */
    public static function due(string $name): bool {
        $fails = (int) get_config('local_reportlms', $name . 'fails');

        if ($fails <= 0) {
            return true;
        }

        return time() >= (int) get_config('local_reportlms', $name . 'failedat') + self::wait($fails - 1);
    }

    /**
     * How many times in a row the call failed.
     *
     * @param string $name
     * @return int
     */
    public static function fails(string $name): int {
        return (int) get_config('local_reportlms', $name . 'fails');
    }

    /**
     * Count one more failure, from now.
     *
     * @param string $name
     * @return void
     */
    public static function failed(string $name): void {
        set_config($name . 'fails', self::fails($name) + 1, 'local_reportlms');
        set_config($name . 'failedat', time(), 'local_reportlms');
    }

    /**
     * The call worked: start the count again.
     *
     * Writes only when there was a count, so a healthy site does not touch its
     * settings on every run.
     *
     * @param string $name
     * @return void
     */
    public static function succeeded(string $name): void {
        if (get_config('local_reportlms', $name . 'fails') === false) {
            return;
        }

        unset_config($name . 'fails', 'local_reportlms');
        unset_config($name . 'failedat', 'local_reportlms');
    }

    /**
     * Start every count again, for a new or ended connection.
     *
     * @return void
     */
    public static function reset_all(): void {
        foreach (self::NAMES as $name) {
            self::succeeded($name);
        }
    }
}
