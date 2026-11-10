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
 * Tests for sending the contact address as soon as it is saved.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\config;
use local_reportlms\local\contact_email;

/**
 * The address goes out on its own signed call; the push stays the fallback.
 *
 * @covers \local_reportlms\local\contact_email::send_now
 * @covers \local_reportlms\local\contact_email::setting_updated
 * @covers \local_reportlms\local\api_client::contact
 */
final class contact_send_now_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A client that records what contact was given and answers from the script.
     *
     * @param array $calls Filled with one entry per contact call.
     * @param array|\Throwable $answer What contact answers, or what it throws.
     *
     * @return api_client
     */
    private function client(array &$calls, $answer): api_client {
        return new class (config::baseurl(), $calls, $answer) extends api_client {
            /** @var array */
            private $calls;

            /** @var array|\Throwable */
            private $answer;

            /**
             * Constructor.
             *
             * @param string $baseurl
             * @param array $calls
             * @param array|\Throwable $answer
             */
            public function __construct(string $baseurl, array &$calls, $answer) {
                parent::__construct($baseurl);
                $this->calls = &$calls;
                $this->answer = $answer;
            }

            /**
             * Record the call and answer from the script.
             *
             * @param string $apikey
             * @param string $signingsecret
             * @param string $email
             * @return array
             */
            public function contact(string $apikey, string $signingsecret, string $email): array {
                $this->calls[] = ['apikey' => $apikey, 'signingsecret' => $signingsecret, 'email' => $email];

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /**
     * A connected site.
     */
    private function connected_site(): void {
        set_config('apikey', 'k', 'local_reportlms');
        set_config('signingsecret', 's', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
    }

    public function test_a_connected_site_sends_the_address_and_keeps_the_answer(): void {
        $this->connected_site();
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $calls = [];

        $this->assertTrue(contact_email::send_now($this->client($calls, ['contact_email_set' => true])));

        $this->assertSame([['apikey' => 'k', 'signingsecret' => 's', 'email' => 'lms@example.org']], $calls);
        $this->assertSame('1', get_config('local_reportlms', 'contactemailset'));
        // The push's own record, so the next push does not resend it for this.
        $this->assertSame(sha1('lms@example.org'), get_config('local_reportlms', 'lastcontacthash'));
        $this->assertFalse(contact_email::unsent());
    }

    public function test_an_answer_of_none_is_kept_too(): void {
        $this->connected_site();
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $calls = [];

        $this->assertTrue(contact_email::send_now($this->client($calls, ['contact_email_set' => false])));

        $this->assertSame('0', get_config('local_reportlms', 'contactemailset'));
    }

    public function test_a_site_that_is_not_connected_sends_nothing(): void {
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        $calls = [];

        $this->assertFalse(contact_email::send_now($this->client($calls, ['contact_email_set' => true])));

        $this->assertSame([], $calls);
        $this->assertTrue(contact_email::unsent());
    }

    public function test_a_site_without_an_address_sends_nothing(): void {
        $this->connected_site();
        $calls = [];

        $this->assertFalse(contact_email::send_now($this->client($calls, ['contact_email_set' => true])));

        $this->assertSame([], $calls);
        $this->assertFalse(get_config('local_reportlms', 'contactemailset'));
    }

    public function test_a_failure_returns_false_and_leaves_the_push_to_send_it(): void {
        $this->connected_site();
        set_config('contactemail', 'lms@example.org', 'local_reportlms');
        set_config('contactemailset', '0', 'local_reportlms');

        $failures = [
            new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'down'),
            new \RuntimeException('anything else'),
        ];

        foreach ($failures as $failure) {
            $calls = [];

            $this->assertFalse(contact_email::send_now($this->client($calls, $failure)));

            $this->assertCount(1, $calls);
            $this->assertSame('0', get_config('local_reportlms', 'contactemailset'));
            $this->assertTrue(contact_email::unsent());
        }
    }

    public function test_saving_the_setting_sends_it(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $this->setAdminUser();
        $this->connected_site();
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        // Answered by the curl test double, so nothing leaves the test.
        \curl::mock_response(json_encode(['contact_email_set' => true]));

        admin_write_settings((object) ['s_local_reportlms_contactemail' => 'lms@example.org']);

        $this->assertSame('lms@example.org', contact_email::current());
        $this->assertSame('1', get_config('local_reportlms', 'contactemailset'));
        $this->assertFalse(contact_email::unsent());
    }

    public function test_saving_the_setting_still_works_when_report_lms_is_unreachable(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $this->setAdminUser();
        $this->connected_site();
        // Nothing listening there, so the call fails.
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        admin_write_settings((object) ['s_local_reportlms_contactemail' => 'lms@example.org']);

        $this->assertSame('lms@example.org', contact_email::current());
        $this->assertTrue(contact_email::unsent());
    }
}
