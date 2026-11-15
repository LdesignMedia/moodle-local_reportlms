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
 * Tests for the send switch, the cron log lines and the push flags.
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
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;
use local_reportlms\task\push_now;

/**
 * What the scheduled push does with the switch, with a refusal and with a change made while it ran.
 *
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\task\push_now
 * @covers \local_reportlms\local\userstate
 */
final class push_safety_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('launchsecret', 'launch-secret', 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        userstate::mark_all_dirty();
    }

    /**
     * A client that counts pushes, and answers each with a refusal when told to.
     *
     * @param string|null $refusal The message to refuse with, or null to accept.
     * @param int $calls Count of pushes, by reference.
     *
     * @return api_client
     */
    private function client(?string $refusal, int &$calls): api_client {
        return new class (\local_reportlms\local\config::baseurl(), $refusal, $calls) extends api_client {
            /** @var string|null */
            private $refusal;
            /** @var int */
            private $calls;

            public function __construct(string $baseurl, ?string $refusal, int &$calls) {
                parent::__construct($baseurl);
                $this->refusal = $refusal;
                $this->calls = &$calls;
            }

            /**
             * Answer like a Report LMS that sends no plan limit.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                return [];
            }

            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                $this->calls++;

                if ($this->refusal !== null) {
                    throw new push_refused_exception('', $this->refusal);
                }

                return ['batch_id' => 'b1'];
            }
        };
    }

    /**
     * Run the scheduled task and return what it said.
     *
     * @param api_client $client
     *
     * @return string
     */
    private function run_task(api_client $client): string {
        $task = new push_batch();
        $task->set_client($client);

        ob_start();
        $task->execute();

        return ob_get_clean();
    }

    public function test_a_connected_site_with_sending_off_does_not_push(): void {
        set_config('enabled', 0, 'local_reportlms');
        $calls = 0;

        $output = $this->run_task($this->client(null, $calls));

        $this->assertSame(0, $calls);
        $this->assertStringContainsString('sending is turned off', $output);
    }

    public function test_an_unset_switch_does_not_push(): void {
        unset_config('enabled', 'local_reportlms');
        $calls = 0;

        $this->run_task($this->client(null, $calls));

        $this->assertSame(0, $calls);
    }

    public function test_a_connected_site_with_sending_on_pushes(): void {
        set_config('enabled', 1, 'local_reportlms');
        $calls = 0;

        $this->run_task($this->client(null, $calls));

        $this->assertGreaterThan(0, $calls);
    }

    public function test_the_ad_hoc_task_honours_the_switch_too(): void {
        set_config('enabled', 0, 'local_reportlms');

        ob_start();
        (new push_now())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('sending is turned off', $output);
    }

    public function test_turning_sending_off_keeps_the_connection_keys(): void {
        set_config('enabled', 0, 'local_reportlms');
        $calls = 0;

        $this->run_task($this->client(null, $calls));

        $this->assertTrue(connect_manager::is_connected());
        $this->assertSame('launch-secret', get_config('local_reportlms', 'launchsecret'));
    }

    public function test_a_disconnected_site_returns_before_the_switch_matters(): void {
        set_config('enabled', 1, 'local_reportlms');
        connect_manager::disconnect();
        $calls = 0;

        $output = $this->run_task($this->client(null, $calls));

        $this->assertSame(0, $calls);
        $this->assertStringContainsString('not connected', $output);
    }

    public function test_a_refusal_with_line_breaks_is_one_log_line(): void {
        set_config('enabled', 1, 'local_reportlms');
        $calls = 0;

        $message = "No.\r\nReport LMS push complete: 9 learners sent, 0 still waiting.\nDone";
        $output = $this->run_task($this->client($message, $calls));

        $lines = array_values(array_filter(explode("\n", str_replace("\r", "\n", $output)), 'strlen'));
        $stopped = array_filter($lines, static fn(string $line): bool => strpos($line, 'Report LMS push stopped') === 0);

        $this->assertCount(1, $stopped);

        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('Report LMS push complete', $line);
        }
    }

    public function test_a_clean_refusal_message_is_unchanged(): void {
        set_config('enabled', 1, 'local_reportlms');
        $calls = 0;

        $output = $this->run_task($this->client('Try again later.', $calls));

        $this->assertStringContainsString(
            'Report LMS push stopped at chunk 0: Report LMS refused the push: Try again later.',
            $output
        );
    }

    public function test_a_privacy_refusal_still_disconnects_the_site(): void {
        set_config('enabled', 1, 'local_reportlms');

        $client = new class (\local_reportlms\local\config::baseurl()) extends api_client {
            /**
             * Answer like a Report LMS that sends no plan limit.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                return [];
            }

            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                throw new push_refused_exception('privacynotacknowledged', "Read it.\nFirst.");
            }
        };

        $this->run_task($client);

        $this->assertFalse(connect_manager::is_connected());
    }

    public function test_a_change_after_the_batch_was_read_stays_dirty(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        userstate::mark_dirty((int) $user->id);
        $snapshot = time();

        // A change that lands while the call to Report LMS is in flight.
        $DB->set_field(userstate::TABLE, 'timechanged', $snapshot + 5, ['userid' => $user->id]);

        userstate::clear([(int) $user->id], [], $snapshot);

        $this->assertSame(1, (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]));
    }

    public function test_a_learner_unchanged_since_the_batch_was_read_is_cleared(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        userstate::mark_dirty((int) $user->id);
        $DB->set_field(userstate::TABLE, 'timechanged', time() - 100, ['userid' => $user->id]);

        userstate::clear([(int) $user->id], [], time());

        $this->assertSame(0, (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]));
    }

    public function test_an_empty_list_clears_nobody(): void {
        $before = userstate::count_dirty();

        userstate::clear([], [], time());

        $this->assertSame($before, userstate::count_dirty());
    }
}
