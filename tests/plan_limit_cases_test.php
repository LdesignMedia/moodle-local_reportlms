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
 * Tests for the edges of the plan limit: who takes a place, and what never leaves.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms;

use local_reportlms\local\batch_builder;
use local_reportlms\local\collector\org;
use local_reportlms\local\erasure;
use local_reportlms\local\plan_limit;
use local_reportlms\local\pseudonym;
use local_reportlms\local\userstate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/plan_limit_testing.php');

/**
 * Limit 0, suspended people, administrators coming and going, upgrades, and
 * the promise that a held person's results never leave.
 *
 * @covers \local_reportlms\local\plan_limit
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\userstate::get_dirty_batch
 * @covers \local_reportlms\local\collector\org
 * @covers \local_reportlms\local\batch_builder
 * @covers \local_reportlms\local\erasure
 */
final class plan_limit_cases_test extends \advanced_testcase {
    use plan_limit_testing;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->connect_site();
    }

    protected function tearDown(): void {
        erasure::set_client(null);
        parent::tearDown();
    }

    public function test_a_limit_of_zero_holds_everybody_who_takes_a_place(): void {
        $people = $this->people(3);
        $admin = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($admin->id, $this->course->id);
        set_config('siteadmins', '2,' . $admin->id);
        $leaver = $this->getDataGenerator()->create_user();
        delete_user($leaver);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(0));

        $output = $this->run_task($client);

        $this->assertSame(0, plan_limit::stored());
        $this->assertSame([pseudonym::learner((int) $admin->id)], $this->sent_learners($client));
        $this->assertSame([pseudonym::learner((int) $leaver->id)], $client->payloads[0]['departures']);
        $org = $this->sent_org($client);
        $this->assertSame(0, $org['limit_applied']);
        $this->assertSame($this->pids($people), $this->held_pids($org));
        $this->assertSame([], array_intersect($this->pids($people), $this->sent_fact_pids($client)));
        $this->assertStringContainsString('0 still waiting, 3 held by the plan', $output);
        $this->assertStringContainsString(
            'Your plan covers 0 people. This site has 3 people. Reports show nobody. '
                . 'For everybody only a coded id and the account status are sent, no learning data.',
            plan_limit::notice()
        );
    }

    public function test_a_suspended_person_takes_a_place(): void {
        $suspended = $this->people(1, true, ['suspended' => 1]);
        $others = $this->people(2);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(1));

        $this->run_task($client);

        $this->assertSame($this->pids($suspended), $this->sent_learners($client));
        $this->assertSame($this->pids($others), $this->held_pids($this->sent_org($client)));
    }

    public function test_the_cut_off_follows_the_administrators(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(1)));
        $this->assertTrue($this->dirty($people[1]));

        // The person inside becomes an administrator: they take no place any
        // more, so the next person moves inside and goes out.
        set_config('siteadmins', '2,' . $people[0]->id);
        $client = $this->client($this->limits(1));
        $this->run_task($client);

        $this->assertSame($this->pids([$people[0], $people[1]]), $this->sent_learners($client));
        $this->assertSame([pseudonym::learner((int) $people[0]->id)], $client->payloads[0]['uncounted']);
        $this->assertSame($this->pids([$people[2]]), $this->held_pids($this->sent_org($client)));
        $this->assertTrue($this->dirty($people[2]));

        // A held person who becomes an administrator goes out at once.
        set_config('siteadmins', '2,' . $people[0]->id . ',' . $people[2]->id);
        $client = $this->client($this->limits(1));
        $this->run_task($client);

        $this->assertSame($this->pids([$people[2]]), $this->sent_learners($client));
        $this->assertSame([], $this->held_pids($this->sent_org($client)));
        $this->assertSame(0, userstate::count_dirty());
    }

    public function test_a_former_administrator_above_the_cut_off_becomes_held(): void {
        $people = $this->people(2);
        $admin = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($admin->id, $this->course->id);
        set_config('siteadmins', '2,' . $admin->id);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(1)));
        $this->assertFalse($this->dirty($admin));

        set_config('siteadmins', '2');
        $client = $this->client($this->limits(1));
        $this->run_task($client);

        $this->assertNotContains(pseudonym::learner((int) $admin->id), $this->sent_learners($client));
        $this->assertContains(pseudonym::learner((int) $admin->id), $this->held_pids($this->sent_org($client)));
        $this->assertTrue($this->dirty($admin), 'Held, so still waiting for the plan to grow.');
        $this->assertTrue($this->dirty($people[1]));
    }

    public function test_an_empty_administrator_list_still_counts_and_filters(): void {
        set_config('siteadmins', '');
        $people = $this->people(3);
        userstate::mark_all_dirty();
        set_config(plan_limit::USERS, 2, 'local_reportlms');

        $plan = plan_limit::current();

        $this->assertSame((int) $people[1]->id, $plan->cutoff());
        $this->assertSame(3, plan_limit::headcount());
        $this->assertSame([(int) $people[0]->id, (int) $people[1]->id], userstate::get_dirty_batch(10, $plan->cutoff()));
        $this->assertSame(1, userstate::count_held($plan->cutoff()));

        $client = $this->client($this->limits(2));
        $this->run_task($client);

        $this->assertSame($this->pids(array_slice($people, 0, 2)), $this->sent_learners($client));
    }

    public function test_user_two_takes_no_place_when_not_an_administrator(): void {
        $admin = $this->getDataGenerator()->create_user();
        $people = $this->people(2);
        set_config('siteadmins', (string) $admin->id);
        set_config(plan_limit::USERS, 1, 'local_reportlms');

        $this->assertSame((int) $people[0]->id, plan_limit::current()->cutoff());
        $this->assertSame(2, plan_limit::headcount());
        $this->assertNotContains(2, array_map(static fn($attr) => (int) $attr->userid, org::collect()->attrs));
    }

    public function test_an_upgraded_site_without_a_saved_time_resends_a_lost_organisation_at_once(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        unset_config(plan_limit::ORG_SENT_AT, 'local_reportlms');
        $client = $this->client(['org_state_hash_applied' => 'h_somethingelse']);

        $this->run_task($client);

        $this->assertCount(1, $client->payloads);
        $this->assertArrayHasKey('org', $client->payloads[0]);
    }

    public function test_an_upgraded_site_whose_organisation_arrived_sends_none(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        unset_config(plan_limit::ORG_SENT_AT, 'local_reportlms');
        $client = $this->client(['org_state_hash_applied' => 'h_' . get_config('local_reportlms', 'lastorghash')]);

        $this->run_task($client);

        $this->assertSame([], $client->payloads);
    }

    public function test_without_a_limit_the_organisation_hashes_as_in_0_8(): void {
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);

        // The organisation state exactly as 0.8.11 built it for this site: one
        // Unassigned node, everybody in it, nobody held, no limit.
        $golden = sprintf(
            '{"nodes":[{"key":"department:Unassigned","parent":null,"kind":"department"}],'
                . '"members":[{"userid":%1$d,"nodekey":"department:Unassigned"},'
                . '{"userid":%2$d,"nodekey":"department:Unassigned"}],'
                . '"managers":[],'
                . '"attrs":[{"userid":%1$d,"auth":"manual","active":true,"function":null},'
                . '{"userid":%2$d,"auth":"manual","active":false,"function":null}]}',
            (int) $active->id,
            (int) $suspended->id
        );

        $this->assertSame($golden, json_encode(org::collect(plan_limit::none())));
        $this->assertSame(sha1($golden), org::state_hash(org::collect()));

        $section = batch_builder::org_section_if_changed(plan_limit::none());
        $this->assertSame('h_' . sha1($golden), $section['org_state_hash']);
        $this->assertSame(['org_state_hash', 'nodes', 'members', 'managers', 'attrs', 'limit_applied'], array_keys($section));
    }

    public function test_no_fact_row_names_a_held_person(): void {
        // Everybody is enrolled, so everybody has an enrolment row to send.
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->client($this->limits(2));

        $this->run_task($client);

        $held = $this->held_pids($this->sent_org($client));
        $facts = $this->sent_fact_pids($client);

        $this->assertSame($this->pids(array_slice($people, 2)), $held);
        $this->assertNotEmpty($facts, 'The people inside did send facts.');
        $this->assertSame([], array_values(array_intersect($held, $facts)));
    }

    public function test_erasing_a_held_person_still_tells_report_lms(): void {
        $people = $this->people(3);
        userstate::mark_all_dirty();
        $this->run_task($this->client($this->limits(1)));
        $this->assertTrue($this->dirty($people[2]));

        $client = $this->client($this->limits(1));
        erasure::set_client($client);
        erasure::send([(int) $people[2]->id]);

        $this->assertCount(1, $client->payloads);
        $this->assertSame([pseudonym::learner((int) $people[2]->id)], $client->payloads[0]['departures']);
    }

    public function test_a_held_row_carries_no_job_function(): void {
        global $DB;

        $people = $this->people(2);
        $fieldid = (int) $DB->get_field('user_info_field', 'id', ['shortname' => org::function_field()]);

        if (!$fieldid) {
            $fieldid = (int) $DB->insert_record('user_info_field', (object) [
                'shortname' => org::function_field(),
                'name' => 'Function',
                'datatype' => 'text',
                'categoryid' => 1,
                'sortorder' => 1,
            ]);
        }

        foreach ($people as $user) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => 'Machinist',
                'dataformat' => 0,
            ]);
        }

        $attrs = [];
        foreach (org::collect(new plan_limit(1, (int) $people[0]->id))->attrs as $attr) {
            $attrs[(int) $attr->userid] = $attr;
        }

        $this->assertSame('Machinist', $attrs[(int) $people[0]->id]->function);
        $this->assertTrue($attrs[(int) $people[1]->id]->held);
        $this->assertNull($attrs[(int) $people[1]->id]->function);
    }
}
