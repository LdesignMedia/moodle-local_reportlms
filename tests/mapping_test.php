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
 * Tests for the hand-built organisation.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\collector\org;
use local_reportlms\local\manager_resolver;
use local_reportlms\local\mapping;

/**
 * Two rules, and everything here tests one of them.
 *
 * Manual beats auto-detect beats the learner's own field, and being named a
 * manager grants the report without any role assignment.
 *
 * @covers \local_reportlms\local\mapping
 */
final class mapping_test extends \advanced_testcase {
    /** @var \stdClass */
    private $departmentfield;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        manager_resolver::reset();
        mapping::reset();

        $this->departmentfield = $this->make_field(org::fieldmap()['department']);
    }

    /**
     * The whole point of the editor: a row somebody typed wins.
     *
     * A profile field is a guess derived from an HR export. A row in here is a
     * statement of fact about the organisation, so when they disagree the
     * person wins.
     */
    public function test_a_hand_placed_learner_beats_their_profile_field(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->set_department($learner->id, 'Utrecht');

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);

        $this->assertSame('department:Rotterdam', $this->node_of($learner->id));
    }

    /**
     * And the unit exists in the tree even when no profile field mentions it,
     * or the membership would point at a node nobody declared.
     */
    public function test_a_hand_built_unit_is_declared_in_the_tree(): void {
        $learner = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);

        $keys = array_column(org::collect()->nodes, 'key');

        $this->assertContains('department:Rotterdam', $keys);
    }

    /**
     * A department under a sector gets the same compound key a derived one
     * would, so the two are the same node rather than two that look alike.
     */
    public function test_a_unit_under_a_sector_keys_the_same_way_as_a_derived_one(): void {
        $sector = mapping::save_node('Operations', 'sector');
        $node = mapping::save_node('Rotterdam', 'department', $sector);

        $this->assertSame('department:Operations/Rotterdam', mapping::nodekey($node));
    }

    /**
     * Releasing hands them back, which is what makes a mistake survivable.
     */
    public function test_releasing_a_learner_returns_them_to_auto_detect(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->set_department($learner->id, 'Utrecht');

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);
        mapping::release((int) $learner->id);

        $this->assertSame('department:Utrecht', $this->node_of($learner->id));
    }

    /**
     * One unit per learner. Placing somewhere new moves them rather than
     * adding a second claim, because the tree cannot express two and storing
     * it would mean writing data nothing can read.
     */
    public function test_placing_a_learner_twice_moves_them(): void {
        $learner = $this->getDataGenerator()->create_user();

        $first = mapping::save_node('Rotterdam', 'department');
        $second = mapping::save_node('Utrecht', 'department');

        mapping::place((int) $learner->id, $first);
        mapping::place((int) $learner->id, $second);

        $this->assertSame([(int) $learner->id => $second], array_map('intval', mapping::membership()));
    }

    /**
     * Deleting a unit does not delete the people in it.
     */
    public function test_deleting_a_unit_hands_its_people_back(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->set_department($learner->id, 'Utrecht');

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);
        mapping::delete_node($node);

        $this->assertSame([], mapping::membership());
        $this->assertSame('department:Utrecht', $this->node_of($learner->id));
    }

    /**
     * Being a manager is data, not a permission: naming somebody opens the
     * report for them with no role assignment anywhere.
     */
    public function test_naming_a_manager_opens_the_report_for_them(): void {
        $user = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Rotterdam', 'department');

        $this->setUser($user);
        $this->assertFalse(access::can_view());

        mapping::add_manager($node, (int) $user->id, null);
        manager_resolver::reset();

        $this->assertTrue(access::can_view());
        $this->assertFalse(access::can_view_all(), 'A named manager runs one unit, not the site.');
    }

    /**
     * And it does not hand them the editor: stating a fact about the
     * organisation is not the authority to rewrite it.
     */
    public function test_naming_a_manager_does_not_grant_the_editor(): void {
        $user = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Rotterdam', 'department');
        mapping::add_manager($node, (int) $user->id, null);

        $this->setUser($user);

        $this->assertFalse(access::can_manage());
    }

    /**
     * Naming a role means everyone holding it, which is how a site with staff
     * turnover avoids editing the table every time somebody joins.
     */
    public function test_naming_a_role_covers_everyone_holding_it(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $node = mapping::save_node('Rotterdam', 'department');

        mapping::add_manager($node, null, (int) $roleid);

        $this->setUser($user);
        $this->assertFalse(access::can_view(), 'Not holding the role yet.');

        role_assign($roleid, $user->id, \context_system::instance()->id);

        $this->assertTrue(access::can_view());
    }

    /**
     * Somebody else's unit is still somebody else's.
     */
    public function test_a_named_manager_does_not_see_another_unit(): void {
        $user = $this->getDataGenerator()->create_user();
        $mine = mapping::save_node('Rotterdam', 'department');
        mapping::save_node('Utrecht', 'department');

        mapping::add_manager($mine, (int) $user->id, null);

        $this->setUser($user);

        $visible = access::visible_codes([
            \local_reportlms\local\pseudonym::node('department', 'Rotterdam'),
            \local_reportlms\local\pseudonym::node('department', 'Utrecht'),
        ]);

        $this->assertContains(\local_reportlms\local\pseudonym::node('department', 'Rotterdam'), $visible);
        $this->assertNotContains(\local_reportlms\local\pseudonym::node('department', 'Utrecht'), $visible);
    }

    /**
     * A row naming both a user and a role would have two answers to "who does
     * this mean", so the role wins and the user is dropped.
     */
    public function test_a_manager_row_names_a_user_or_a_role_never_both(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $node = mapping::save_node('Rotterdam', 'department');

        mapping::add_manager($node, (int) $user->id, (int) $roleid);

        $row = $DB->get_record('local_reportlms_manager', ['nodeid' => $node]);

        $this->assertNull($row->userid);
        $this->assertEquals($roleid, $row->roleid);
    }

    /**
     * The learner's coded node, as the collector would send it.
     *
     * @param int $userid
     *
     * @return string|null
     */
    /**
     * A manager the capability route finds, running the given department.
     *
     * Built the way manager_resolver actually looks: somebody holding :view in a
     * course context, with a department on their own profile, and a participant
     * in that course for them to manage. Anything less is not detected at all
     * and the test would pass for the wrong reason.
     *
     * @param string $department
     *
     * @return \stdClass The manager.
     */
    private function a_manager_of(string $department): \stdClass {
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->set_department($manager->id, $department);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $manager->id, \context_course::instance($course->id)->id);

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        manager_resolver::reset();
        mapping::reset();

        return $manager;
    }

    private function node_of(int $userid): ?string {
        mapping::reset();
        manager_resolver::reset();

        foreach (org::collect()->members as $member) {
            if ((int) $member->userid === $userid) {
                return $member->nodekey;
            }
        }

        return null;
    }

    /**
     * @param int $userid
     * @param string $value
     */
    private function set_department(int $userid, string $value): void {
        global $DB;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $this->departmentfield->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

        // db/install.php creates the rlms_* fields in the phpunit baseline, so
        // the field this asks for may already exist. Reuse it: a second row
        // with the same shortname doubles every join that resolves by it.
        $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if ($existing) {
            return $existing;
        }

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

        return $DB->get_record('user_info_field', ['id' => $id]);
    }

    /**
     * Moving a naming is one act, not a removal and an addition.
     */
    public function test_a_naming_can_be_moved_to_another_unit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $from = mapping::save_node('Sales', 'department');
        $to = mapping::save_node('Support', 'department');

        mapping::add_manager($from, (int) $user->id, null);
        $rows = mapping::managers();
        $row = reset($rows);

        mapping::move_manager((int) $row->id, $to);

        $after = mapping::managers();
        $moved = reset($after);

        $this->assertCount(1, $after);
        $this->assertSame($to, (int) $moved->nodeid);
    }

    /**
     * Moving onto a unit somebody already manages collapses into it.
     *
     * The same person against the same unit twice is not a stronger statement,
     * it is a duplicate row, and the register exists to be read.
     */
    public function test_moving_onto_an_existing_naming_does_not_duplicate_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $from = mapping::save_node('Sales', 'department');
        $to = mapping::save_node('Support', 'department');

        mapping::add_manager($from, (int) $user->id, null);
        mapping::add_manager($to, (int) $user->id, null);

        $rows = mapping::managers();
        $first = reset($rows);

        mapping::move_manager((int) $first->id, $to);

        $this->assertCount(1, mapping::managers());
    }

    /**
     * The import turns the departments detected managers run into units.
     *
     * Units and namings only. Placing a learner by hand is what stops
     * auto-detect moving them again, so doing it wholesale here would freeze
     * every department against the next HR upload.
     */
    public function test_importing_creates_units_and_namings_but_not_memberships(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->a_manager_of('Rotterdam');

        $this->assertTrue(mapping::has_departments_to_import());
        $this->assertSame(1, mapping::import_manager_departments());

        $names = array_map(static fn($node) => $node->name, mapping::nodes());
        $this->assertContains('Rotterdam', $names);
        $this->assertCount(1, mapping::managers());
        $this->assertSame([], mapping::membership(), 'Nobody should be placed by hand.');
    }

    /**
     * A department that is already a unit is not added twice.
     */
    public function test_importing_leaves_units_that_already_exist_alone(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->a_manager_of('Rotterdam');
        mapping::save_node('Rotterdam', 'department');

        $this->assertSame(0, mapping::import_manager_departments());
        $this->assertCount(1, mapping::nodes());
    }

    /**
     * The offer stops being made once it has been taken.
     *
     * No flag remembers that somebody pressed it. The condition is "there is
     * something left to import", so accepting removes the reason to ask and a
     * site that never wants it is never asked twice about the same department.
     */
    public function test_the_offer_disappears_once_taken(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->a_manager_of('Rotterdam');
        mapping::import_manager_departments();

        $this->assertFalse(mapping::has_departments_to_import());
    }

    /**
     * An administrator is not a manager and their reach is not a department.
     *
     * They hold every capability everywhere, so the detector finds them running
     * whatever they can see. Turning that into units would invent an
     * organisation out of an access level.
     */
    public function test_an_administrator_contributes_no_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(mapping::has_departments_to_import());
        $this->assertSame(0, mapping::import_manager_departments());
    }
}
