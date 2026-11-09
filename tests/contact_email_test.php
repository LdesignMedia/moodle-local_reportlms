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
 * Tests for the contact address.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\connect;
use local_reportlms\local\api_client;
use local_reportlms\local\batch_builder;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\contact_email;
use local_reportlms\privacy\provider;

/**
 * Where Report LMS writes about the licence: sent when given, asked for when missing.
 *
 * @covers \local_reportlms\local\contact_email
 * @covers \local_reportlms\local\api_client::connect
 * @covers \local_reportlms\external\connect
 * @covers \local_reportlms\privacy\provider
 */
final class contact_email_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    /**
     * A client that records what connect and health were given and answers from the script.
     *
     * @param array $sent Filled with the connect arguments.
     * @param array|null $health What health answers, or null to fail.
     *
     * @return api_client
     */
    private function client(array &$sent, ?array $health = null): api_client {
        return new class (config::baseurl(), $sent, $health) extends api_client {
            /** @var array */
            private $sent;

            /** @var array|null */
            private $health;

            public function __construct(string $baseurl, array &$sent, ?array $health) {
                parent::__construct($baseurl);
                $this->sent = &$sent;
                $this->health = $health;
            }

            public function connect(
                string $siteurl,
                string $verificationtoken,
                int $acknowledgedat = 0,
                string $contactemail = ''
            ): array {
                $this->sent = ['contactemail' => $contactemail];

                return [
                    'environment_id' => 'env_test',
                    'api_key' => 'k',
                    'signing_secret' => 's',
                    'launch_secret' => 'l',
                ];
            }

            public function health(string $apikey): array {
                if ($this->health === null) {
                    throw new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'down');
                }

                return $this->health;
            }
        };
    }

    /**
     * A connected site, so the gate has something to decide about.
     */
    private function connected_site(): void {
        set_config('apikey', 'k', 'local_reportlms');
        set_config('signingsecret', 's', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
    }

    public function test_connect_sends_the_address_when_given(): void {
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $sent = [];

        (new connect_manager($this->client($sent)))->connect();

        $this->assertSame('lms@example.org', $sent['contactemail']);
    }

    public function test_connect_sends_nothing_when_there_is_none(): void {
        $sent = [];

        (new connect_manager($this->client($sent)))->connect();

        $this->assertSame('', $sent['contactemail']);
    }

    /**
     * The client's own body: the field is there when given and absent when empty.
     */
    public function test_the_connect_body_carries_the_field_only_when_given(): void {
        $body = [];
        $client = new class (config::baseurl(), $body) extends api_client {
            /** @var array */
            private $body;

            public function __construct(string $baseurl, array &$body) {
                parent::__construct($baseurl);
                $this->body = &$body;
            }

            protected function post(string $path, array $payload): array {
                $this->body = $payload;

                return ['environment_id' => 'e', 'api_key' => 'k', 'signing_secret' => 's', 'launch_secret' => 'l'];
            }
        };

        $client->connect('https://lms.example.org', 'token', 0, 'lms@example.org');
        $this->assertSame('lms@example.org', $body['contact_email']);

        $client->connect('https://lms.example.org', 'token', 0, '');
        $this->assertArrayNotHasKey('contact_email', $body);
    }

    public function test_the_connect_service_stores_a_valid_address(): void {
        $this->setAdminUser();
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        connect::execute('lms@example.org');

        $this->assertSame('lms@example.org', contact_email::current());
    }

    public function test_the_connect_service_rejects_an_invalid_address(): void {
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        connect::execute('not-an-address');
    }

    public function test_the_envelope_carries_the_address_only_when_set(): void {
        $without = batch_builder::structure_only(time());
        $this->assertNotNull($without);
        $this->assertArrayNotHasKey('contact_email', $without->payload);

        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $with = batch_builder::structure_only(time());
        $this->assertSame('lms@example.org', $with->payload['contact_email']);
    }

    public function test_a_changed_address_is_a_reason_to_push_and_an_accepted_one_is_not(): void {
        $org = batch_builder::org_section_if_changed();
        if ($org !== null) {
            set_config('lastorghash', substr($org['org_state_hash'], 2), 'local_reportlms');
        }
        $dims = batch_builder::dimensions_section_if_changed();
        if ($dims !== null) {
            set_config('lastdimhash', $dims['hash'], 'local_reportlms');
        }
        set_config('lastsettingshash', sha1(json_encode(batch_builder::settings_section())), 'local_reportlms');
        $this->assertNull(batch_builder::structure_only(time()));

        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $this->assertNotNull(batch_builder::structure_only(time()));

        contact_email::mark_sent('lms@example.org');
        $this->assertNull(batch_builder::structure_only(time()));
    }

    public function test_health_answers_are_stored_and_a_missing_field_is_unknown(): void {
        contact_email::store_health(['contact_email_set' => false]);
        $this->assertSame('0', get_config('local_reportlms', 'contactemailset'));

        contact_email::store_health(['contact_email_set' => true]);
        $this->assertSame('1', get_config('local_reportlms', 'contactemailset'));

        contact_email::store_health(['status' => 'ok']);
        $this->assertFalse(get_config('local_reportlms', 'contactemailset'));
        $this->assertFalse(contact_email::reportlms_has_none());
    }

    public function test_an_unreachable_report_lms_leaves_the_answer_alone(): void {
        $this->connected_site();
        set_config('contactemailset', '0', 'local_reportlms');
        $sent = [];

        contact_email::refresh($this->client($sent, null), true);

        $this->assertSame('0', get_config('local_reportlms', 'contactemailset'));
    }

    public function test_an_admin_with_no_contact_anywhere_is_asked(): void {
        $this->connected_site();
        $this->setAdminUser();
        $sent = [];

        $this->assertTrue(contact_email::must_ask($this->client($sent, ['contact_email_set' => false])));
    }

    public function test_an_admin_is_not_asked_when_report_lms_has_one_or_does_not_say(): void {
        $this->connected_site();
        $this->setAdminUser();
        $sent = [];

        $this->assertFalse(contact_email::must_ask($this->client($sent, ['contact_email_set' => true])));

        unset_config('contactemailcheckedat', 'local_reportlms');
        $this->assertFalse(contact_email::must_ask($this->client($sent, ['status' => 'ok'])));
    }

    public function test_an_admin_with_an_address_is_not_asked(): void {
        $this->connected_site();
        $this->setAdminUser();
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        set_config('contactemailset', '0', 'local_reportlms');
        $sent = [];

        $this->assertFalse(contact_email::must_ask($this->client($sent, ['contact_email_set' => false])));
    }

    public function test_somebody_who_is_not_an_admin_is_never_asked(): void {
        $this->connected_site();
        set_config('contactemailset', '0', 'local_reportlms');
        $this->setUser($this->getDataGenerator()->create_user());
        $sent = [];

        $this->assertFalse(contact_email::must_ask($this->client($sent, ['contact_email_set' => false])));
    }

    public function test_a_site_that_is_not_connected_is_not_asked(): void {
        $this->setAdminUser();
        set_config('contactemailset', '0', 'local_reportlms');
        $sent = [];

        $this->assertFalse(contact_email::must_ask($this->client($sent, ['contact_email_set' => false])));
    }

    public function test_saving_stores_a_valid_address_and_refuses_an_invalid_one(): void {
        $this->assertFalse(contact_email::save('nope'));
        $this->assertSame('', contact_email::current());

        $this->assertTrue(contact_email::save(' lms@example.org '));
        $this->assertSame('lms@example.org', contact_email::current());
    }

    public function test_the_privacy_metadata_names_the_contact_address(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_reportlms'));
        $found = false;

        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\external_location) {
                $found = $found || array_key_exists('contact_email', $item->get_privacy_fields());
            }
        }

        $this->assertTrue($found);
    }
}
