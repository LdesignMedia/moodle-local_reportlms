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

use local_reportlms\local\batch_builder;
use local_reportlms\local\connect_manager;
use local_reportlms\local\plan_limit;
use local_reportlms\local\pseudonym;
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/plan_limit_testing.php');

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
    use plan_limit_testing;

    /** @var int Held people in the large test: more than one run takes. */
    private const MANY = 25010;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->connect_site();
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
        $this->assertSame($this->pids($outside), $this->held_pids($org));

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
        $this->assertGreaterThan(push_batch::MAX_USERS_PER_RUN, self::MANY);

        $inside = $this->people(2);
        $this->assertCount(self::MANY, $this->bulk_users(self::MANY, 'held'));

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
            'Your plan covers 2 people. This site has 25,012 people. Reports show the first 2. '
                . 'For the other 25,010 only a coded id, the account status and login method '
                . '(and any unit they manage) are sent, no learning data.',
            plan_limit::notice()
        );
    }

    public function test_the_org_hash_follows_the_limit(): void {
        $people = $this->people(3);

        $none = batch_builder::org_section_if_changed(plan_limit::none());
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

        plan_limit::store_reply(['limits' => ['users' => 2147483648]]);
        $this->assertSame(1500, plan_limit::stored(), 'Above what Report LMS itself accepts back.');

        plan_limit::store_reply($this->limits(2147483647));
        $this->assertSame(2147483647, plan_limit::stored());

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
        (new connect_manager($this->connector()))->connect();
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
        // No key at all is an older Report LMS, which never says.
        $this->assertFalse(plan_limit::follow_health([]));
        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => 42]));
        $this->assertSame('abc', get_config('local_reportlms', 'lastorghash'));
    }

    public function test_an_explicit_null_means_the_app_never_applied_one(): void {
        $never = ['org_state_hash_applied' => null];

        // Fresh: the batch may still be in the queue.
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config('lastorghashat', time() - 600, 'local_reportlms');
        $this->assertFalse(plan_limit::follow_health($never));
        $this->assertSame('abc', get_config('local_reportlms', 'lastorghash'));

        // Over an hour: sent again.
        set_config('lastorghashat', time() - 2 * HOURSECS, 'local_reportlms');
        $this->assertTrue(plan_limit::follow_health($never));
        $this->assertFalse(get_config('local_reportlms', 'lastorghash'));
        $this->assertSame(1, plan_limit::org_resends());

        // And the same backoff: the next resend waits two hours.
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config('lastorghashat', time() - 90 * MINSECS, 'local_reportlms');
        $this->assertFalse(plan_limit::follow_health($never));
        set_config('lastorghashat', time() - 2 * HOURSECS - MINSECS, 'local_reportlms');
        $this->assertTrue(plan_limit::follow_health($never));
        $this->assertSame(2, plan_limit::org_resends());
    }

    public function test_an_explicit_null_with_nothing_saved_sends_nothing_again(): void {
        // Nothing was accepted yet, so nothing can have been lost.
        unset_config('lastorghash', 'local_reportlms');
        set_config('lastorghashat', time() - 2 * HOURSECS, 'local_reportlms');

        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => null]));
        $this->assertSame(0, plan_limit::org_resends());
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

    public function test_a_heartbeat_that_carries_a_change_is_not_logged_as_a_heartbeat(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        set_config('lastpush', time() - DAYSECS - HOURSECS, 'local_reportlms');
        set_config('deadline_source', 'period', 'local_reportlms');
        set_config('deadline_days', 30, 'local_reportlms');
        $client = $this->client([]);

        $output = $this->run_task($client);

        $this->assertCount(1, $client->payloads);
        $this->assertStringNotContainsString('heartbeat', $output);
        $this->assertStringContainsString('no learner changed', $output);
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
}
