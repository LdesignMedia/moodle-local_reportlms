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
 * A one-off push, run at the next cron tick.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\task;

/**
 * The ad-hoc counterpart of the scheduled push.
 *
 * Queued after a successful connect, so the first backfill starts within a
 * minute instead of whenever the fifteen-minute schedule next fires. The
 * same task is the natural carrier for the on-demand refresh button later:
 * anything that wants "push now" queues this.
 *
 * The work itself is the scheduled task's, shared, and serialised by the
 * push lock, so an ad-hoc run and a scheduled run can never send the same
 * learners twice side by side.
 */
class push_now extends \core\task\adhoc_task {
    /**
     * Queue a run for the next cron tick, once.
     *
     * The true flag deduplicates: if a push_now is already waiting, another
     * connect click does not stack a second one.
     */
    public static function queue(): void {
        \core\task\manager::queue_adhoc_task(new self(), true);
    }

    /**
     * Same work, same lock, different trigger.
     */
    public function execute(): void {
        (new push_batch())->execute();
    }
}
