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
 * Tests for sending a claim code to Report LMS.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\form\claim_form;
use local_reportlms\local\api_client;
use local_reportlms\local\claim_code;
use local_reportlms\local\config;

/**
 * The claim code proves which Report LMS account runs this site.
 *
 * It goes out on its own keyed and signed call, so only a connected site can
 * send one, and whatever Report LMS answers is shown to the administrator.
 *
 * @covers \local_reportlms\local\claim_code
 * @covers \local_reportlms\local\api_client::claim
 * @covers \local_reportlms\local\api_client::claim_answer
 * @covers \local_reportlms\form\claim_form
 */
final class claim_code_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A client that records what claim was given and answers from the script.
     *
     * @param array $calls Filled with one entry per claim call.
     * @param array|\Throwable $answer What claim answers, or what it throws.
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
             * @param string $code
             * @return array
             */
            public function claim(string $apikey, string $signingsecret, string $code): array {
                $this->calls[] = ['apikey' => $apikey, 'signingsecret' => $signingsecret, 'code' => $code];

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

    public function test_a_connected_site_sends_the_code_with_its_own_keys(): void {
        $this->connected_site();
        $calls = [];

        $result = claim_code::send('K7QF-29XM', $this->client($calls, [
            'status' => 'claimed',
            'message' => 'Done. This site is now proven for your Report LMS account.',
        ]));

        $this->assertSame([['apikey' => 'k', 'signingsecret' => 's', 'code' => 'K7QF29XM']], $calls);
        $this->assertTrue($result['success']);
        $this->assertSame('Done. This site is now proven for your Report LMS account.', $result['message']);
    }

    public function test_the_code_goes_out_in_one_form(): void {
        $this->connected_site();

        foreach (['k7qf-29xm', ' K7QF 29XM ', 'K7QF29XM', "k7qf\t29xm\n"] as $typed) {
            $calls = [];

            claim_code::send($typed, $this->client($calls, ['status' => 'claimed', 'message' => 'Done.']));

            $this->assertSame('K7QF29XM', $calls[0]['code'], 'Typed as ' . json_encode($typed));
        }
    }

    public function test_a_code_with_nothing_in_it_is_not_sent(): void {
        $this->connected_site();

        foreach (['', '   ', '--', str_repeat('A', claim_code::MAX_LENGTH + 1)] as $typed) {
            $calls = [];

            $result = claim_code::send($typed, $this->client($calls, ['status' => 'claimed', 'message' => 'Done.']));

            $this->assertSame([], $calls, 'Typed as ' . json_encode($typed));
            $this->assertFalse($result['success']);
            $this->assertSame(get_string('claim:invalid', 'local_reportlms'), $result['message']);
        }
    }

    public function test_a_site_that_is_not_connected_sends_nothing(): void {
        $calls = [];

        $result = claim_code::send('K7QF-29XM', $this->client($calls, ['status' => 'claimed', 'message' => 'Done.']));

        $this->assertSame([], $calls);
        $this->assertFalse($result['success']);
        $this->assertSame(get_string('claim:notconnected', 'local_reportlms'), $result['message']);
    }

    public function test_a_refusal_comes_back_as_the_words_report_lms_sent(): void {
        $this->connected_site();
        $calls = [];
        $words = 'This LMS address has a licence on another Report LMS account. Write to us and we will sort it out.';

        $result = claim_code::send('K7QF-29XM', $this->client(
            $calls,
            new \moodle_exception('error:claimrefused', 'local_reportlms', '', $words)
        ));

        $this->assertCount(1, $calls);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString($words, $result['message']);
    }

    public function test_a_site_that_cannot_reach_report_lms_is_told_so(): void {
        $this->connected_site();
        $calls = [];

        $result = claim_code::send('K7QF-29XM', $this->client(
            $calls,
            new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'timed out')
        ));

        $this->assertFalse($result['success']);
        $this->assertSame(get_string('error:connectfailed', 'local_reportlms', 'timed out'), $result['message']);
    }

    public function test_an_answer_without_words_still_says_it_worked(): void {
        $this->connected_site();

        foreach ([[], ['message' => ''], ['message' => '   '], ['message' => ['not', 'words']]] as $answer) {
            $calls = [];

            $result = claim_code::send('K7QF-29XM', $this->client($calls, $answer));

            $this->assertTrue($result['success']);
            $this->assertSame(get_string('claim:success', 'local_reportlms'), $result['message']);
        }
    }

    public function test_a_success_is_read_from_the_answer(): void {
        $answer = api_client::claim_answer(200, json_encode(['status' => 'moved', 'message' => 'Done.']));

        $this->assertSame(['status' => 'moved', 'message' => 'Done.'], $answer);
    }

    public function test_a_refusal_carries_the_words_of_the_answer(): void {
        $refusals = [
            422 => 'That claim code is not right.',
            409 => 'This LMS address has no Report LMS licence yet.',
            401 => 'This API key has expired.',
        ];

        foreach ($refusals as $httpcode => $words) {
            try {
                api_client::claim_answer($httpcode, json_encode(['status' => 'any', 'message' => $words]));
                $this->fail('HTTP ' . $httpcode . ' must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:claimrefused', $e->errorcode);
                $this->assertSame(get_string('error:claimrefused', 'local_reportlms', $words), $e->getMessage());
            }
        }
    }

    public function test_an_answer_without_words_is_refused_by_its_status_code(): void {
        $answers = [
            500 => '<html>Server error</html>',
            404 => '',
            // A success that is not JSON is not a success: nothing says what happened.
            200 => 'OK',
        ];

        foreach ($answers as $httpcode => $body) {
            try {
                api_client::claim_answer($httpcode, $body);
                $this->fail('HTTP ' . $httpcode . ' without JSON must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:claimrefused', $e->errorcode);
                $this->assertStringContainsString('HTTP ' . $httpcode, $e->getMessage());
            }
        }
    }

    public function test_too_many_tries_gets_its_own_sentence(): void {
        try {
            api_client::claim_answer(429, json_encode(['message' => 'Too Many Attempts.']));
            $this->fail('HTTP 429 must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:claimtoomany', $e->errorcode);
        }
    }

    public function test_the_claim_goes_out_over_the_wire(): void {
        $this->connected_site();
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        // Answered by the curl test double, so nothing leaves the test.
        \curl::mock_response(json_encode(['status' => 'claimed', 'message' => 'Done.']));

        $result = claim_code::send('K7QF-29XM');

        $this->assertTrue($result['success']);
        $this->assertSame('Done.', $result['message']);
    }

    public function test_a_plain_http_address_is_refused_before_the_code_is_sent(): void {
        $client = new api_client('http://reportlms.example.com');

        try {
            $client->claim('key', 'secret', 'K7QF29XM');
            $this->fail('A plain http address must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:insecurebaseurl', $e->errorcode);
        }
    }

    public function test_only_a_site_administrator_can_open_the_claim_page(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        // Plugin settings files only run for somebody who holds site:config.
        $this->setAdminUser();
        $page = admin_get_root(true, false)->locate('local_reportlms_claim');

        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertSame(['moodle/site:config'], $page->req_capability);
        // Reached from the button on the settings page, never from the menu.
        $this->assertTrue($page->is_hidden());

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse($page->check_access());

        $this->setAdminUser();
        $this->assertTrue($page->check_access());
    }

    public function test_the_settings_page_offers_the_claim_code_only_while_connected(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();

        // A settings page keys its settings by plugin and name run together.
        $header = static function (): string {
            return admin_get_root(true, true)->locate('local_reportlms')->settings->local_reportlmsheader->description;
        };

        $this->assertStringContainsString(get_string('connect:statusdisconnected', 'local_reportlms'), $header());
        $this->assertStringNotContainsString('/local/reportlms/claim.php', $header());

        $this->connected_site();

        $this->assertStringContainsString('/local/reportlms/claim.php', $header());
        $this->assertStringContainsString(get_string('claim:button', 'local_reportlms'), $header());
    }

    public function test_the_form_takes_a_code(): void {
        $this->setAdminUser();

        claim_form::mock_submit(['code' => 'k7qf-29xm']);
        $data = (new claim_form(new \moodle_url('/local/reportlms/claim.php')))->get_data();

        $this->assertNotNull($data);
        $this->assertSame('k7qf-29xm', $data->code);
    }

    public function test_the_form_refuses_a_code_with_nothing_in_it(): void {
        $this->setAdminUser();

        foreach (['', '---', '!!!'] as $typed) {
            claim_form::mock_submit(['code' => $typed]);
            $data = (new claim_form(new \moodle_url('/local/reportlms/claim.php')))->get_data();

            $this->assertNull($data, 'Typed as ' . json_encode($typed));
        }
    }
}
