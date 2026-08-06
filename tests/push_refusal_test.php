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
 * Tests for what happens when Report LMS refuses a push.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\connect_manager;
use local_reportlms\local\push_refused_exception;
use local_reportlms\task\push_batch;

/**
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\push_refused_exception
 */
final class push_refusal_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        // A connected site with something to send.
        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('salt', 'salt-that-must-survive', 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        \local_reportlms\local\userstate::mark_all_dirty();
    }

    /**
     * A client that always refuses, with the reason under test.
     *
     * @param string $reason
     *
     * @return api_client
     */
    private function refusing_client(string $reason): api_client {
        return new class(\local_reportlms\local\config::baseurl(), $reason) extends api_client {
            /** @var string */
            private $reason;

            public function __construct(string $baseurl, string $reason) {
                parent::__construct($baseurl);
                $this->reason = $reason;
            }

            public function push(string $pushpath, string $rawbody, string $apikey,
                    string $signingsecret, string $idempotencykey): array {
                throw new push_refused_exception($this->reason, 'Refused for testing.');
            }
        };
    }

    /**
     * Refused for want of the data statement, so the site disconnects.
     *
     * Not a punishment. A disconnected site shows the setup steps on its report
     * page, and reading the statement is step two. A site left connected and
     * refused shows a dashboard that never fills and says nothing about why.
     */
    public function test_a_statement_refusal_disconnects_the_site(): void {
        $this->assertTrue(connect_manager::is_connected());

        $task = new push_batch();
        $task->set_client($this->refusing_client('privacynotacknowledged'));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertFalse(connect_manager::is_connected());
    }

    /**
     * The salt survives, so reconnecting resumes rather than starting again.
     *
     * This is what "keep the data until they agree" rests on: the coded
     * identifiers are derived from the salt, so losing it would leave every
     * row Report LMS already holds pointing at people the site can no longer
     * name, and a reconnect would build a second history beside the first.
     */
    public function test_the_salt_survives_so_the_kept_data_still_lines_up(): void {
        $task = new push_batch();
        $task->set_client($this->refusing_client('privacynotacknowledged'));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame('salt-that-must-survive', get_config('local_reportlms', 'salt'));
    }

    /**
     * Weather is not a decision.
     *
     * A timeout, a restart, a bad gateway: those are retried, and disconnecting
     * over one would turn a blip into an outage somebody has to notice and undo.
     */
    public function test_an_ordinary_failure_leaves_the_site_connected(): void {
        $task = new push_batch();
        $task->set_client($this->refusing_client(''));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue(connect_manager::is_connected());
    }

    /**
     * Some other refusal is also not this one.
     */
    public function test_a_different_refusal_reason_leaves_the_site_connected(): void {
        $task = new push_batch();
        $task->set_client($this->refusing_client('somethingelse'));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue(connect_manager::is_connected());
    }
}
