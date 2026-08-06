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
 * Whether this site's cron is actually running.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\task\push_batch;

defined('MOODLE_INTERNAL') || die();

/**
 * Reports whether Moodle's cron is running, and therefore whether anything is
 * being sent at all.
 *
 * Exists because a site can be licensed, connected, enabled and completely
 * silent, with every screen saying Connected. Found on a live site whose
 * crontab pointed at a path that did not exist and was silenced with
 * "> /dev/null 2>&1", so it failed every minute for the life of the site and
 * nothing anywhere said so.
 *
 * The plugin cannot fix somebody's crontab. It can stop being the last thing to
 * find out.
 */
class cron_health {
    /**
     * How long without a run before something is wrong.
     *
     * Six hours, not one. Moodle's cron is often deliberately infrequent, and a
     * warning that fires during a normal quiet hour is a warning people learn to
     * ignore, which is worse than no warning at all.
     *
     * @var int
     */
    public const STALE_AFTER = 6 * HOURSECS;

    /**
     * When Moodle's cron last started, or 0 for never.
     *
     * @return int
     */
    public static function cron_last_run(): int {
        return (int) get_config('tool_task', 'lastcronstart');
    }

    /**
     * When this plugin's push task last ran, or 0 for never.
     *
     * Read separately from cron itself, because the two fail differently: cron
     * can be running while this one task is disabled, and saying "cron is fine"
     * would then be true and useless.
     *
     * @return int
     */
    public static function push_last_run(): int {
        $task = \core\task\manager::get_scheduled_task(push_batch::class);

        return $task ? (int) $task->get_last_run_time() : 0;
    }

    /**
     * Whether this site's push task is switched off in Moodle's task admin.
     *
     * @return bool
     */
    public static function push_disabled(): bool {
        $task = \core\task\manager::get_scheduled_task(push_batch::class);

        return $task ? (bool) $task->get_disabled() : false;
    }

    /**
     * What is wrong, or null when nothing is.
     *
     * Returns the most specific true statement rather than the first failing
     * check. An administrator told "cron has never run" can act; one told
     * "something is wrong with reporting" cannot.
     *
     * @return string|null One of: nevercron, cronstale, taskdisabled, tasknever, taskstale.
     */
    public static function problem(): ?string {
        $cron = self::cron_last_run();

        if ($cron === 0) {
            return 'nevercron';
        }

        if ($cron < time() - self::STALE_AFTER) {
            return 'cronstale';
        }

        // Cron is alive, so anything still wrong is about this task alone.
        if (self::push_disabled()) {
            return 'taskdisabled';
        }

        $push = self::push_last_run();

        if ($push === 0) {
            // Only worth reporting once the site is connected: before that the
            // task correctly does nothing.
            return connect_manager::is_connected() ? 'tasknever' : null;
        }

        if ($push < time() - self::STALE_AFTER) {
            return 'taskstale';
        }

        return null;
    }

    /**
     * The problem in a sentence an administrator can act on, or null.
     *
     * @return string|null
     */
    public static function message(): ?string {
        $problem = self::problem();

        if ($problem === null) {
            return null;
        }

        return get_string('cron:' . $problem, 'local_reportlms');
    }

    /**
     * A relative description of when cron last ran, for the settings page.
     *
     * @return string
     */
    public static function describe_last_run(): string {
        $cron = self::cron_last_run();

        if ($cron === 0) {
            return get_string('cron:never', 'local_reportlms');
        }

        return get_string('cron:ago', 'local_reportlms', format_time(time() - $cron));
    }
}
