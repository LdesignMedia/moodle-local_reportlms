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
use local_reportlms\local\manager_resolver;

/**
 * @covers \local_reportlms\local\access
 */
final class access_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // The coding key. Every node code is an HMAC against it, so the tests
        // that ask what a viewer may resolve cannot mint a code without one.
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        // resetAfterTest() rolls back the database and leaves static properties
        // alone, so without this the second test in this class resolves the
        // first test's managers against a database where they no longer exist.
        manager_resolver::reset();
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

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
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

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
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

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
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
     * Moodle's own manager role grants nothing here, at any level.
     *
     * This test used to assert the opposite: "the manager archetype carries all
     * three by default". It was rewritten rather than deleted, because that
     * default was the problem. It meant the natural way to make somebody a
     * Report LMS manager was to hand them Moodle's manager role, and that role
     * carries 656 capabilities including moodle/user:delete.
     */
    public function test_moodles_manager_role_grants_nothing_at_system_level(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        role_assign($role->id, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertFalse(access::can_view());
        $this->assertFalse(access::can_view_all());
        $this->assertFalse(access::can_manage());
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

    /**
     * The archetype defaults are gone, and that is asserted rather than
     * assumed. They came off because Moodle's manager role carries 656
     * capabilities including moodle/user:delete, so defaulting to it told every
     * customer that making somebody a Report LMS manager meant handing them the
     * ability to delete the course and the people in it.
     */
    public function test_the_manager_archetype_grants_nothing_by_default(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role(['archetype' => 'manager']);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        $this->setUser($user);

        $this->assertFalse(access::can_view());
        $this->assertFalse(access::can_view_all());
        $this->assertFalse(access::can_manage());
    }

    /**
     * :manage opens the editor and shows its holder nobody's numbers.
     *
     * Somebody who may state a fact about the organisation is not thereby
     * entitled to read the consequences of it. That is what lets a training
     * coordinator maintain the tree without being given sight of everybody's
     * progress.
     */
    public function test_manage_opens_the_editor_and_no_data(): void {
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);

        $this->setUser($user);

        $this->assertTrue(access::can_manage());
        $this->assertFalse(access::can_view());
        $this->assertFalse(access::can_view_all());
    }

    /**
     * And the other way round: a manager reads, and does not rewrite the tree.
     */
    public function test_a_manager_may_not_edit_the_mapping(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        $this->setUser($user);

        $this->assertTrue(access::can_view());
        $this->assertFalse(access::can_manage());
    }

    /**
     * A manager may name their own department, and not the one next door.
     *
     * The live gap this closes: the label service resolved any code for anybody
     * who could open the report, so a manager of one department could ask it
     * about every other department on the site and be told what each is called.
     */
    public function test_a_manager_may_not_resolve_another_departments_name(): void {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_department($manager->id, 'Rotterdam');
        $this->set_department($learner->id, 'Utrecht');
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $manager->id, \context_course::instance($course->id)->id);

        $mine = \local_reportlms\local\pseudonym::node('department', 'Rotterdam');
        $theirs = \local_reportlms\local\pseudonym::node('department', 'Elders');

        $this->setUser($manager);

        $visible = access::visible_codes([$mine, $theirs]);

        $this->assertContains($mine, $visible);
        $this->assertNotContains($theirs, $visible);
    }

    /**
     * The sector above their department comes with it, because a picker that
     * cannot name the parent is broken.
     */
    public function test_the_sector_above_their_own_node_resolves(): void {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_department($manager->id, 'Rotterdam');
        $this->set_sector($manager->id, 'Operations');
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $manager->id, \context_course::instance($course->id)->id);

        $sector = \local_reportlms\local\pseudonym::node('sector', 'Operations');

        $this->setUser($manager);

        $this->assertContains($sector, access::visible_codes([$sector]));
    }

    /**
     * An unscoped viewer keeps naming everything, which is what viewall means.
     */
    public function test_viewall_resolves_anything(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, $context->id, true);
        assign_capability(access::VIEWALL, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);

        $codes = [
            \local_reportlms\local\pseudonym::node('department', 'Anywhere'),
            \local_reportlms\local\pseudonym::node('department', 'Elsewhere'),
        ];

        $this->assertSame($codes, access::visible_codes($codes));
    }

    /**
     * A viewer who manages nobody names nothing, rather than everything.
     */
    public function test_a_viewer_with_no_scope_resolves_nothing(): void {
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);

        $this->setUser($user);

        $this->assertSame([], access::visible_codes([
            \local_reportlms\local\pseudonym::node('department', 'Anywhere'),
        ]));
    }

    /**
     * @param int $userid
     * @param string $value
     */
    private function set_department(int $userid, string $value): void {
        $this->set_profile_field($userid, 'department', $value);
    }

    /**
     * @param int $userid
     * @param string $value
     */
    private function set_sector(int $userid, string $value): void {
        $this->set_profile_field($userid, 'sector', $value);
    }

    /**
     * @param int $userid
     * @param string $level
     * @param string $value
     */
    private function set_profile_field(int $userid, string $level, string $value): void {
        global $DB;

        $shortname = \local_reportlms\local\collector\org::fieldmap()[$level];
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);

        if (!$field) {
            // A full row, not a partial one. Moodle reads param1 and param2 for
            // a text field and a half-built record resolves to nothing, which
            // looks exactly like a user who has no department.
            $categoryid = $DB->insert_record('user_info_category', (object) [
                'name' => 'Report LMS ' . $shortname,
                'sortorder' => 1,
            ]);

            $id = $DB->insert_record('user_info_field', (object) [
                'shortname' => $shortname,
                'name' => $shortname,
                'categoryid' => $categoryid,
                'datatype' => 'text',
                'sortorder' => 1,
                'required' => 0,
                'locked' => 0,
                'visible' => 2,
                'forceunique' => 0,
                'signup' => 0,
                'param1' => 30,
                'param2' => 2048,
            ]);

            $field = $DB->get_record('user_info_field', ['id' => $id]);
        }

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }
}
