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
 * Tests for the launch token's claim set.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\launch;

/**
 * The token is the contract with the SaaS, so its claim set is pinned here:
 * a claim that silently disappears breaks the other side without any test on
 * this side noticing.
 *
 * @covers \local_reportlms\local\launch
 */
final class launch_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        set_config('environmentid', 'env_test0001', 'local_reportlms');
        set_config('launchsecret', 'launch-secret-for-unit-tests', 'local_reportlms');
    }

    /**
     * Mint a token as the current user and decode its claims.
     *
     * @return array<string, mixed>
     */
    private function claims(): array {
        $mint = new \ReflectionMethod(launch::class, 'mint');
        $token = $mint->invoke(null);

        [, $payload] = explode('.', $token);

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    public function test_an_administrator_may_configure(): void {
        $this->setAdminUser();

        $this->assertTrue($this->claims()['configure']);
    }

    public function test_a_plain_viewer_may_not_and_the_claim_still_travels(): void {
        // Present-and-false rather than absent: the SaaS defaults an absent
        // claim to false for older plugins, and that default must never be
        // what decides for a current one.
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $roleid = create_role('Reporting manager', 'reportingmanager', '');
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);
        $this->setUser($user);

        $claims = $this->claims();

        $this->assertArrayHasKey('configure', $claims);
        $this->assertFalse($claims['configure']);
    }

    public function test_a_custom_role_granted_configure_may(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Deck editor', 'deckeditor', '');
        assign_capability(access::CONFIGURE, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertTrue($this->claims()['configure']);
    }

    public function test_the_claim_set_the_saas_relies_on_is_intact(): void {
        $this->setAdminUser();

        $claims = $this->claims();

        foreach (
            ['iss', 'env', 'sub', 'role', 'scope', 'cron_last_run_at',
            'push_task_last_run_at', 'configure', 'iat', 'exp', 'jti'] as $key
        ) {
            $this->assertArrayHasKey($key, $claims, "Claim {$key} went missing.");
        }
    }
}
