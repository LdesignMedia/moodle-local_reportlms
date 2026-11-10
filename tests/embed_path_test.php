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
 * Tests for the path of the embedded report.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\launch;

/**
 * The report frame always loads from the configured host.
 *
 * @covers \local_reportlms\local\config
 * @covers \local_reportlms\local\launch
 * @covers \local_reportlms\local\connect_manager
 */
final class embed_path_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('baseurl', 'https://reportlms.example.com', 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        set_config('environmentid', 'env_test0001', 'local_reportlms');
        set_config('launchsecret', 'launch-secret-for-unit-tests', 'local_reportlms');
    }

    /**
     * Stored values that are not plain paths.
     *
     * @return array<string, array{0: string}>
     */
    public static function odd_paths(): array {
        return [
            'user part' => ['@other.example/x'],
            'dot' => ['.other.example/x'],
            'line break first' => ["\n@other.example/x"],
            'carriage return first' => ["\r\n.other.example/x"],
            'line break inside' => ["/embed\n@other.example/x"],
            'backslash' => ['\\other.example/x'],
        ];
    }

    /**
     * A stored value that is not a plain path never moves the frame.
     *
     * @dataProvider odd_paths
     * @param string $stored
     */
    public function test_an_odd_stored_path_stays_on_the_configured_host(string $stored): void {
        set_config('embedpath', $stored, 'local_reportlms');

        $url = launch::embed_url();
        $parts = parse_url($url);

        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('reportlms.example.com', $parts['host']);
        $this->assertArrayNotHasKey('user', $parts);
        $this->assertStringContainsString('token=', $parts['query']);
        $this->assertStringStartsWith('https://reportlms.example.com/', $url);
    }

    public function test_a_normal_path_still_loads_with_the_token(): void {
        set_config('embedpath', '/embed', 'local_reportlms');

        $parts = parse_url(launch::embed_url());

        $this->assertSame('reportlms.example.com', $parts['host']);
        $this->assertSame('/embed', $parts['path']);
        $this->assertStringContainsString('token=', $parts['query']);
    }

    public function test_the_push_path_keeps_its_leading_slash(): void {
        set_config('pushpath', 'api/v1/push/batch', 'local_reportlms');

        $this->assertSame('/api/v1/push/batch', config::pushpath());
    }

    /**
     * A client that answers a connect with the given embed path.
     *
     * @param string|null $embedpath
     *
     * @return api_client
     */
    private function client_answering(?string $embedpath): api_client {
        return new class (config::baseurl(), $embedpath) extends api_client {
            /** @var string|null */
            private $embedpath;

            public function __construct(string $baseurl, ?string $embedpath) {
                parent::__construct($baseurl);
                $this->embedpath = $embedpath;
            }

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
                    'embed_path' => $this->embedpath,
                ];
            }
        };
    }

    public function test_a_connect_without_an_embed_path_replaces_the_stored_one(): void {
        set_config('embedpath', '/old/place', 'local_reportlms');

        (new connect_manager($this->client_answering(null)))->connect();

        $this->assertSame(config::DEFAULT_EMBED_PATH, get_config('local_reportlms', 'embedpath'));
    }

    public function test_a_connect_stores_the_path_it_was_given(): void {
        (new connect_manager($this->client_answering('/frame')))->connect();

        $this->assertSame('/frame', get_config('local_reportlms', 'embedpath'));
    }

    public function test_disconnecting_removes_the_embed_path_as_well(): void {
        (new connect_manager($this->client_answering('/frame')))->connect();

        connect_manager::disconnect();

        $this->assertFalse(get_config('local_reportlms', 'embedpath'));
        $this->assertFalse(get_config('local_reportlms', 'launchsecret'));
    }
}
