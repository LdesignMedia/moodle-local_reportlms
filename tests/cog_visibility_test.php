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
 * Tests for the block cog routing and the per-viewer visibility CSS.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\block_visibility;
use local_reportlms\local\mapping;

/**
 * The rule under test: you see the block only if clicking its button leads
 * somewhere that will let you in, and the cog only if an admin surface will
 * admit you.
 *
 * @covers \local_reportlms\local\block_visibility
 * @covers \local_reportlms\local\access::admin_destination
 */
final class cog_visibility_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        // The callback gates on pagetype before anything else, so every test
        // stands on a page the block can actually exist on.
        global $PAGE;
        $PAGE->set_pagetype('my-index');
    }

    /** All three keys, because is_connected() checks all three. */
    private function connect(): void {
        set_config('apikey', 'key-for-tests', 'local_reportlms');
        set_config('signingsecret', 'secret-for-tests', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
    }

    private function viewer(): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $roleid = create_role('Reporting manager', 'reportingmanager', '');
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        return $user;
    }

    // ---- admin_destination() -------------------------------------------

    public function test_an_administrator_is_routed_to_the_settings_page(): void {
        $this->setAdminUser();

        $this->assertStringContainsString('/admin/settings.php', (string) access::admin_destination());
    }

    public function test_a_manage_holder_is_routed_to_the_editor_they_can_open(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Org editor', 'orgeditor', '');
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertStringContainsString('/local/reportlms/view/manage.php', (string) access::admin_destination());
    }

    public function test_a_siteconfig_holder_without_manage_still_gets_the_settings_page(): void {
        // The one persona for whom the site:config branch does work that
        // is_siteadmin does not: settings.php sits behind $hassiteconfig,
        // so this grant alone must count as having an admin surface.
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Config holder', 'configholder', '');
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertStringContainsString('/admin/settings.php', (string) access::admin_destination());
    }

    public function test_everyone_else_has_no_admin_surface(): void {
        $this->setUser($this->viewer());

        $this->assertNull(access::admin_destination());
    }

    // ---- block_visibility::css() ---------------------------------------

    public function test_an_administrator_sees_everything(): void {
        $this->setAdminUser();

        $this->assertSame('', block_visibility::css());
    }

    public function test_a_viewer_on_a_connected_site_gets_the_block_without_the_cog(): void {
        $this->connect();
        $this->setUser($this->viewer());

        $css = block_visibility::css();

        $this->assertStringContainsString('.local-reportlms-cog', $css);
        $this->assertStringNotContainsString(':has', $css);
    }

    public function test_a_viewer_on_a_disconnected_site_sees_no_block_at_all(): void {
        $this->setUser($this->viewer());

        $this->assertStringContainsString('.block:has(.local-reportlms-block)', block_visibility::css());
    }

    public function test_an_editor_named_manager_counts_as_a_viewer(): void {
        // The other way in: no capability, no role assignment — named in the
        // mapping editor. The persona the editor exists for must not lose
        // the block to a rule written in terms of capabilities.
        $user = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Sales', 'department');
        mapping::add_manager($node, (int) $user->id, null);
        $this->connect();
        $this->setUser($user);

        $css = block_visibility::css();

        $this->assertStringContainsString('.local-reportlms-cog', $css);
        $this->assertStringNotContainsString(':has', $css);
    }

    public function test_a_plain_learner_never_sees_the_block(): void {
        $this->connect();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertStringContainsString('.block:has(.local-reportlms-block)', block_visibility::css());
    }

    public function test_a_guest_never_sees_the_block(): void {
        $this->connect();
        $this->setGuestUser();

        $this->assertStringContainsString('.block:has(.local-reportlms-block)', block_visibility::css());
    }

    public function test_pages_the_block_cannot_live_on_pay_nothing(): void {
        global $PAGE;
        $PAGE->set_pagetype('mod-quiz-view');
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame('', block_visibility::css());
    }

    public function test_the_legacy_callback_and_the_hook_agree(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reportlms/lib.php');
        $this->setAdminUser();

        $this->assertSame(block_visibility::css(), local_reportlms_before_standard_html_head());
    }
}
