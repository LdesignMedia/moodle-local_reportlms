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
 * Tests for the data statement record, which connecting is what carries.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\connect;
use local_reportlms\local\api_client;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\privacy_statement;

/**
 * @covers \local_reportlms\local\privacy_statement
 * @covers \local_reportlms\local\config::privacy_acknowledged
 */
final class privacy_statement_test extends \advanced_testcase {
    /**
     * A client that records what it was asked to send and returns usable keys.
     *
     * @param array $captured Filled with the payload, by reference.
     *
     * @return api_client
     */
    private function recording_client(array &$captured): api_client {
        return new class(config::baseurl(), $captured) extends api_client {
            /** @var array Payload store, by reference. */
            private array $captured;

            /**
             * Constructor.
             *
             * @param string $baseurl
             * @param array $captured
             */
            public function __construct(string $baseurl, array &$captured) {
                parent::__construct($baseurl);
                $this->captured = &$captured;
            }

            /**
             * @param string $siteurl
             * @param string $verificationtoken
             * @param int $acknowledgedat
             *
             * @return array
             */
            public function connect(string $siteurl, string $verificationtoken, int $acknowledgedat = 0): array {
                $this->captured = [
                    'site_url' => $siteurl,
                    'privacy_acknowledged_at' => $acknowledgedat,
                ];

                return [
                    'environment_id' => 'env-1',
                    'api_key' => 'key-1',
                    'signing_secret' => 'secret-1',
                    'launch_secret' => 'launch-1',
                ];
            }
        };
    }

    /**
     * Connecting records who read the statement, and when.
     *
     * The two used to be separate steps and that was the whole bug: the record
     * could only be reported with an API key, and the connection is what mints
     * one. Reading first and connecting second is the order the setup steps ask
     * for, and it was the one order that could not complete.
     */
    public function test_connecting_records_who_read_it_and_when(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $USER;
        $before = time();

        // No licence and nothing listening, so the connection itself fails.
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');
        connect::execute();

        $this->assertGreaterThanOrEqual($before, privacy_statement::acknowledged_at());
        $this->assertEquals($USER->id, privacy_statement::acknowledged_by());
    }

    /**
     * The first reading stands.
     *
     * Re-recording on every attempt would turn a dated record into a timestamp
     * of the last time somebody pressed connect, which answers a different
     * question and is no use to whoever has to account for the decision.
     */
    public function test_a_second_attempt_does_not_overwrite_the_first_record(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('privacyackdate', 1000, 'local_reportlms');
        set_config('privacyackuserid', 42, 'local_reportlms');
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        connect::execute();

        $this->assertSame(1000, privacy_statement::acknowledged_at());
        $this->assertSame(42, privacy_statement::acknowledged_by());
    }

    /**
     * Somebody who cannot connect the site cannot record a reading on its behalf.
     */
    public function test_a_teacher_records_nothing(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            connect::execute();
            $this->fail('A teacher must not get this far.');
        } catch (\required_capability_exception $exception) {
            $this->assertFalse(privacy_statement::acknowledged());
        }
    }

    /**
     * Nothing is recorded until somebody actually connects.
     */
    public function test_a_fresh_site_holds_no_record(): void {
        $this->resetAfterTest();

        $this->assertFalse(privacy_statement::acknowledged());
        $this->assertFalse(config::privacy_acknowledged());
    }

    /**
     * The date this site holds rides the connect call.
     *
     * This is what replaced the second round trip. Report LMS refuses data from
     * a site it does not know has read the statement, and the only other way to
     * tell it needs the key this very call returns.
     */
    public function test_the_connect_payload_carries_the_date(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('privacyackdate', 1700000000, 'local_reportlms');

        $captured = [];
        (new connect_manager($this->recording_client($captured)))->connect();

        $this->assertSame(1700000000, $captured['privacy_acknowledged_at']);
    }

    /**
     * A site with nothing recorded sends nothing rather than a zero.
     *
     * The field then means "this is when it happened" and never "it did not",
     * which is the difference between an absent answer and a wrong one.
     */
    public function test_a_site_with_no_record_sends_no_date(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $captured = [];
        (new connect_manager($this->recording_client($captured)))->connect();

        $this->assertSame(0, $captured['privacy_acknowledged_at']);
    }

    /**
     * The setup gate follows this site's own record, not what Report LMS confirmed.
     *
     * Confirmation needs an API key, an API key needs a connection, and the
     * connection is the step this gate stands in front of. Reading it the other
     * way round left a site that had done everything asked of it sitting on the
     * setup steps forever.
     */
    public function test_reading_it_is_enough_to_pass_the_setup_gate(): void {
        $this->resetAfterTest();

        set_config('privacyackdate', 1700000000, 'local_reportlms');

        $this->assertTrue(config::privacy_acknowledged());
        $this->assertFalse(privacy_statement::confirmed(), 'Nobody has been told yet.');
    }

    /**
     * An accepted push is what proves Report LMS knows.
     */
    public function test_an_accepted_push_confirms_it(): void {
        $this->resetAfterTest();

        set_config('privacyackdate', 1700000000, 'local_reportlms');
        privacy_statement::accepted_in_push();

        $this->assertTrue(privacy_statement::confirmed());
    }

    /**
     * An accepted push proves nothing about a site that never acknowledged.
     */
    public function test_a_push_does_not_invent_an_acknowledgement(): void {
        $this->resetAfterTest();

        privacy_statement::accepted_in_push();

        $this->assertFalse(privacy_statement::acknowledged());
        $this->assertFalse(privacy_statement::confirmed());
    }

    /**
     * A successful connect is what marks the date as told, with no second call.
     */
    public function test_a_successful_connect_marks_it_told(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('privacyackdate', 1700000000, 'local_reportlms');

        $captured = [];
        (new connect_manager($this->recording_client($captured)))->connect();

        $this->assertTrue(privacy_statement::confirmed());
    }

    /**
     * A site with nothing recorded claims nothing, however the connect went.
     */
    public function test_a_connect_without_a_reading_claims_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $captured = [];
        (new connect_manager($this->recording_client($captured)))->connect();

        $this->assertFalse(privacy_statement::confirmed());
    }
}
