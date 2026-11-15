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
 * Tests for sending the organisation in pages.
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
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\mapping;
use local_reportlms\local\org_pages;
use local_reportlms\local\plan_limit;
use local_reportlms\local\push_refused_exception;
use local_reportlms\local\userstate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/plan_limit_testing.php');

/**
 * A large organisation goes in pages of its own, before the learners.
 *
 * The organisation is full state: every unit, every membership, every
 * manager and one row of attributes per person. On a large site that is one
 * very large request, so when Report LMS says it takes pages, a section with
 * more rows than one page holds is cut into pages, sent first and remembered
 * only once the last page is accepted. A section that fits one page, or a
 * Report LMS that does not offer pages, gets exactly what it got before.
 *
 * The fake Report LMS here offers pages of five rows, so a handful of people
 * is already a large site.
 *
 * @covers \local_reportlms\local\org_pages
 * @covers \local_reportlms\local\batch_builder
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\connect_manager
 */
final class org_pages_test extends \advanced_testcase {
    use plan_limit_testing;

    /** @var int Rows per page the fake Report LMS offers. */
    private const OFFER = 5;

    /** @var string[] What a page may carry at the top level, besides the organisation and no facts. */
    private const PAGE_KEYS = [
        'contract_version', 'platform', 'platform_version', 'plugin_version', 'privacy_acknowledged_at',
        'environment_id', 'snapshot_at', 'contact_email', 'org', 'facts',
    ];

    /** @var string[] What the organisation part of a page holds, and nothing else. */
    private const ORG_KEYS = [
        'set_id', 'page', 'page_count', 'org_state_hash', 'limit_applied', 'nodes', 'managers', 'members', 'attrs',
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->connect_site();
    }

    public function test_an_organisation_that_fits_one_page_goes_whole_as_before(): void {
        $people = $this->people(2);
        userstate::mark_all_dirty();
        $client = $this->pager(['org_page_rows' => 1000]);

        $this->run_task($client);

        $this->assertSame(1000, org_pages::rows());
        $this->assertCount(1, $client->payloads);
        $this->assertSame('1.0', $client->payloads[0]['contract_version']);
        $this->assertArrayNotHasKey('set_id', $client->payloads[0]['org']);
        $this->assertArrayNotHasKey('page', $client->payloads[0]['org']);
        $this->assertSame($this->pids($people), $client->payloads[0]['learners']);
    }

    public function test_a_small_site_builds_the_same_bytes_with_or_without_the_offer(): void {
        $people = $this->people(3);
        $userids = array_map(static fn($user) => (int) $user->id, $people);
        $plan = plan_limit::current();
        $now = time();

        $before = batch_builder::build($userids, $now, $plan);
        $structurebefore = batch_builder::structure_only($now, $plan);

        org_pages::store_reply(['org_page_rows' => 1000]);
        $org = batch_builder::org_delivery($plan, $now);
        $after = batch_builder::build($userids, $now, $plan, $org);
        $structureafter = batch_builder::structure_only($now, $plan, false, $org);

        $this->assertSame([], $org->pages);
        $this->assertNotNull($org->section);
        $this->assertArrayHasKey('org', $after[0]->payload, 'The comparison covers the organisation section.');
        $this->assertSame(
            json_encode(array_column($before, 'payload')),
            json_encode(array_column($after, 'payload'))
        );
        $this->assertSame(json_encode($structurebefore->payload), json_encode($structureafter->payload));
        $this->assertSame($org->hash, $after[0]->orghash);
        $this->assertSame($org->hash, $structureafter->orghash);
    }

    public function test_with_no_offer_a_large_organisation_still_goes_whole(): void {
        $this->people(4);
        userstate::mark_all_dirty();
        $whole = $this->whole();
        $this->assertGreaterThan(self::OFFER, count($whole['members']) + count($whole['attrs']));
        $client = $this->pager([]);

        $this->run_task($client);

        $this->assertSame(0, org_pages::rows());
        $this->assertCount(1, $client->payloads);
        $this->assertSame('1.0', $client->payloads[0]['contract_version']);
        $this->assertEquals($whole, $client->payloads[0]['org']);
        $this->assertNotEmpty($client->payloads[0]['learners']);
    }

    public function test_a_large_organisation_goes_in_pages_before_the_learners(): void {
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $output = $this->run_task($client);

        $pages = $this->pages($client);
        $count = count($pages);
        $this->assertGreaterThan(1, $count);

        foreach (array_slice($client->payloads, 0, $count) as $payload) {
            $this->assertArrayHasKey('set_id', $payload['org']);
            $this->assertArrayNotHasKey('learners', $payload);
        }

        $rest = array_slice($client->payloads, $count);
        $this->assertNotEmpty($rest);
        foreach ($rest as $payload) {
            $this->assertArrayNotHasKey('org', $payload, 'The first chunk of learners carries no organisation.');
        }

        $this->assertSame($this->pids($people), $this->sent_learners($client));
        $this->assertSame(0, userstate::count_dirty());
        $this->assertStringContainsString("organisation in {$count} pages", $output);
        $this->assertStringContainsString("org page 1 of {$count} accepted", $output);
        $this->assertStringContainsString("org page {$count} of {$count} accepted", $output);
        $this->assertStringContainsString('chunk 0: ', $output);

        // A push reply says nothing about pages, and leaves the offer alone.
        $this->assertSame(self::OFFER, org_pages::rows());
    }

    public function test_every_page_names_its_set_place_hash_and_limit(): void {
        $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager(['org_page_rows' => self::OFFER] + $this->limits(3));

        $this->run_task($client);

        $whole = $this->whole();
        $pages = $this->pages($client);
        $count = count($pages);
        $first = $client->payloads[0];

        foreach (array_slice($client->payloads, 0, $count) as $i => $payload) {
            $this->assertSame('1.1', $payload['contract_version']);
            $this->assertSame([], array_diff(array_keys($payload), self::PAGE_KEYS), 'A page carries nothing else.');
            $this->assertSame([], $payload['facts']);
            $this->assertSame($first['snapshot_at'], $payload['snapshot_at']);

            $org = $payload['org'];
            $this->assertEqualsCanonicalizing(self::ORG_KEYS, array_keys($org));
            $this->assertMatchesRegularExpression('/^s_[0-9a-f]{32}$/', $org['set_id']);
            $this->assertSame($first['org']['set_id'], $org['set_id']);
            $this->assertSame($i, $org['page']);
            $this->assertSame($count, $org['page_count']);
            $this->assertSame($whole['org_state_hash'], $org['org_state_hash']);
            $this->assertSame(3, $org['limit_applied']);
        }
    }

    public function test_only_the_first_page_carries_the_units_and_managers(): void {
        $manager = $this->getDataGenerator()->create_user();
        $nodeid = mapping::save_node('Sales', 'department');
        mapping::add_manager($nodeid, (int) $manager->id, null);
        mapping::reset();
        $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $this->run_task($client);

        $whole = $this->whole();
        $pages = $this->pages($client);
        $this->assertNotEmpty($whole['nodes']);
        $this->assertCount(1, $whole['managers']);
        $this->assertSame($whole['nodes'], $pages[0]['nodes']);
        $this->assertSame($whole['managers'], $pages[0]['managers']);

        foreach (array_slice($pages, 1) as $page) {
            $this->assertSame([], $page['nodes']);
            $this->assertSame([], $page['managers']);
        }
    }

    public function test_no_page_holds_more_rows_than_offered(): void {
        $this->people(6);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $this->run_task($client);

        $pages = $this->pages($client);
        $last = count($pages) - 1;
        foreach ($pages as $i => $page) {
            $rows = count($page['members']) + count($page['attrs']);
            $this->assertLessThanOrEqual(self::OFFER, $rows);
            if ($i < $last) {
                $this->assertSame(self::OFFER, $rows, 'Only the last page is short.');
            }
        }
    }

    public function test_the_offer_is_capped_at_the_plugin_page_size(): void {
        org_pages::store_reply(['org_page_rows' => org_pages::ROWS + 5000]);

        $this->assertSame(org_pages::ROWS, org_pages::rows());
        $this->assertSame(org_pages::ROWS + 5000, (int) get_config('local_reportlms', org_pages::CONFIG));
    }

    public function test_the_pages_together_are_the_whole_organisation(): void {
        $manager = $this->getDataGenerator()->create_user();
        $nodeid = mapping::save_node('Support', 'department');
        mapping::add_manager($nodeid, (int) $manager->id, null);
        mapping::reset();
        $this->people(5);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $this->run_task($client);

        $this->assertSame($this->sorted($this->whole()), $this->sorted($this->joined($this->pages($client))));
    }

    public function test_the_organisation_is_remembered_only_after_the_last_page(): void {
        $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $this->run_task($client);

        $whole = $this->whole();
        $count = count($this->pages($client));

        // What was remembered as each push began: nothing during the pages.
        foreach (array_slice($client->saved, 0, $count) as $saved) {
            $this->assertFalse($saved);
        }
        $this->assertSame(substr($whole['org_state_hash'], 2), $client->saved[$count]);
        $this->assertSame(substr($whole['org_state_hash'], 2), get_config('local_reportlms', 'lastorghash'));
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', plan_limit::ORG_SENT_AT));
    }

    /**
     * Which page is refused.
     *
     * @return array<string, array{0: string}>
     */
    public static function refused_pages(): array {
        return [
            'the first page' => ['first'],
            'the second page' => ['second'],
            'the last page' => ['last'],
        ];
    }

    /**
     * A refused page stops the run: no learner goes and nothing is remembered.
     *
     * @dataProvider refused_pages
     * @param string $which
     */
    public function test_a_refused_page_stops_the_run_and_sends_no_learner(string $which): void {
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $count = count(org_pages::split($this->whole(), self::OFFER));
        $refused = match ($which) {
            'first' => 0,
            'last' => $count - 1,
            default => 1,
        };
        $client = $this->pager();
        $client->refuseat = $refused;

        $output = $this->run_task($client);

        $this->assertCount($refused, $client->payloads);
        $this->assertSame([], $this->sent_learners($client));
        foreach ($people as $user) {
            $this->assertTrue($this->dirty($user));
        }
        $this->assertFalse(get_config('local_reportlms', 'lastorghash'));
        $this->assertFalse(get_config('local_reportlms', plan_limit::ORG_SENT_AT));
        $this->assertStringContainsString('stopped at org page ' . ($refused + 1) . " of {$count}", $output);
        $this->assertStringContainsString('stay dirty', $output);
    }

    public function test_the_resend_count_and_the_labels_wait_for_the_last_page(): void {
        $this->people(4);
        $this->pretend_everything_was_pushed();
        // Changed since the hash Report LMS holds, after earlier resends.
        set_config('lastorghash', 'stale', 'local_reportlms');
        set_config(plan_limit::ORG_RESENDS, 3, 'local_reportlms');
        $labels = \cache::make('local_reportlms', 'labels');
        $labels->set('labelmap', ['seeded' => 'Seeded']);
        $count = count(org_pages::split($this->whole(), self::OFFER));

        $refused = $this->pager();
        $refused->refuseat = $count - 1;
        $this->run_task($refused);

        $this->assertCount($count - 1, $refused->payloads);
        $this->assertSame(3, plan_limit::org_resends());
        $this->assertSame(['seeded' => 'Seeded'], $labels->get('labelmap'));
        $this->assertSame('stale', get_config('local_reportlms', 'lastorghash'));

        $accepted = $this->pager();
        $this->run_task($accepted);

        $this->assertCount($count, $accepted->payloads, 'Only the pages: nothing else changed.');
        $this->assertSame(0, plan_limit::org_resends());
        $this->assertFalse($labels->get('labelmap'));
        $this->assertSame(substr($this->whole()['org_state_hash'], 2), get_config('local_reportlms', 'lastorghash'));
    }

    public function test_a_lost_organisation_is_sent_again_in_pages(): void {
        $this->people(4);
        $this->pretend_everything_was_pushed();
        set_config(plan_limit::ORG_SENT_AT, time() - 2 * HOURSECS, 'local_reportlms');
        $hash = $this->whole()['org_state_hash'];
        $lost = ['org_page_rows' => self::OFFER, 'org_state_hash_applied' => 'h_somethingelse'];

        // Found lost and counted; the set stops at its second page.
        $first = $this->pager($lost);
        $first->refuseat = 1;
        $output = $this->run_task($first);

        $this->assertStringContainsString('sending it again (resend 1)', $output);
        $this->assertSame(1, plan_limit::org_resends());
        $this->assertFalse(get_config('local_reportlms', 'lastorghash'));

        // The next run sends the whole set again, and the count stays as it is.
        $second = $this->pager($lost);
        $output = $this->run_task($second);

        $pages = $this->pages($second);
        $this->assertGreaterThan(1, count($pages));
        $this->assertCount(count($pages), $second->payloads);
        $this->assertSame(0, $pages[0]['page']);
        $this->assertStringNotContainsString('sending it again', $output);
        $this->assertSame(array_fill(0, count($pages), 1), $second->resends);
        $this->assertSame(1, plan_limit::org_resends(), 'A resend is not a change, so the last page keeps the count.');
        $this->assertSame(substr($hash, 2), get_config('local_reportlms', 'lastorghash'));
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', plan_limit::ORG_SENT_AT));

        // Once Report LMS says it applied that hash, the count starts again.
        $this->run_task($this->pager(['org_page_rows' => self::OFFER, 'org_state_hash_applied' => $hash]));
        $this->assertSame(0, plan_limit::org_resends());
    }

    public function test_the_next_run_after_a_refusal_starts_a_new_set(): void {
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $first = $this->pager();
        $first->refuseat = 1;
        $this->run_task($first);

        $this->waitForSecond();
        $second = $this->pager();
        $this->run_task($second);

        $before = $first->payloads[0];
        $pages = $this->pages($second);
        $this->assertSame(0, $pages[0]['page'], 'Started again from the first page, not resumed.');
        $this->assertNotSame($before['org']['set_id'], $pages[0]['set_id']);
        $this->assertNotSame($before['snapshot_at'], $second->payloads[0]['snapshot_at']);
        $this->assertSame($this->pids($people), $this->sent_learners($second));
        $this->assertSame(0, userstate::count_dirty());
        $this->assertSame(
            substr($pages[0]['org_state_hash'], 2),
            get_config('local_reportlms', 'lastorghash')
        );
    }

    public function test_each_page_has_its_own_idempotency_key(): void {
        $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager();

        $this->run_task($client);

        $count = count($this->pages($client));
        $keys = array_slice($client->keys, 0, $count);
        $this->assertCount($count, array_unique($keys));

        $prefix = 'env_test:' . $client->payloads[0]['snapshot_at'] . ':';
        foreach ($keys as $key) {
            $this->assertStringStartsWith($prefix, $key);
        }
        $this->assertCount(1, array_unique(array_column($this->pages($client), 'set_id')));
    }

    public function test_a_limit_change_during_the_pages_finishes_the_set_then_stops(): void {
        $people = $this->people(4);
        userstate::mark_all_dirty();
        $client = $this->pager();
        $client->replies = [0 => $this->limits(1)];

        $output = $this->run_task($client);

        $pages = $this->pages($client);
        $this->assertCount(count($pages), $client->payloads, 'Every page went, and nothing after them.');
        $this->assertSame($pages[0]['page_count'], count($pages));
        foreach ($pages as $page) {
            $this->assertNull($page['limit_applied']);
        }
        $this->assertSame(1, plan_limit::stored());
        $this->assertStringContainsString('plan limit changed', $output);
        $this->assertSame(substr($pages[0]['org_state_hash'], 2), get_config('local_reportlms', 'lastorghash'));
        foreach ($people as $user) {
            $this->assertTrue($this->dirty($user));
        }
    }

    public function test_the_health_reply_turns_pages_on_and_an_older_app_turns_them_off(): void {
        $this->people(2);
        $this->pretend_everything_was_pushed();
        $this->assertSame(0, org_pages::rows());

        $this->run_task($this->pager(['org_page_rows' => self::OFFER]));
        $this->assertSame(self::OFFER, org_pages::rows());

        $this->run_task($this->pager([]));
        $this->assertSame(0, org_pages::rows());

        // An unreachable Report LMS says nothing either way.
        $this->run_task($this->pager(['org_page_rows' => self::OFFER]));
        $this->run_task($this->pager(null));
        $this->assertSame(self::OFFER, org_pages::rows());
    }

    /**
     * Answers that do not offer pages.
     *
     * @return array<string, array{0: array}>
     */
    public static function no_offer(): array {
        return [
            'no offer at all' => [[]],
            'null' => [['org_page_rows' => null]],
            'zero' => [['org_page_rows' => 0]],
            'negative' => [['org_page_rows' => -5]],
            'a string' => [['org_page_rows' => '300']],
            'a fraction' => [['org_page_rows' => 300.5]],
            'true' => [['org_page_rows' => true]],
            'a list' => [['org_page_rows' => [300]]],
        ];
    }

    /**
     * Anything but a positive whole number clears the offer.
     *
     * @dataProvider no_offer
     * @param array $reply
     */
    public function test_anything_but_a_positive_whole_number_clears_the_offer(array $reply): void {
        org_pages::store_reply(['org_page_rows' => 300]);
        $this->assertSame(300, org_pages::rows());

        org_pages::store_reply($reply);

        $this->assertSame(0, org_pages::rows());
        $this->assertFalse(get_config('local_reportlms', org_pages::CONFIG));
    }

    public function test_an_unchanged_offer_writes_nothing(): void {
        global $DB;

        org_pages::store_reply(['org_page_rows' => 300]);
        $writes = $DB->perf_get_writes();
        org_pages::store_reply(['org_page_rows' => 300]);
        $this->assertSame($writes, $DB->perf_get_writes());

        org_pages::store_reply([]);
        $writes = $DB->perf_get_writes();
        org_pages::store_reply([]);
        $this->assertSame($writes, $DB->perf_get_writes(), 'No offer stays no offer without a write.');
    }

    public function test_connecting_stores_the_offer_and_disconnecting_clears_it(): void {
        (new connect_manager($this->offering_connector(25000)))->connect();
        $this->assertSame(org_pages::ROWS, org_pages::rows());

        connect_manager::disconnect();
        $this->assertSame(0, org_pages::rows());
        $this->assertFalse(get_config('local_reportlms', org_pages::CONFIG));

        // A connect reply without the offer is a Report LMS that cannot take pages.
        org_pages::store_reply(['org_page_rows' => 300]);
        (new connect_manager($this->connector()))->connect();
        $this->assertSame(0, org_pages::rows());
    }

    public function test_a_quiet_site_sends_only_the_pages(): void {
        $this->people(4);
        $this->pretend_everything_was_pushed();
        unset_config('lastorghash', 'local_reportlms');
        set_config('lastpush', time() - DAYSECS - HOURSECS, 'local_reportlms');
        $client = $this->pager();

        $output = $this->run_task($client);

        $pages = $this->pages($client);
        $this->assertGreaterThan(1, count($pages));
        $this->assertCount(count($pages), $client->payloads, 'No heartbeat or other chunk after the pages.');
        $this->assertStringNotContainsString('heartbeat', $output);
        $this->assertStringNotContainsString('nothing to push', $output);
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', 'lastpush'));
        $this->assertSame(substr($pages[0]['org_state_hash'], 2), get_config('local_reportlms', 'lastorghash'));
    }

    public function test_a_quiet_site_sends_a_changed_catalogue_after_the_pages(): void {
        $this->people(4);
        $this->pretend_everything_was_pushed();
        unset_config('lastorghash', 'local_reportlms');
        unset_config('lastdimhash', 'local_reportlms');
        $client = $this->pager();

        $output = $this->run_task($client);

        $count = count($this->pages($client));
        $this->assertCount($count + 1, $client->payloads);
        $last = end($client->payloads);
        $this->assertArrayHasKey('dimensions', $last);
        $this->assertArrayNotHasKey('org', $last);
        $this->assertSame('1.0', $last['contract_version']);
        $this->assertStringContainsString('sending the catalogue and settings after the organisation pages', $output);
        $this->assertStringNotContainsString('sending the organisation and catalogue', $output);
    }

    public function test_paging_does_not_change_the_organisation_hash(): void {
        $this->people(4);
        userstate::mark_all_dirty();
        $whole = $this->whole();
        $client = $this->pager();

        $this->run_task($client);

        foreach ($this->pages($client) as $page) {
            $this->assertSame($whole['org_state_hash'], $page['org_state_hash']);
        }
        $this->assertSame(substr($whole['org_state_hash'], 2), get_config('local_reportlms', 'lastorghash'));

        // Remembered, so the next run sends no organisation at all.
        $this->assertNull(batch_builder::org_section_if_changed(plan_limit::current()));
    }

    public function test_split_cuts_the_member_then_attribute_stream_every_n_rows(): void {
        $pages = org_pages::split($this->section(3, 3), 2);

        $this->assertCount(3, $pages);
        $this->assertSame([['learner_pid' => 'm0'], ['learner_pid' => 'm1']], $pages[0]['members']);
        $this->assertSame([], $pages[0]['attrs']);
        $this->assertSame([['learner_pid' => 'm2']], $pages[1]['members']);
        $this->assertSame([['learner_pid' => 'a0']], $pages[1]['attrs']);
        $this->assertSame([], $pages[2]['members']);
        $this->assertSame([['learner_pid' => 'a1'], ['learner_pid' => 'a2']], $pages[2]['attrs']);

        foreach ($pages as $i => $page) {
            $this->assertSame($i, $page['page']);
            $this->assertSame(3, $page['page_count']);
            $this->assertArrayNotHasKey('set_id', $page, 'The set is named by whoever sends it.');
        }
    }

    public function test_split_gives_units_and_managers_to_the_first_page_only(): void {
        $section = $this->section(2, 2);

        $pages = org_pages::split($section, 1);

        $this->assertCount(4, $pages);
        $this->assertSame($section['nodes'], $pages[0]['nodes']);
        $this->assertSame($section['managers'], $pages[0]['managers']);
        foreach (array_slice($pages, 1) as $page) {
            $this->assertSame([], $page['nodes']);
            $this->assertSame([], $page['managers']);
        }
    }

    public function test_split_puts_the_hash_and_the_limit_on_every_page(): void {
        $pages = org_pages::split($this->section(4, 4), 3);

        $this->assertCount(3, $pages);
        foreach ($pages as $page) {
            $this->assertSame('h_abc', $page['org_state_hash']);
            $this->assertSame(7, $page['limit_applied']);
        }

        // No limit travels as null on every page, not as a missing key.
        $section = $this->section(2, 2);
        $section['limit_applied'] = null;
        foreach (org_pages::split($section, 1) as $page) {
            $this->assertArrayHasKey('limit_applied', $page);
            $this->assertNull($page['limit_applied']);
        }
    }

    public function test_split_of_an_exact_multiple_leaves_no_empty_page(): void {
        $pages = org_pages::split($this->section(2, 2), 2);

        $this->assertCount(2, $pages);
        $this->assertSame(2, $pages[1]['page_count']);
        $this->assertCount(2, $pages[1]['attrs']);
    }

    public function test_split_of_an_empty_stream_is_one_page(): void {
        $section = $this->section(0, 0);

        $pages = org_pages::split($section, 5);

        $this->assertCount(1, $pages);
        $this->assertSame(0, $pages[0]['page']);
        $this->assertSame(1, $pages[0]['page_count']);
        $this->assertSame($section['nodes'], $pages[0]['nodes']);
    }

    public function test_split_refuses_a_page_size_below_one(): void {
        $this->expectException(\coding_exception::class);

        org_pages::split($this->section(1, 1), 0);
    }

    public function test_a_set_id_is_coded_and_new_every_time(): void {
        $first = org_pages::new_set_id();
        $second = org_pages::new_set_id();

        $this->assertMatchesRegularExpression('/^s_[0-9a-f]{32}$/', $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * A fake Report LMS that offers pages and records each try.
     *
     * Each try is numbered from 0. The try numbered refuseat is refused; the
     * others are accepted, with replies[n] for the n-th accepted push and reply
     * for the rest.
     *
     * @param array|null $health What the health call answers; null fails it.
     * @return api_client
     */
    protected function pager(?array $health = ['org_page_rows' => self::OFFER]): api_client {
        $client = new class (config::baseurl()) extends api_client {
            /** @var array|null What the health call answers; null fails it. */
            public $health = [];
            /** @var array What a push answers besides its batch id. */
            public $reply = [];
            /** @var array Per-push answers, by accepted push number from 0. */
            public $replies = [];
            /** @var int|null Refuse the try with this number, counted from 0. */
            public $refuseat = null;
            /** @var array Decoded payloads accepted. */
            public $payloads = [];
            /** @var string[] Idempotency keys of the payloads accepted. */
            public $keys = [];
            /** @var array The remembered organisation hash as each try began. */
            public $saved = [];
            /** @var int[] The organisation resend count as each try began. */
            public $resends = [];
            /** @var int Pushes tried, accepted or not. */
            public $tries = 0;

            /**
             * Answer the health call, or fail it.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                if ($this->health === null) {
                    throw new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'unreachable');
                }

                return $this->health;
            }

            /**
             * Keep the payload and accept it, or refuse it.
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
                $try = $this->tries++;
                $this->saved[] = get_config('local_reportlms', 'lastorghash');
                $this->resends[] = (int) get_config('local_reportlms', 'orgresends');

                if ($try === $this->refuseat) {
                    throw new push_refused_exception('', 'Refused for the test.');
                }

                $number = count($this->payloads);
                $this->payloads[] = json_decode($rawbody, true);
                $this->keys[] = $idempotencykey;

                return ['batch_id' => 'b-' . $number] + ($this->replies[$number] ?? $this->reply);
            }
        };
        $client->health = $health;

        return $client;
    }

    /**
     * A fake Report LMS that answers a connect with keys and a page offer.
     *
     * @param int $rows
     * @return api_client
     */
    protected function offering_connector(int $rows): api_client {
        $client = new class (config::baseurl()) extends api_client {
            /** @var int Rows per page offered. */
            public $rows = 0;

            /**
             * Answer a connect with keys and a page offer.
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
                    'org_page_rows' => $this->rows,
                ];
            }
        };
        $client->rows = $rows;

        return $client;
    }

    /**
     * The organisation parts of the pages a client was sent, in order.
     *
     * @param api_client $client
     * @return array[]
     */
    protected function pages(api_client $client): array {
        $pages = [];

        foreach ($client->payloads as $payload) {
            if (isset($payload['org']['set_id'])) {
                $pages[] = $payload['org'];
            }
        }

        return $pages;
    }

    /**
     * The whole organisation section as it stands, whatever was remembered.
     *
     * @return array
     */
    protected function whole(): array {
        $saved = get_config('local_reportlms', 'lastorghash');
        unset_config('lastorghash', 'local_reportlms');

        $section = batch_builder::org_section_if_changed(plan_limit::current());

        if ($saved !== false) {
            set_config('lastorghash', $saved, 'local_reportlms');
        }

        return $section;
    }

    /**
     * Pages put back together into one section.
     *
     * @param array[] $pages
     * @return array
     */
    protected function joined(array $pages): array {
        $section = [
            'org_state_hash' => $pages[0]['org_state_hash'],
            'nodes' => [],
            'members' => [],
            'managers' => [],
            'attrs' => [],
            'limit_applied' => $pages[0]['limit_applied'],
        ];

        foreach ($pages as $page) {
            foreach (['nodes', 'members', 'managers', 'attrs'] as $list) {
                $section[$list] = array_merge($section[$list], $page[$list]);
            }
        }

        return $section;
    }

    /**
     * A section with its keys in one order, for comparing.
     *
     * @param array $section
     * @return array
     */
    protected function sorted(array $section): array {
        ksort($section);

        return $section;
    }

    /**
     * A made-up section with this many members and attribute rows.
     *
     * @param int $members
     * @param int $attrs
     * @return array
     */
    protected function section(int $members, int $attrs): array {
        $section = [
            'org_state_hash' => 'h_abc',
            'nodes' => [['node_id' => 'n_1', 'parent_id' => null, 'kind' => 'department']],
            'members' => [],
            'managers' => [['manager_pid' => 'p_1', 'node_id' => 'n_1']],
            'attrs' => [],
            'limit_applied' => 7,
        ];

        for ($i = 0; $i < $members; $i++) {
            $section['members'][] = ['learner_pid' => 'm' . $i];
        }
        for ($i = 0; $i < $attrs; $i++) {
            $section['attrs'][] = ['learner_pid' => 'a' . $i];
        }

        return $section;
    }
}
