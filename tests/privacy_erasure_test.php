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
 * Tests for the privacy deletions telling Report LMS.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use local_reportlms\local\api_client;
use local_reportlms\local\erasure;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;
use local_reportlms\local\userstate;
use local_reportlms\privacy\provider;

/**
 * A privacy deletion names the people to Report LMS before the rows go.
 *
 * @covers \local_reportlms\privacy\provider
 * @covers \local_reportlms\local\erasure
 * @covers \local_reportlms\local\batch_builder
 */
final class privacy_erasure_test extends \advanced_testcase {
    /** @var array<int, array> Payloads the stand-in client received. */
    private $sent = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        mapping::reset();
        $this->sent = [];
        erasure::set_client($this->client(false));
    }

    protected function tearDown(): void {
        erasure::set_client(null);
        parent::tearDown();
    }

    /**
     * A stand-in for Report LMS that records what it is sent.
     *
     * @param bool $refuse Whether to refuse every push.
     *
     * @return api_client
     */
    private function client(bool $refuse): api_client {
        $store = &$this->sent;

        return new class (\local_reportlms\local\config::baseurl(), $refuse, $store) extends api_client {
            /** @var bool */
            private $refuse;
            /** @var array */
            private $store;

            public function __construct(string $baseurl, bool $refuse, array &$store) {
                parent::__construct($baseurl);
                $this->refuse = $refuse;
                $this->store = &$store;
            }

            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                if ($this->refuse) {
                    throw new \moodle_exception('error:pushfailed', 'local_reportlms', '', 'down');
                }

                $this->store[] = json_decode($rawbody, true);

                return ['batch_id' => 'b1'];
            }
        };
    }

    /**
     * A living person with a row in each of the three tables.
     *
     * @return \stdClass
     */
    private function tracked_person(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $nodeid = mapping::save_node('Unit ' . $user->id, 'department');

        userstate::mark_dirty((int) $user->id);
        mapping::place((int) $user->id, $nodeid);
        mapping::add_manager($nodeid, (int) $user->id, null);

        return $user;
    }

    /**
     * Rows held for a person, over the three tables.
     *
     * @param \stdClass $user
     *
     * @return int
     */
    private function rows_for(\stdClass $user): int {
        global $DB;

        return $DB->count_records('local_reportlms_userstate', ['userid' => $user->id])
            + $DB->count_records('local_reportlms_member', ['userid' => $user->id])
            + $DB->count_records('local_reportlms_manager', ['userid' => $user->id]);
    }

    public function test_deleting_one_user_sends_the_departure_and_then_deletes(): void {
        $user = $this->tracked_person();
        $other = $this->tracked_person();
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($user, 'local_reportlms', [$context->id]));

        $this->assertCount(1, $this->sent);
        $this->assertSame([pseudonym::learner((int) $user->id)], $this->sent[0]['departures']);
        $this->assertSame(0, $this->rows_for($user));
        $this->assertGreaterThan(0, $this->rows_for($other));
    }

    public function test_deleting_a_list_of_users_sends_one_payload_for_all_of_them(): void {
        $first = $this->tracked_person();
        $second = $this->tracked_person();
        $context = \context_system::instance();

        provider::delete_data_for_users(
            new approved_userlist($context, 'local_reportlms', [(int) $first->id, (int) $second->id])
        );

        $this->assertCount(1, $this->sent);
        $this->assertEqualsCanonicalizing(
            [pseudonym::learner((int) $first->id), pseudonym::learner((int) $second->id)],
            $this->sent[0]['departures']
        );
        $this->assertSame(0, $this->rows_for($first) + $this->rows_for($second));
    }

    public function test_the_person_is_named_although_the_account_is_alive(): void {
        $user = $this->tracked_person();

        provider::delete_data_for_users(
            new approved_userlist(\context_system::instance(), 'local_reportlms', [(int) $user->id])
        );

        $this->assertSame(0, (int) $user->deleted);
        $this->assertContains(pseudonym::learner((int) $user->id), $this->sent[0]['departures']);
    }

    public function test_a_failed_push_leaves_the_rows_of_a_user_delete(): void {
        $user = $this->tracked_person();
        $before = $this->rows_for($user);
        erasure::set_client($this->client(true));

        try {
            provider::delete_data_for_users(
                new approved_userlist(\context_system::instance(), 'local_reportlms', [(int) $user->id])
            );
            $this->fail('The refusal should have reached the caller.');
        } catch (\moodle_exception $e) {
            $this->assertSame($before, $this->rows_for($user));
        }
    }

    public function test_an_empty_list_deletes_nothing_and_sends_nothing(): void {
        $user = $this->tracked_person();
        $before = $this->rows_for($user);

        provider::delete_data_for_users(new approved_userlist(\context_system::instance(), 'local_reportlms', []));

        $this->assertSame([], $this->sent);
        $this->assertSame($before, $this->rows_for($user));
    }

    public function test_a_context_that_is_not_the_system_one_does_nothing(): void {
        $user = $this->tracked_person();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $before = $this->rows_for($user);

        provider::delete_data_for_users(new approved_userlist($context, 'local_reportlms', [(int) $user->id]));
        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame([], $this->sent);
        $this->assertSame($before, $this->rows_for($user));
    }

    public function test_the_site_wide_purge_sends_every_person_it_holds_and_then_deletes(): void {
        global $DB;

        $first = $this->tracked_person();
        $second = $this->tracked_person();

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertCount(1, $this->sent);
        $this->assertEqualsCanonicalizing(
            [pseudonym::learner((int) $first->id), pseudonym::learner((int) $second->id)],
            $this->sent[0]['departures']
        );
        $this->assertSame(0, $DB->count_records('local_reportlms_userstate'));
        $this->assertSame(0, $DB->count_records('local_reportlms_member'));
        $this->assertSame(0, $DB->count_records('local_reportlms_manager'));
    }

    public function test_a_failed_push_leaves_the_rows_of_the_purge(): void {
        global $DB;

        $this->tracked_person();
        erasure::set_client($this->client(true));

        try {
            provider::delete_data_for_all_users_in_context(\context_system::instance());
            $this->fail('The refusal should have reached the caller.');
        } catch (\moodle_exception $e) {
            $this->assertGreaterThan(0, $DB->count_records('local_reportlms_userstate'));
            $this->assertGreaterThan(0, $DB->count_records('local_reportlms_member'));
            $this->assertGreaterThan(0, $DB->count_records('local_reportlms_manager'));
        }
    }

    public function test_a_site_that_was_never_connected_has_nothing_to_tell(): void {
        $user = $this->tracked_person();
        \local_reportlms\local\connect_manager::disconnect();

        provider::delete_data_for_users(
            new approved_userlist(\context_system::instance(), 'local_reportlms', [(int) $user->id])
        );

        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->rows_for($user));
    }
}
