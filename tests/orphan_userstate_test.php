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
 * Tracking rows for users missing from the user table.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\pseudonym;
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;
use local_reportlms\task\reconcile_userstate;

/**
 * A site restored from a database copy can track ids its user table never had.
 * Those used to be pushed as active learners and inflated the learner and
 * licence counts. They are now sent once as deleted and then removed.
 *
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\userstate
 * @covers \local_reportlms\local\batch_builder
 */
final class orphan_userstate_test extends \advanced_testcase {
    /** @var int A user id that exists in no user table. */
    private const MISSING = 987654;

    /** @var \stdClass */
    private $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');

        $this->user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->user->id, $course->id);
        userstate::mark_all_dirty();
    }

    /**
     * Add a tracking row for a user that does not exist.
     *
     * @param int $dirty
     */
    private function add_orphan(int $dirty): void {
        global $DB;

        $DB->insert_record(userstate::TABLE, (object) [
            'userid' => self::MISSING,
            'dirty' => $dirty,
            'timechanged' => time(),
            'timepushed' => 0,
        ]);
    }

    /**
     * A client that accepts everything and records the payloads.
     *
     * @return api_client
     */
    private function recording_client(): api_client {
        return new class (\local_reportlms\local\config::baseurl()) extends api_client {
            /** @var array Decoded payloads received. */
            public $payloads = [];

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
                $this->payloads[] = json_decode($rawbody, true);

                return ['batch_id' => 'b1'];
            }
        };
    }

    /**
     * Run one push and return the client that saw it.
     *
     * @return api_client
     */
    private function run_push(): api_client {
        $client = $this->recording_client();
        $task = new push_batch();
        $task->set_client($client);

        ob_start();
        $task->execute();
        ob_end_clean();

        return $client;
    }

    /**
     * The orphan goes out as deleted once, is removed, and the real user is untouched.
     */
    public function test_an_orphan_row_is_sent_as_deleted_once_then_removed(): void {
        global $DB;

        $this->add_orphan(1);
        $code = pseudonym::learner(self::MISSING);
        $realcode = pseudonym::learner((int) $this->user->id);

        $client = $this->run_push();

        $departures = [];
        $learners = [];
        foreach ($client->payloads as $payload) {
            $departures = array_merge($departures, $payload['departures'] ?? []);
            $learners = array_merge($learners, $payload['learners'] ?? []);
        }
        $this->assertSame([$code], $departures);
        $this->assertNotContains($code, $learners);
        $this->assertContains($realcode, $learners);

        $this->assertFalse($DB->record_exists(userstate::TABLE, ['userid' => self::MISSING]));
        $this->assertTrue($DB->record_exists(userstate::TABLE, ['userid' => $this->user->id]));

        // A second push does not mention it again.
        userstate::mark_all_dirty();
        $again = $this->run_push();
        foreach ($again->payloads as $payload) {
            $this->assertArrayNotHasKey('departures', $payload);
        }
    }

    /**
     * Nobody is orphaned on an ordinary site, so nothing is sent as deleted.
     */
    public function test_a_normal_user_is_not_a_departure(): void {
        $client = $this->run_push();

        $this->assertNotEmpty($client->payloads);
        foreach ($client->payloads as $payload) {
            $this->assertArrayNotHasKey('departures', $payload);
        }
    }

    /**
     * The reconcile marks orphans dirty and leaves the rest alone.
     */
    public function test_the_reconcile_marks_orphan_rows_dirty(): void {
        global $DB;

        $this->run_push();
        $this->add_orphan(0);
        $this->assertSame(0, (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => self::MISSING]));

        ob_start();
        (new reconcile_userstate())->execute();
        ob_end_clean();

        $this->assertSame(1, (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => self::MISSING]));

        $client = $this->run_push();
        $this->assertSame([pseudonym::learner(self::MISSING)], $client->payloads[0]['departures']);
        $this->assertFalse($DB->record_exists(userstate::TABLE, ['userid' => self::MISSING]));
    }

    /**
     * Marking is batched and terminates with more orphans than the batch size.
     */
    public function test_marking_orphans_runs_in_batches(): void {
        global $DB;

        foreach ([900001, 900002, 900003] as $id) {
            $DB->insert_record(userstate::TABLE, (object) [
                'userid' => $id, 'dirty' => 0, 'timechanged' => time(), 'timepushed' => 0,
            ]);
        }

        $this->assertSame(3, userstate::mark_orphans_dirty(2));
        $this->assertSame(0, userstate::mark_orphans_dirty(2));
    }
}
