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
 * heartbeat, and every push while Report LMS says the key has ended. A key
 * that has ended, or a Report LMS that keeps refusing, would otherwise get
 * the same call every fifteen minutes for as long as the site runs. One
 * success starts the count again. Every refused push is counted, so an
 * accepted one is known as a recovery, but the count holds pushes back only
 * while the key has ended.
 *
 * A count belongs to the Report LMS address it was made against, so fixing
 * the address, in the settings or in config.php, starts it again.
 *
 * Kept in three plugin settings per call: {name}fails, {name}failedat and
 * {name}failedurl.
 */
class backoff {
    /** @var int Seconds to wait after the first failure. */
    public const FIRST = HOURSECS;

    /** @var int The longest wait. */
    public const CAP = DAYSECS;

    /** @var string[] Every call that keeps a count, so a new connection can start them all again. */
    public const NAMES = ['health', 'heartbeat', 'push'];

    /** @var string Plugin config key: set while Report LMS says this site's key has ended. */
    public const KEY_ENDED = 'keyended';

    /**
     * How long to wait after this many earlier tries.
     *
     * @param int $tries Earlier consecutive tries, 0 for the first.
     * @param int $cap The longest wait, in seconds.
     * @return int Seconds.
     */
    public static function wait(int $tries, int $cap = self::CAP): int {
        // Past five doublings the cap applies anyway; this keeps the power small.
        return (int) min(self::FIRST * (2 ** min(max($tries, 0), 6)), $cap);
    }

    /**
     * Whether the call may be made now.
     *
     * @param string $name
     * @param int $cap The longest wait, in seconds.
     * @return bool
     */
    public static function due(string $name, int $cap = self::CAP): bool {
        $fails = self::fails($name);

        if ($fails <= 0) {
            return true;
        }

        return self::now() >= (int) get_config('local_reportlms', $name . 'failedat') + self::wait($fails - 1, $cap);
    }

    /**
     * How many times in a row the call failed at the Report LMS address in use now.
     *
     * Failures at another address do not count.
     *
     * @param string $name
     * @return int
     */
    public static function fails(string $name): int {
        if ((string) get_config('local_reportlms', $name . 'failedurl') !== config::baseurl()) {
            return 0;
        }

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
        set_config($name . 'failedat', self::now(), 'local_reportlms');
        set_config($name . 'failedurl', config::baseurl(), 'local_reportlms');
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
        unset_config($name . 'failedurl', 'local_reportlms');
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

    /**
     * Whether Report LMS last said this site's key has ended.
     *
     * @return bool
     */
    public static function key_has_ended(): bool {
        return get_config('local_reportlms', self::KEY_ENDED) !== false;
    }

    /**
     * Forget that the key ended: it works again, or its connection is gone.
     *
     * A push only waits while the key has ended, so its count starts again too.
     *
     * @return void
     */
    public static function forget_key_ended(): void {
        if (self::key_has_ended()) {
            unset_config(self::KEY_ENDED, 'local_reportlms');
        }

        self::succeeded('push');
    }

    /**
     * The time now, from Moodle's clock, so a test can move it.
     *
     * @return int
     */
    protected static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
