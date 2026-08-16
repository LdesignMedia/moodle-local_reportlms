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
use local_reportlms\local\capability_holders;
use local_reportlms\local\mapping;

/**
 * Who may open the report, and which of its numbers they are served.
 *
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
        capability_holders::reset();
        mapping::reset();
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
     * answered "no" for exactly the people the report was built for: somebody
     * granted the capability on one course was turned away at the door with
     * "you do not currently have permissions".
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
        $manager = $this->getDataGenerator()->create_user();

        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::save_node('Elders', 'department');
        mapping::add_manager($nodeid, (int) $manager->id, null);
        mapping::reset();

        $mine = \local_reportlms\local\pseudonym::node('department', 'Rotterdam');
        $theirs = \local_reportlms\local\pseudonym::node('department', 'Elders');

        $this->setUser($manager);

        $visible = access::visible_codes([$mine, $theirs]);

        $this->assertContains($mine, $visible);
        $this->assertNotContains($theirs, $visible);
    }

    /**
     * Holding the capability and being named nothing resolves nothing.
     *
     * The capability used to place its holder: whoever held :view on a course
     * was read as managing its participants, and their own profile field became
     * the department they could name. It now opens the door and says nothing
     * about which room is theirs, so somebody nobody named is served no labels
     * at all rather than the labels of a department guessed for them.
     */
    public function test_holding_the_capability_names_no_department(): void {
        $course = $this->getDataGenerator()->create_course();
        $holder = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        mapping::save_node('Rotterdam', 'department');
        mapping::reset();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $holder->id, \context_course::instance($course->id)->id);

        capability_holders::reset();
        $this->setUser($holder);

        $this->assertTrue(access::can_view(), 'The door still opens.');
        $this->assertSame([], access::visible_codes([
            \local_reportlms\local\pseudonym::node('department', 'Rotterdam'),
        ]));
    }

    /**
     * The sector above their department comes with it, because a picker that
     * cannot name the parent is broken.
     */
    public function test_the_sector_above_their_own_node_resolves(): void {
        $manager = $this->getDataGenerator()->create_user();

        $sectorid = mapping::save_node('Operations', 'sector');
        $nodeid = mapping::save_node('Rotterdam', 'department', $sectorid);
        mapping::add_manager($nodeid, (int) $manager->id, null);
        mapping::reset();

        $sector = \local_reportlms\local\pseudonym::node('sector', 'Operations');

        $this->setUser($manager);

        $this->assertContains($sector, access::visible_codes([$sector]));
    }

    /**
     * Managing a department does not hand over the sector it sits in.
     *
     * The leak this file exists to stop coming back. Report LMS expands every
     * scope node to its subtree before serving anything, so a sector in the
     * token is a grant over every department inside it. The sector was being
     * added so the picker could show a parent's name, and a manager of one
     * department could read the whole sector.
     *
     * It stayed invisible while the only managed units sat at the root, and
     * appeared the day one of them was moved under a sector.
     */
    public function test_managing_a_department_does_not_grant_its_sector(): void {
        $manager = $this->getDataGenerator()->create_user();

        $sectorid = mapping::save_node('Operatie en Techniek', 'sector');
        $mine = mapping::save_node('Flexpool A', 'department', $sectorid);
        mapping::save_node('Sloterdijk Groep E', 'department', $sectorid);
        mapping::add_manager($mine, (int) $manager->id, null);
        mapping::reset();

        $this->setUser($manager);

        $scope = \local_reportlms\local\launch::viewer_scope();

        $this->assertSame(
            [\local_reportlms\local\pseudonym::node('department', 'Operatie en Techniek/Flexpool A')],
            $scope,
            'Their unit, and nothing above or beside it.'
        );
    }

    /**
     * They are still told what the sector is called, because a department drawn
     * under an unnamed parent is a picker nobody can use.
     *
     * Knowing a name is not permission to read the numbers under it, which is
     * the whole reason these are two different questions.
     */
    public function test_a_manager_is_told_the_name_of_the_sector_above_them(): void {
        $manager = $this->getDataGenerator()->create_user();

        $sectorid = mapping::save_node('Operatie en Techniek', 'sector');
        $mine = mapping::save_node('Flexpool A', 'department', $sectorid);
        mapping::add_manager($mine, (int) $manager->id, null);
        mapping::reset();

        $this->setUser($manager);

        $sector = \local_reportlms\local\pseudonym::node('sector', 'Operatie en Techniek');

        $this->assertContains($sector, access::visible_codes([$sector]));
        $this->assertNotContains($sector, \local_reportlms\local\launch::viewer_scope());
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
}
