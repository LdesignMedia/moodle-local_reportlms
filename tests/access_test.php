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
 * Tests for who may open the report.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;

/**
 * @covers \local_reportlms\local\access
 */
final class access_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_an_ordinary_user_may_not_open_the_report(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(access::can_view());
        $this->assertFalse(access::can_view_all());
    }

    public function test_require_view_stops_an_ordinary_user(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        access::require_view();
    }

    /**
     * The bug this file exists to stop coming back.
     *
     * These capabilities default to the manager archetype, and Moodle's manager
     * role is normally assigned at a category or a course rather than at the
     * system. Asking for the capability at the system context therefore
     * answered "no" for exactly the people the report was built for: a manager
     * of one course, correctly identified as a manager by manager_resolver, was
     * turned away at the door with "you do not currently have permissions".
     */
    public function test_a_manager_assigned_at_a_course_may_open_the_report(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role(['archetype' => 'manager']);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        $this->setUser($user);

        // Nothing at system level, which is the whole point.
        $this->assertFalse(has_capability(access::VIEW, \context_system::instance()));

        $this->assertTrue(access::can_view());
        access::require_view();
    }

    public function test_a_manager_assigned_at_a_category_may_open_the_report(): void {
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role(['archetype' => 'manager']);
        role_assign($roleid, $user->id, \context_coursecat::instance($category->id)->id);

        $this->setUser($user);

        $this->assertTrue(access::can_view());
    }

    /**
     * A manager does not see the whole organisation just for being a manager.
     *
     * :viewall shipped as a manager archetype default, which made every
     * category and course manager an organisation-wide viewer and left the
     * scoping the rest of the plugin does entirely decorative for them. Seeing
     * everybody has to be a deliberate grant.
     */
    public function test_a_manager_does_not_get_the_whole_organisation(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role(['archetype' => 'manager']);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        $this->setUser($user);

        $this->assertTrue(access::can_view());
        $this->assertFalse(access::can_view_all());
    }

    /**
     * And the asymmetry between the two checks, stated on purpose.
     *
     * :view asks whether you may open the page, which a manager of one course
     * legitimately may, so it is honoured wherever it is held. :viewall means
     * the whole organisation, and held inside one category it would have to
     * mean "everything, from inside one corner of it". That ambiguity is
     * resolved towards less rather than more.
     */
    public function test_viewall_granted_only_inside_a_category_is_not_the_whole_organisation(): void {
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEWALL, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_coursecat::instance($category->id)->id);

        $this->setUser($user);

        // They may open the report, because :viewall implies being allowed in.
        $this->assertFalse(access::can_view_all());
    }

    /**
     * Granted at system level it means exactly what it says.
     */
    public function test_viewall_at_system_level_is_the_whole_organisation(): void {
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEWALL, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);

        $this->setUser($user);

        $this->assertTrue(access::can_view_all());
    }

    /**
     * Anywhere means anywhere they hold a role, not everywhere.
     */
    public function test_a_role_without_the_capability_still_opens_nothing(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $this->setUser($user);

        $this->assertFalse(access::can_view());
    }

    /**
     * A site administrator holds every capability without being granted any.
     *
     * Worth pinning: the capabilities deliberately ship with no admin entry,
     * on the assumption Moodle's own bypass covers it. If that assumption ever
     * stopped holding, every admin would lose the report at once.
     */
    public function test_an_administrator_sees_everything_without_a_grant(): void {
        $this->setAdminUser();

        $this->assertTrue(access::can_view());
        $this->assertTrue(access::can_view_all());
    }

    /**
     * The manager archetype carries all three by default.
     */
    public function test_the_manager_role_can_view(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        role_assign($role->id, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertTrue(access::can_view());
    }

    /**
     * :view alone opens the page and grants no reach.
     *
     * The three capabilities answer different questions, and a viewer who may
     * open the report is not thereby a viewer who may see the whole
     * organisation.
     */
    public function test_view_alone_does_not_grant_the_unscoped_view(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        $this->assertTrue(access::can_view());
        $this->assertFalse(access::can_view_all());
    }

    /**
     * A viewer with no scope at all may drill into nothing.
     *
     * The drill-through arrives as a plain URL, so this is the only thing
     * standing between somebody editing the address bar and another
     * department's people, which is the one place the real names live.
     */
    public function test_a_viewer_with_no_scope_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        access::require_scope('n_something');
    }

    /**
     * :viewall drills anywhere, including with no node named.
     */
    public function test_viewall_may_drill_anywhere(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, $context->id, true);
        assign_capability(access::VIEWALL, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        // No exception is the assertion: an unscoped viewer has nothing to be
        // refused for.
        access::require_scope('n_anything_at_all');
        access::require_scope('');

        $this->assertTrue(access::can_view_all());
    }
}
