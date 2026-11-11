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
 * Tests for the plan limit: only the people inside the plan are sent.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\batch_builder;
use local_reportlms\local\collector\org;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\mapping;
use local_reportlms\local\plan_limit;
use local_reportlms\local\pseudonym;
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;

/**
 * Who takes a place, what is sent for whom, and how the limit is kept.
 *
 * A place goes to every person who is not deleted and not a site
 * administrator, suspended people included, in user id order. Everybody else
 * in the organisation is listed but marked held, and their facts wait.
 *
 * @covers \local_reportlms\local\plan_limit
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\userstate::get_dirty_batch
 * @covers \local_reportlms\local\userstate::count_held
 * @covers \local_reportlms\local\batch_builder
 * @covers \local_reportlms\local\collector\org
 * @covers \local_reportlms\local\connect_manager
 */
final class plan_limit_test extends \advanced_testcase {
    /** @var int Held people in the large test: more than one run takes. */
    private const MANY = 25010;

    /** @var \stdClass The course everybody is enrolled on. */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('launchsecret', 'launch-secret', 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        // Recent, so no test sends a heartbeat it did not ask for.
        set_config('lastpush', time(), 'local_reportlms');

        mapping::reset();

        $this->course = $this->getDataGenerator()->create_course();
    }

    public function test_with_no_limit_everybody_is_sent_as_today(): void {
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->client([]);

        $output = $this->run_task($client);

        $this->assertSame($this->pids($people), $this->sent_learners($client));
        $org = $this->sent_org($client);
        $this->assertNull($org['limit_applied']);
        foreach ($org['attrs'] as $attr) {
            $this->assertArrayNotHasKey('held', $attr);
        }
        $this->assertSame(0, userstate::count_dirty());
        $this->assertStringNotContainsString('held by the plan', $output);
        $this->assertNull(plan_limit::stored());
    }

    public function test_a_limit_below_the_headcount_sends_the_first_places_only(): void {
        $people = $this->people(5);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(2));

        $output = $this->run_task($client);

        $inside = array_slice($people, 0, 2);
        $outside = array_slice($people, 2);

        $this->assertSame(2, plan_limit::stored());
        $this->assertSame($this->pids($inside), $this->sent_learners($client));

        $org = $this->sent_org($client);
        $this->assertSame(2, $org['limit_applied']);
        $held = $this->held_pids($org);
        $this->assertSame($this->pids($outside), $held);

        // Listed, so the headcount stays true, but in no unit.
        $attrs = array_column($org['attrs'], 'learner_pid');
        foreach ($this->pids($outside) as $pid) {
            $this->assertContains($pid, $attrs);
            $this->assertNotContains($pid, array_column($org['members'], 'learner_pid'));
        }
        foreach ($this->pids($inside) as $pid) {
            $this->assertContains($pid, array_column($org['members'], 'learner_pid'));
        }

        // Held people keep waiting, so their history goes once the plan grows.
        foreach ($outside as $user) {
            $this->assertTrue($this->dirty($user));
        }
        foreach ($inside as $user) {
            $this->assertFalse($this->dirty($user));
        }

        $this->assertStringContainsString('0 still waiting, 3 held by the plan', $output);
    }

    public function test_an_administrator_takes_no_place_and_is_still_sent(): void {
        $admin = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($admin->id, $this->course->id);
        $people = $this->people(3);
        set_config('siteadmins', '2,' . $admin->id);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(2));

        $this->run_task($client);

        $sent = $this->sent_learners($client);
        $this->assertContains(pseudonym::learner((int) $admin->id), $sent);
        $this->assertContains(pseudonym::learner((int) $people[0]->id), $sent);
        $this->assertContains(pseudonym::learner((int) $people[1]->id), $sent);
        $this->assertNotContains(pseudonym::learner((int) $people[2]->id), $sent);
        $this->assertSame([pseudonym::learner((int) $people[2]->id)], $this->held_pids($this->sent_org($client)));
    }

    public function test_an_administrator_above_the_cut_off_is_never_held(): void {
        $people = $this->people(3);
        $admin = $this->getDataGenerator()->create_user();
        set_config('siteadmins', '2,' . $admin->id);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(1));

        $this->run_task($client);

        $this->assertContains(pseudonym::learner((int) $admin->id), $this->sent_learners($client));
        $this->assertNotContains(pseudonym::learner((int) $admin->id), $this->held_pids($this->sent_org($client)));
        $this->assertFalse($this->dirty($admin));
        $this->assertTrue($this->dirty($people[2]));
    }

    public function test_raising_the_limit_sends_the_held_people_on_the_next_run(): void {
        $people = $this->people(5);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(2)));

        $client = $this->client($this->limits(5));
        $this->run_task($client);

        $this->assertSame($this->pids(array_slice($people, 2)), $this->sent_learners($client));
        $org = $this->sent_org($client);
        $this->assertNotNull($org, 'The held marks changed, so the organisation goes again.');
        $this->assertSame(5, $org['limit_applied']);
        $this->assertSame([], $this->held_pids($org));
        $this->assertSame(0, userstate::count_dirty());
    }

    public function test_switching_the_limit_off_sends_everybody_held(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(1)));

        $client = $this->client($this->limits(null));
        $this->run_task($client);

        $this->assertNull(plan_limit::stored());
        $this->assertSame($this->pids(array_slice($people, 1)), $this->sent_learners($client));
        $this->assertNull($this->sent_org($client)['limit_applied']);
        $this->assertSame(0, userstate::count_dirty());
    }

    public function test_a_deleted_person_frees_a_place(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(2)));
        $this->assertTrue($this->dirty($people[2]));

        delete_user($people[0]);
        $client = $this->client($this->limits(2));
        $this->run_task($client);

        $departures = [];
        foreach ($client->payloads as $payload) {
            $departures = array_merge($departures, $payload['departures'] ?? []);
        }
        $this->assertSame([pseudonym::learner((int) $people[0]->id)], $departures);
        $this->assertContains(pseudonym::learner((int) $people[2]->id), $this->sent_learners($client));
        $this->assertSame([], $this->held_pids($this->sent_org($client)));
        $this->assertFalse($this->dirty($people[2]));
    }

    public function test_the_queue_is_filtered_before_it_is_cut(): void {
        global $DB;

        $inside = $this->people(1);
        $held = $this->people(30, false);
        $admin = $this->getDataGenerator()->create_user();
        $leaver = $this->getDataGenerator()->create_user();
        set_config('siteadmins', '2,' . $admin->id);
        delete_user($leaver);
        $orphan = (int) $leaver->id + 1000;
        $DB->insert_record(userstate::TABLE, (object) [
            'userid' => $orphan,
            'dirty' => 1,
            'timechanged' => time(),
            'timepushed' => 0,
        ]);
        userstate::mark_all_dirty();
        set_config(plan_limit::USERS, 1, 'local_reportlms');

        $plan = plan_limit::current();
        $batch = userstate::get_dirty_batch(10, $plan->cutoff());

        $this->assertSame(
            [(int) $inside[0]->id, (int) $admin->id, (int) $leaver->id, $orphan],
            $batch,
            'Thirty held people in front of them take none of the ten places.'
        );
        $this->assertSame(30, userstate::count_held($plan->cutoff()));
        $this->assertCount(30, $held);
    }

    public function test_an_administrator_and_a_departure_go_out_past_a_run_full_of_held_people(): void {
        global $CFG, $DB;

        $this->assertGreaterThan(push_batch::MAX_USERS_PER_RUN, self::MANY);

        $inside = $this->people(2);

        $now = time();
        $rows = [];
        for ($i = 0; $i < self::MANY; $i++) {
            $rows[] = [
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => 'held' . $i,
                'firstname' => 'Held',
                'lastname' => (string) $i,
                'email' => 'held' . $i . '@example.com',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records('user', $rows);

        $admin = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($admin->id, $this->course->id);
        set_config('siteadmins', '2,' . $admin->id);
        $leaver = $this->getDataGenerator()->create_user();
        delete_user($leaver);
        userstate::mark_all_dirty();

        $client = $this->client($this->limits(2));
        $output = $this->run_task($client);

        $this->assertSame(
            array_merge($this->pids($inside), [pseudonym::learner((int) $admin->id)]),
            $this->sent_learners($client)
        );
        $this->assertSame([pseudonym::learner((int) $leaver->id)], $client->payloads[0]['departures']);
        $this->assertCount(self::MANY, $this->held_pids($this->sent_org($client)));
        $this->assertSame(self::MANY, userstate::count_dirty());
        $this->assertSame(self::MANY, userstate::count_held(plan_limit::current()->cutoff()));
        $this->assertStringContainsString('0 still waiting, ' . self::MANY . ' held by the plan', $output);

        $this->assertStringContainsString(
            'Your plan covers 2 people. This site has 25,012. Data for 25,010 is not sent.',
            plan_limit::notice()
        );
    }

    public function test_the_org_hash_follows_the_limit(): void {
        $people = $this->people(3);

        $today = 'h_' . org::state_hash(org::collect());
        $none = batch_builder::org_section_if_changed(plan_limit::none());
        $this->assertSame($today, $none['org_state_hash'], 'No limit hashes as before, so an upgrade resends nothing.');
        $this->assertNull($none['limit_applied']);

        $two = batch_builder::org_section_if_changed(new plan_limit(2, (int) $people[1]->id));
        $twoagain = batch_builder::org_section_if_changed(new plan_limit(2, (int) $people[1]->id));
        $three = batch_builder::org_section_if_changed(new plan_limit(3, null));
        $five = batch_builder::org_section_if_changed(new plan_limit(5, null));

        $this->assertSame($two['org_state_hash'], $twoagain['org_state_hash']);
        $this->assertNotSame($none['org_state_hash'], $two['org_state_hash']);
        $this->assertNotSame($none['org_state_hash'], $three['org_state_hash'], 'A limit nobody is over still travels.');
        $this->assertNotSame($three['org_state_hash'], $five['org_state_hash']);
        $this->assertNotSame($two['org_state_hash'], $three['org_state_hash']);
        $this->assertSame(3, $three['limit_applied']);
    }

    public function test_disconnecting_clears_the_limit(): void {
        plan_limit::store_reply($this->limits(1500));
        $this->assertSame(1500, plan_limit::stored());

        connect_manager::disconnect();

        $this->assertNull(plan_limit::stored());
    }

    public function test_a_reply_without_limits_keeps_the_stored_one(): void {
        plan_limit::store_reply($this->limits(1500));

        plan_limit::store_reply(['tier' => 'starter']);
        $this->assertSame(1500, plan_limit::stored());

        plan_limit::store_reply(['limits' => 'nonsense']);
        $this->assertSame(1500, plan_limit::stored());

        plan_limit::store_reply(['limits' => ['users' => -4]]);
        $this->assertSame(1500, plan_limit::stored());

        plan_limit::store_reply($this->limits(0));
        $this->assertSame(0, plan_limit::stored());

        plan_limit::store_reply($this->limits(null));
        $this->assertNull(plan_limit::stored());
    }

    public function test_a_health_reply_without_limits_keeps_the_stored_one_in_the_task(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        plan_limit::store_reply($this->limits(1));
        $client = $this->client([]);

        $this->run_task($client);

        $this->assertSame(1, plan_limit::stored());
        $this->assertSame($this->pids(array_slice($people, 0, 1)), $this->sent_learners($client));
    }

    public function test_a_failed_health_call_keeps_the_stored_limit_and_still_pushes(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        plan_limit::store_reply($this->limits(2));
        $client = $this->client(null);

        $output = $this->run_task($client);

        $this->assertSame(1, $client->healthcalls);
        $this->assertSame(2, plan_limit::stored());
        $this->assertSame($this->pids(array_slice($people, 0, 2)), $this->sent_learners($client));
        $this->assertStringContainsString('keeping the stored plan limit', $output);
    }

    public function test_the_connect_reply_and_the_push_reply_store_the_limit(): void {
        $connector = new class (config::baseurl()) extends api_client {
            /**
             * Answer a connect with keys and a plan limit.
             *
             * @param string $siteurl
             * @param string $verificationtoken
             * @param int $acknowledgedat
             * @param string $contactemail
             * @return array
             */
            public function connect(
                string $siteurl,
                string $verificationtoken,
                int $acknowledgedat = 0,
                string $contactemail = ''
            ): array {
                return [
                    'environment_id' => 'env-1',
                    'api_key' => 'key-1',
                    'signing_secret' => 'secret-1',
                    'launch_secret' => 'launch-1',
                    'limits' => ['users' => 1500, 'courses' => null, 'activities' => null],
                ];
            }
        };
        (new connect_manager($connector))->connect();
        $this->assertSame(1500, plan_limit::stored());

        $this->people(1);
        userstate::mark_all_dirty();
        $client = $this->client([]);
        $client->reply = $this->limits(7);
        $this->run_task($client);

        $this->assertSame(7, plan_limit::stored());
    }

    public function test_a_fresh_hash_mismatch_is_left_alone(): void {
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config('lastorghashat', time() - 600, 'local_reportlms');

        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => 'h_def']));
        $this->assertSame('abc', get_config('local_reportlms', 'lastorghash'));
    }

    public function test_an_old_hash_mismatch_sends_the_organisation_again(): void {
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config('lastorghashat', time() - 2 * HOURSECS, 'local_reportlms');

        $this->assertTrue(plan_limit::follow_health(['org_state_hash_applied' => 'h_def']));
        $this->assertFalse(get_config('local_reportlms', 'lastorghash'));
    }

    public function test_a_matching_or_missing_applied_hash_is_left_alone(): void {
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config('lastorghashat', time() - 2 * HOURSECS, 'local_reportlms');

        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => 'h_abc']));
        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => null]));
        $this->assertFalse(plan_limit::follow_health([]));
        $this->assertSame('abc', get_config('local_reportlms', 'lastorghash'));
    }

    public function test_the_task_resends_an_organisation_the_app_lost(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        set_config('lastorghashat', time() - 2 * HOURSECS, 'local_reportlms');
        $client = $this->client(['org_state_hash_applied' => 'h_somethingelse']);

        $this->run_task($client);

        $this->assertCount(1, $client->payloads);
        $this->assertArrayHasKey('org', $client->payloads[0]);
        $this->assertSame(
            substr($client->payloads[0]['org']['org_state_hash'], 2),
            get_config('local_reportlms', 'lastorghash')
        );
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', 'lastorghashat'));
    }

    public function test_a_quiet_site_sends_a_heartbeat_after_a_day(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        set_config('lastpush', time() - DAYSECS - HOURSECS, 'local_reportlms');
        $client = $this->client([]);

        $output = $this->run_task($client);

        $this->assertCount(1, $client->payloads);
        $this->assertSame([], $client->payloads[0]['facts']);
        $this->assertArrayNotHasKey('org', $client->payloads[0]);
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', 'lastpush'));
        $this->assertStringContainsString('heartbeat', $output);
    }

    public function test_a_quiet_site_pushed_recently_sends_nothing(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        set_config('lastpush', time() - HOURSECS, 'local_reportlms');
        $client = $this->client([]);

        $output = $this->run_task($client);

        $this->assertSame([], $client->payloads);
        $this->assertStringContainsString('nothing to push', $output);
    }

    public function test_the_notice_names_the_numbers_only_when_somebody_is_held(): void {
        $this->people(5);

        $this->assertNull(plan_limit::notice(), 'No limit, nothing to say.');

        plan_limit::store_reply($this->limits(5));
        $this->assertNull(plan_limit::notice(), 'Everybody fits.');

        plan_limit::store_reply($this->limits(2));
        $notice = plan_limit::notice();
        $this->assertStringContainsString('Your plan covers 2 people. This site has 5. Data for 3 is not sent.', $notice);
        $this->assertStringContainsString('https://reportlms.com/pricing', $notice);
    }

    /**
     * Create people enrolled on the course, in id order.
     *
     * @param int $count
     * @param bool $enrol
     * @return array<int, \stdClass>
     */
    private function people(int $count, bool $enrol = true): array {
        $people = [];

        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();

            if ($enrol) {
                $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
            }

            $people[] = $user;
        }

        return $people;
    }

    /**
     * The limits object the app sends.
     *
     * @param int|null $users
     * @return array
     */
    private function limits(?int $users): array {
        return ['limits' => ['users' => $users, 'courses' => null, 'activities' => null]];
    }

    /**
     * A client whose health call answers with the given reply, or fails when it is null.
     *
     * @param array|null $health
     * @return api_client
     */
    private function client(?array $health): api_client {
        $client = new class (config::baseurl()) extends api_client {
            /** @var array|null What the health call answers; null fails it. */
            public $health = [];
            /** @var array What a push answers besides its batch id. */
            public $reply = [];
            /** @var array Decoded payloads accepted. */
            public $payloads = [];
            /** @var int Health calls made. */
            public $healthcalls = 0;

            /**
             * Answer the health call, or fail it.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                $this->healthcalls++;

                if ($this->health === null) {
                    throw new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'unreachable');
                }

                return $this->health;
            }

            /**
             * Keep the payload and accept it.
             *
             * @param string $pushpath
             * @param string $rawbody
             * @param string $apikey
             * @param string $signingsecret
             * @param string $idempotencykey
             * @return array
             */
            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                $this->payloads[] = json_decode($rawbody, true);

                return ['batch_id' => 'b-test'] + $this->reply;
            }
        };
        $client->health = $health;

        return $client;
    }

    /**
     * Run the scheduled push and return what it said.
     *
     * @param api_client $client
     * @return string
     */
    private function run_task(api_client $client): string {
        $task = new push_batch();
        $task->set_client($client);

        ob_start();
        $task->execute();

        return ob_get_clean();
    }

    /**
     * Every learner a client was sent, in order.
     *
     * @param api_client $client
     * @return array<int, string>
     */
    private function sent_learners(api_client $client): array {
        $learners = [];

        foreach ($client->payloads as $payload) {
            $learners = array_merge($learners, $payload['learners'] ?? []);
        }

        return $learners;
    }

    /**
     * The organisation section a client was sent, or null.
     *
     * @param api_client $client
     * @return array|null
     */
    private function sent_org(api_client $client): ?array {
        foreach ($client->payloads as $payload) {
            if (isset($payload['org'])) {
                return $payload['org'];
            }
        }

        return null;
    }

    /**
     * The people an organisation section marks held.
     *
     * @param array $org
     * @return array<int, string>
     */
    private function held_pids(array $org): array {
        return array_values(array_column(
            array_filter($org['attrs'], static fn(array $attr): bool => !empty($attr['held'])),
            'learner_pid'
        ));
    }

    /**
     * Coded ids of these people.
     *
     * @param \stdClass[] $people
     * @return array<int, string>
     */
    private function pids(array $people): array {
        return array_values(array_map(static fn($user) => pseudonym::learner((int) $user->id), $people));
    }

    /**
     * Whether this person is waiting to be sent.
     *
     * @param \stdClass $user
     * @return bool
     */
    private function dirty(\stdClass $user): bool {
        global $DB;

        return (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]) === 1;
    }

    /**
     * Record everything as delivered, as the push task does after a 202.
     *
     * @return void
     */
    private function pretend_everything_was_pushed(): void {
        global $DB;

        $org = batch_builder::org_section_if_changed();
        if ($org !== null) {
            set_config('lastorghash', substr($org['org_state_hash'], 2), 'local_reportlms');
        }

        $dims = batch_builder::dimensions_section_if_changed();
        if ($dims !== null) {
            set_config('lastdimhash', $dims['hash'], 'local_reportlms');
        }

        set_config('lastsettingshash', sha1(json_encode(batch_builder::settings_section())), 'local_reportlms');
        $DB->set_field(userstate::TABLE, 'dirty', 0, []);
    }
}
