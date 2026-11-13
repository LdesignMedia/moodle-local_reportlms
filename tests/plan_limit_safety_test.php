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
 * Tests for the limits on what the plan limit itself may cost: retries, mid-run changes, transport.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\backoff;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\plan_limit;
use local_reportlms\local\userstate;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/plan_limit_testing.php');

/**
 * A site never calls Report LMS every fifteen minutes for something that keeps
 * failing, never sends with a limit it has just been told is lower, and never
 * sends its key over plain http to a remote host.
 *
 * @covers \local_reportlms\local\plan_limit
 * @covers \local_reportlms\local\backoff
 * @covers \local_reportlms\local\config::is_secure_url
 * @covers \local_reportlms\local\api_client
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\local\connect_manager
 */
final class plan_limit_safety_test extends \advanced_testcase {
    use plan_limit_testing;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->connect_site();
    }

    public function test_the_wait_doubles_from_an_hour_up_to_a_day(): void {
        $this->assertSame(HOURSECS, backoff::wait(0));
        $this->assertSame(2 * HOURSECS, backoff::wait(1));
        $this->assertSame(4 * HOURSECS, backoff::wait(2));
        $this->assertSame(16 * HOURSECS, backoff::wait(4));
        $this->assertSame(DAYSECS, backoff::wait(5));
        $this->assertSame(DAYSECS, backoff::wait(60));
    }

    public function test_resends_of_a_lost_organisation_wait_longer_each_time(): void {
        $mismatch = ['org_state_hash_applied' => 'h_other'];

        // First resend after an hour.
        $this->accepted_org(time() - HOURSECS - MINSECS);
        $this->assertTrue(plan_limit::follow_health($mismatch));
        $this->assertSame(1, plan_limit::org_resends());

        // The second waits two hours.
        $this->accepted_org(time() - 90 * MINSECS);
        $this->assertFalse(plan_limit::follow_health($mismatch));
        $this->accepted_org(time() - 2 * HOURSECS - MINSECS);
        $this->assertTrue(plan_limit::follow_health($mismatch));
        $this->assertSame(2, plan_limit::org_resends());

        // The third waits four.
        $this->accepted_org(time() - 3 * HOURSECS);
        $this->assertFalse(plan_limit::follow_health($mismatch));
        $this->accepted_org(time() - 4 * HOURSECS - MINSECS);
        $this->assertTrue(plan_limit::follow_health($mismatch));

        // Never more than a day.
        set_config(plan_limit::ORG_RESENDS, 9, 'local_reportlms');
        $this->accepted_org(time() - 23 * HOURSECS);
        $this->assertFalse(plan_limit::follow_health($mismatch));
        $this->accepted_org(time() - DAYSECS - MINSECS);
        $this->assertTrue(plan_limit::follow_health($mismatch));

        // Caught up: the count starts again.
        $this->accepted_org(time());
        $this->assertFalse(plan_limit::follow_health(['org_state_hash_applied' => 'h_abc']));
        $this->assertSame(0, plan_limit::org_resends());
        $this->accepted_org(time() - HOURSECS - MINSECS);
        $this->assertTrue(plan_limit::follow_health($mismatch));
    }

    public function test_the_task_says_which_resend_it_is(): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();
        set_config(plan_limit::ORG_SENT_AT, time() - 3 * HOURSECS, 'local_reportlms');
        set_config(plan_limit::ORG_RESENDS, 1, 'local_reportlms');

        $output = $this->run_task($this->client(['org_state_hash_applied' => 'h_other']));

        $this->assertStringContainsString('resend 2', $output);
    }

    /**
     * Limits that arrive mid-run, against the 10,000 the run started with.
     *
     * @return array<string, array{0: int}>
     */
    public static function limit_changes(): array {
        return [
            'a lower limit' => [100],
            'a higher limit' => [20000],
        ];
    }

    /**
     * Whichever way the limit moves, nothing built with the old one goes after the new one arrived.
     *
     * @dataProvider limit_changes
     * @param int $newlimit
     */
    public function test_a_limit_change_arriving_mid_run_stops_the_rest(int $newlimit): void {
        global $DB;

        // Two enrolments each for 2,600 people: more fact rows than one batch holds.
        $second = $this->getDataGenerator()->create_course();
        $userids = $this->bulk_users(2600, 'many');
        $now = time();
        foreach ([$this->course->id, $second->id] as $courseid) {
            $enrolid = (int) $DB->get_field('enrol', 'id', ['courseid' => $courseid, 'enrol' => 'manual']);
            $rows = [];
            foreach ($userids as $userid) {
                $rows[] = [
                    'enrolid' => $enrolid,
                    'userid' => $userid,
                    'status' => 0,
                    'timestart' => 0,
                    'timeend' => 0,
                    'modifierid' => 2,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
            }
            $DB->insert_records('user_enrolments', $rows);
        }
        userstate::mark_all_dirty();

        $client = $this->client($this->limits(10000));
        $client->replies = [0 => $this->limits($newlimit)];
        $output = $this->run_task($client);

        $this->assertCount(1, $client->payloads, 'Nothing built with the old limit goes after the new one arrived.');
        $this->assertCount(2500, $client->payloads[0]['learners']);
        $this->assertSame($newlimit, plan_limit::stored());
        $this->assertSame(100, userstate::count_dirty());
        $this->assertStringContainsString('plan limit changed', $output);
    }

    public function test_an_unchanged_limit_writes_nothing(): void {
        global $DB;

        plan_limit::store_reply($this->limits(1500));
        $writes = $DB->perf_get_writes();
        plan_limit::store_reply($this->limits(1500));
        $this->assertSame($writes, $DB->perf_get_writes());

        plan_limit::store_reply($this->limits(null));
        $this->assertGreaterThan($writes, $DB->perf_get_writes(), 'A change is written.');

        $writes = $DB->perf_get_writes();
        plan_limit::store_reply($this->limits(null));
        $this->assertSame($writes, $DB->perf_get_writes(), 'No limit stays no limit without a write.');
    }

    /**
     * What a site may have waiting when its key ends.
     *
     * @return array<string, array{0: string}>
     */
    public static function pending_sends(): array {
        return [
            'a learner' => ['learner'],
            'a settings change' => ['settings'],
            'a resend of the organisation' => ['resend'],
        ];
    }

    /**
     * After the key ended, whatever is waiting is sent less often, like the heartbeat.
     *
     * @dataProvider pending_sends
     * @param string $pending
     */
    public function test_after_the_key_ended_a_refused_push_waits_longer_each_time(string $pending): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();
        $ended = ['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - DAYSECS)];

        if ($pending === 'learner') {
            userstate::mark_all_dirty();
        } else if ($pending === 'settings') {
            set_config('deadline_source', 'period', 'local_reportlms');
            set_config('deadline_days', 30, 'local_reportlms');
        } else {
            set_config(plan_limit::ORG_SENT_AT, time() - 2 * HOURSECS, 'local_reportlms');
            $ended['org_state_hash_applied'] = 'h_other';
        }

        $client = $this->refusing_client($ended);
        $this->run_task($client);
        $this->assertSame(1, $client->pushcalls);

        // Straight after, and on every run for the next hour: nothing goes.
        $client = $this->refusing_client($ended);
        $output = $this->run_task($client);
        $this->assertSame(0, $client->pushcalls, 'Not every fifteen minutes.');
        $this->assertStringContainsString('key has ended, not sending again yet after 1 failed try.', $output);

        // An hour later it goes once more, is refused, and then waits two hours.
        set_config('pushfailedat', time() - HOURSECS - MINSECS, 'local_reportlms');
        $client = $this->refusing_client($ended);
        $this->run_task($client);
        $this->assertSame(1, $client->pushcalls);

        set_config('pushfailedat', time() - 90 * MINSECS, 'local_reportlms');
        $client = $this->refusing_client($ended);
        $this->run_task($client);
        $this->assertSame(0, $client->pushcalls);

        // Renewed: the next answer says so, and what waited goes at once.
        set_config('healthfailedat', time() - DAYSECS, 'local_reportlms');
        $client = $this->client(['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * DAYSECS)]);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);
        $this->assertCount(1, $client->payloads);
        $this->assertTrue(backoff::due('push'));
        $this->assertFalse(get_config('local_reportlms', 'pushfails'));
    }

    public function test_an_ordinary_refusal_does_not_hold_the_next_push(): void {
        $this->people(1);
        userstate::mark_all_dirty();

        $client = $this->refusing_client([]);
        $this->run_task($client);
        $client = $this->refusing_client([]);
        $this->run_task($client);

        $this->assertSame(1, $client->pushcalls, 'Learners still retry on every run while the key works.');
    }

    public function test_an_accepted_push_clears_the_ended_key_mark_and_the_health_wait(): void {
        $this->people(1);
        userstate::mark_all_dirty();
        set_config(backoff::KEY_ENDED, 1, 'local_reportlms');
        backoff::failed('health');
        $client = $this->client([]);

        $this->run_task($client);

        $this->assertSame(0, $client->healthcalls, 'Still waiting to ask.');
        $this->assertCount(1, $client->payloads);
        $this->assertFalse(backoff::key_has_ended());
        $this->assertTrue(backoff::due('health'), 'Report LMS took the push, so it is asked again on the next run.');
        $this->assertFalse(get_config('local_reportlms', 'healthfails'));
    }

    public function test_only_a_push_accepted_after_failing_ends_the_health_wait(): void {
        $this->people(1);
        userstate::mark_all_dirty();

        // An ordinary run: the health call fails, the push goes through. Not a
        // recovery, so the health call keeps its wait.
        $this->run_task($this->client(null));
        $this->assertFalse(backoff::due('health'));

        // A push accepted after refused ones is a recovery: asked on the next run.
        userstate::mark_all_dirty();
        backoff::failed('push');
        $this->run_task($this->client(null));
        $this->assertTrue(backoff::due('health'));
        $this->assertTrue(backoff::due('push'));
    }

    public function test_the_first_push_accepted_after_an_outage_ends_the_health_wait(): void {
        $this->people(1);
        userstate::mark_all_dirty();

        // Report LMS is down: the health call fails and waits, the push is refused.
        $client = $this->client(null);
        $client->refuse = 'Service Unavailable';
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);
        $this->assertSame(1, $client->pushcalls);

        // Still down on the next run: the push goes again, the health call waits.
        $client = $this->client(null);
        $client->refuse = 'Service Unavailable';
        $this->run_task($client);
        $this->assertSame(0, $client->healthcalls);
        $this->assertSame(1, $client->pushcalls, 'An ordinary refusal never holds the next push.');
        $this->assertFalse(backoff::due('health'));

        // Back up: the first accepted push is a recovery, so the plan limit is asked on the next run.
        $client = $this->client([]);
        $this->run_task($client);
        $this->assertSame(0, $client->healthcalls);
        $this->assertCount(1, $client->payloads);
        $this->assertTrue(backoff::due('health'));
        $this->assertTrue(backoff::due('push'));
    }

    public function test_a_renewed_key_is_noticed_within_an_hour(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->people(1);
        userstate::mark_all_dirty();
        $ended = ['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $clock->time() - DAYSECS)];

        // Ended for a while: the health call goes every hour, one small request.
        for ($hour = 0; $hour < 6; $hour++) {
            $client = $this->refusing_client($ended);
            $this->run_task($client);
            $this->assertSame(1, $client->healthcalls, 'Hour ' . $hour);
            $clock->bump(HOURSECS);
        }

        // The pushes still wait longer each time.
        $this->assertGreaterThan(HOURSECS, backoff::wait(backoff::fails('push') - 1));

        // The app renews the same key: noticed on the next hourly ask, and what
        // waited goes out in that same run.
        $client = $this->client(['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $clock->time() + 30 * DAYSECS)]);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);
        $this->assertCount(1, $client->payloads);
        $this->assertFalse(backoff::key_has_ended());
        $this->assertSame(0, userstate::count_dirty());
    }

    public function test_a_new_report_lms_address_is_tried_at_once(): void {
        set_config('baseurl', 'http://reportlms.example.com', 'local_reportlms');
        backoff::failed('health');
        backoff::failed('health');
        $this->assertFalse(backoff::due('health'));

        // Fixed, in the settings or in config.php: asked again on the next run.
        set_config('baseurl', 'https://reportlms.example.com', 'local_reportlms');
        $this->assertTrue(backoff::due('health'));
        $this->assertSame(0, backoff::fails('health'));

        // A failure at the new address counts from one.
        backoff::failed('health');
        $this->assertSame(1, backoff::fails('health'));
    }

    public function test_an_organisation_sent_because_it_changed_starts_the_resend_count_again(): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();
        $mismatch = ['org_state_hash_applied' => 'h_other'];

        // A resend: being accepted does not start the count again.
        set_config(plan_limit::ORG_SENT_AT, time() - 9 * HOURSECS, 'local_reportlms');
        set_config(plan_limit::ORG_RESENDS, 3, 'local_reportlms');
        $client = $this->client($mismatch);
        $this->run_task($client);
        $this->assertArrayHasKey('org', $client->payloads[0]);
        $this->assertSame(4, plan_limit::org_resends());

        // Somebody joins: the organisation goes again because it changed.
        $this->people(1);
        $client = $this->client([]);
        $this->run_task($client);
        $this->assertNotNull($this->sent_org($client));
        $this->assertSame(0, plan_limit::org_resends());

        // So when that one goes missing, it is sent again after an hour, not sixteen.
        set_config(plan_limit::ORG_SENT_AT, time() - HOURSECS - MINSECS, 'local_reportlms');
        $this->assertTrue(plan_limit::follow_health($mismatch));
    }

    public function test_connecting_starts_every_wait_again(): void {
        set_config(plan_limit::ORG_RESENDS, 3, 'local_reportlms');
        set_config(backoff::KEY_ENDED, 1, 'local_reportlms');
        foreach (['health', 'heartbeat', 'push'] as $name) {
            backoff::failed($name);
        }

        (new connect_manager($this->connector()))->connect();

        $this->assertSame(0, plan_limit::org_resends());
        $this->assertFalse(backoff::key_has_ended());
        foreach (['health', 'heartbeat', 'push'] as $name) {
            $this->assertTrue(backoff::due($name), $name);
            $this->assertSame(0, backoff::fails($name), $name);
        }
    }

    public function test_the_settings_page_says_when_the_address_is_not_https(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();

        $alert = \html_writer::div(get_string('error:insecurebaseurl', 'local_reportlms'), 'alert alert-danger');
        // A settings page keys its settings by plugin and name run together.
        $header = static function (): string {
            return admin_get_root(true, true)->locate('local_reportlms')->settings->local_reportlmsheader->description;
        };

        $this->assertStringNotContainsString($alert, $header());

        set_config('baseurl', 'http://reportlms.example.com', 'local_reportlms');
        $this->assertStringContainsString($alert, $header());

        // A machine of one's own may use plain http.
        set_config('baseurl', 'http://localhost:8000', 'local_reportlms');
        $this->assertStringNotContainsString($alert, $header());
    }

    public function test_a_failing_health_call_is_tried_less_often(): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();

        $client = $this->client(null);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);

        // Straight after: not asked again.
        $client = $this->client(null);
        $output = $this->run_task($client);
        $this->assertSame(0, $client->healthcalls);
        $this->assertStringContainsString('after 1 failed try, keeping the stored plan limit', $output);

        // An hour later it tries again, fails again, and waits two hours.
        set_config('healthfailedat', time() - HOURSECS - MINSECS, 'local_reportlms');
        $client = $this->client(null);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);

        set_config('healthfailedat', time() - 90 * MINSECS, 'local_reportlms');
        $client = $this->client([]);
        $this->run_task($client);
        $this->assertSame(0, $client->healthcalls);

        // Once it answers, the count starts again.
        set_config('healthfailedat', time() - 2 * HOURSECS - MINSECS, 'local_reportlms');
        $client = $this->client([]);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);
        $this->assertTrue(backoff::due('health'));
        $this->assertFalse(get_config('local_reportlms', 'healthfails'));
    }

    public function test_a_key_that_has_ended_stops_the_heartbeat_and_the_hourly_asking(): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();
        set_config(plan_limit::LAST_PUSH, time() - DAYSECS - HOURSECS, 'local_reportlms');

        $client = $this->client(['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - DAYSECS)]);
        $output = $this->run_task($client);

        $this->assertSame(1, $client->healthcalls);
        $this->assertSame([], $client->payloads, 'No heartbeat with a key that has ended.');
        $this->assertStringContainsString('key has ended', $output);

        $client = $this->client(['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - DAYSECS)]);
        $output = $this->run_task($client);
        $this->assertSame(0, $client->healthcalls);
        $this->assertSame(0, $client->pushcalls);
        $this->assertStringContainsString('key has ended', $output);
        $this->assertStringNotContainsString('failed tr', $output, 'The call did not fail, it said the key ended.');

        // Renewed: the next answer says so, and the heartbeat goes.
        set_config('healthfailedat', time() - HOURSECS - MINSECS, 'local_reportlms');
        $client = $this->client(['key_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * DAYSECS)]);
        $this->run_task($client);
        $this->assertSame(1, $client->healthcalls);
        $this->assertCount(1, $client->payloads);
    }

    public function test_a_refused_heartbeat_is_tried_less_often(): void {
        $this->people(1);
        $this->pretend_everything_was_pushed();
        set_config(plan_limit::LAST_PUSH, time() - DAYSECS - HOURSECS, 'local_reportlms');

        $client = $this->client([]);
        $client->refuse = 'Unauthenticated.';
        $this->run_task($client);
        $this->assertSame(1, $client->pushcalls);

        $client = $this->client([]);
        $client->refuse = 'Unauthenticated.';
        $this->run_task($client);
        $this->assertSame(0, $client->pushcalls, 'Not every fifteen minutes.');

        set_config('heartbeatfailedat', time() - HOURSECS - MINSECS, 'local_reportlms');
        $client = $this->client([]);
        $this->run_task($client);
        $this->assertCount(1, $client->payloads);
        $this->assertTrue(backoff::due('heartbeat'));
        $this->assertGreaterThan(time() - MINSECS, (int) get_config('local_reportlms', plan_limit::LAST_PUSH));
    }

    public function test_disconnecting_starts_the_counts_again(): void {
        set_config(plan_limit::ORG_RESENDS, 3, 'local_reportlms');
        backoff::failed('health');
        backoff::failed('heartbeat');

        connect_manager::disconnect();

        $this->assertSame(0, plan_limit::org_resends());
        $this->assertTrue(backoff::due('health'));
        $this->assertTrue(backoff::due('heartbeat'));
    }

    public function test_only_https_reaches_report_lms_except_on_a_local_machine(): void {
        $this->assertTrue(config::is_secure_url('https://reportlms.com'));
        $this->assertTrue(config::is_secure_url('https://reportlms.example.com:8443/base'));
        $this->assertTrue(config::is_secure_url('http://localhost:8000'));
        $this->assertTrue(config::is_secure_url('http://127.0.0.1'));
        $this->assertTrue(config::is_secure_url('http://reportlms.test'));

        $this->assertFalse(config::is_secure_url('http://reportlms.example.com'));
        $this->assertFalse(config::is_secure_url('http://localhost.example.com'));
        $this->assertFalse(config::is_secure_url('http://reportlms.test.example.com'));
        $this->assertFalse(config::is_secure_url('ftp://reportlms.com'));
        $this->assertFalse(config::is_secure_url(''));
    }

    public function test_a_plain_http_address_is_refused_before_any_request(): void {
        $client = new api_client('http://reportlms.example.com');
        $calls = [
            static fn() => $client->health('key'),
            static fn() => $client->push('/api/v1/push/batch', '{}', 'key', 'secret', 'idem'),
            static fn() => $client->contact('key', 'secret', 'lms@example.com'),
            static fn() => $client->connect('https://moodle.example.com', 'token'),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A plain http address must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:insecurebaseurl', $e->errorcode);
                // The address may be pinned in config.php, where the settings page cannot change it.
                $this->assertStringContainsString('https://', $e->getMessage());
                $this->assertStringContainsString('config.php', $e->getMessage());
            }
        }
    }

    public function test_the_notice_says_what_is_and_is_not_sent(): void {
        $this->people(5);

        $this->assertNull(plan_limit::notice(), 'No limit, nothing to say.');

        plan_limit::store_reply($this->limits(5));
        $this->assertNull(plan_limit::notice(), 'Everybody fits.');

        plan_limit::store_reply($this->limits(2));
        $this->assertSame(
            'Your plan covers 2 people. This site has 5 people. Reports show the first 2. '
                . 'For the other 3 only a coded id, the account status and login method '
                . '(and any unit they manage) are sent, no learning data. '
                . 'Upgrade at <a href="https://reportlms.com/pricing">https://reportlms.com/pricing</a>.',
            plan_limit::notice()
        );

        plan_limit::store_reply($this->limits(1));
        $this->assertStringStartsWith(
            'Your plan covers 1 person. This site has 5 people. Reports show the first person. For the other 4 only',
            plan_limit::notice()
        );

        plan_limit::store_reply($this->limits(4));
        $this->assertStringContainsString(
            'Reports show the first 4. For the other person only a coded id, the account status and login method '
                . '(and any unit they manage) are sent, no learning data.',
            plan_limit::notice()
        );
    }

    public function test_the_pricing_link_is_escaped(): void {
        $this->people(2);
        plan_limit::store_reply($this->limits(1));
        set_config('baseurl', 'https://reportlms.example.com/x?a=1&b=2', 'local_reportlms');

        $notice = plan_limit::notice();

        $this->assertStringNotContainsString('a=1&b=', $notice);
        $this->assertStringContainsString('a=1&amp;b=', $notice);
    }

    public function test_a_base_url_moodle_cannot_parse_still_shows_the_notice(): void {
        $this->people(2);
        plan_limit::store_reply($this->limits(1));
        // A port no URL can have: Moodle's own URL class refuses it, and the
        // settings page must still build. Only config.php can pin such a value.
        set_config('baseurl', 'https://reportlms.example.com:99999', 'local_reportlms');

        $notice = plan_limit::notice();

        $this->assertStringContainsString('https://reportlms.example.com:99999/pricing', $notice);
    }

    /**
     * A fake Report LMS that answers the health call and refuses every push.
     *
     * @param array $health
     * @return api_client
     */
    private function refusing_client(array $health): api_client {
        $client = $this->client($health);
        $client->refuse = 'This API key has expired.';

        return $client;
    }

    /**
     * Record an accepted organisation with hash abc at the given time.
     *
     * @param int $at
     * @return void
     */
    private function accepted_org(int $at): void {
        set_config('lastorghash', 'abc', 'local_reportlms');
        set_config(plan_limit::ORG_SENT_AT, $at, 'local_reportlms');
    }
}
