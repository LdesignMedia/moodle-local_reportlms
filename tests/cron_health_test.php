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
 * Tests for cron health detection.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\cron_health;

/**
 * A push that stopped running is invisible unless somebody notices the silence.
 *
 * @covers \local_reportlms\local\cron_health
 */
final class cron_health_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Never having run is the case that started all of this.
     *
     * A live site sat at the epoch for its entire life while every screen said
     * Connected, because the crontab pointed at a path that did not exist and
     * was silenced.
     */
    public function test_a_site_whose_cron_never_ran_is_reported(): void {
        set_config('lastcronstart', 0, 'tool_task');

        $this->assertSame('nevercron', cron_health::problem());
        $this->assertNotNull(cron_health::message());
    }

    public function test_cron_that_stopped_hours_ago_is_reported(): void {
        set_config('lastcronstart', time() - cron_health::STALE_AFTER - 60, 'tool_task');

        $this->assertSame('cronstale', cron_health::problem());
    }

    /**
     * A recent run and a connected, pushing site is silence.
     *
     * Worth pinning: a warning that fires when nothing is wrong is a warning
     * people learn to ignore, which is worse than none.
     */
    public function test_a_healthy_site_says_nothing(): void {
        set_config('lastcronstart', time() - 60, 'tool_task');

        $task = \core\task\manager::get_scheduled_task(\local_reportlms\task\push_batch::class);
        $task->set_last_run_time(time() - 60);
        \core\task\manager::configure_scheduled_task($task);

        $this->assertNull(cron_health::problem());
        $this->assertNull(cron_health::message());
    }

    /**
     * Cron running while this one task is off is a different fault.
     *
     * Reporting "cron is fine" there would be true and useless.
     */
    public function test_a_disabled_task_is_reported_separately(): void {
        set_config('lastcronstart', time() - 60, 'tool_task');

        $task = \core\task\manager::get_scheduled_task(\local_reportlms\task\push_batch::class);
        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);

        $this->assertSame('taskdisabled', cron_health::problem());
    }

    /**
     * Before a connect, a task that has never run is correct, not a fault.
     */
    public function test_an_unconnected_site_is_not_warned_about_its_task(): void {
        set_config('lastcronstart', time() - 60, 'tool_task');

        $this->assertNull(cron_health::problem());
    }

    public function test_the_last_run_is_described_for_a_human(): void {
        set_config('lastcronstart', 0, 'tool_task');
        $this->assertSame(get_string('cron:never', 'local_reportlms'), cron_health::describe_last_run());

        set_config('lastcronstart', time() - 300, 'tool_task');
        $this->assertStringContainsString('ago', cron_health::describe_last_run());
    }
}
