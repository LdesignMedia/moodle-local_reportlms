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
 * Tests for the organisation somebody stated.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\collector\org;
use local_reportlms\local\mapping;

/**
 * Two rules, and everything here tests one of them.
 *
 * A unit's people are the rows somebody wrote, and being named a manager grants
 * the report without any role assignment.
 *
 * @covers \local_reportlms\local\mapping
 */
final class mapping_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        mapping::reset();
    }

    /**
     * The whole point of the editor: a row somebody typed is where they are.
     *
     * This used to assert that the row beat the learner's profile field, which
     * was the only rule that mattered while the tree was derived from one.
     * Nothing derives now, so the row is not the winner of an argument, it is
     * the only answer there is.
     */
    public function test_a_placed_learner_is_in_the_unit_they_were_placed_in(): void {
        $learner = $this->getDataGenerator()->create_user();

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);

        $this->assertSame('department:Rotterdam', $this->node_of($learner->id));
    }

    /**
     * Nobody is placed by a profile field, however well it describes them.
     *
     * The regression this whole change exists to prevent coming back. A field
     * can still be pointed at as a source, which writes a placement; what it
     * cannot do is place somebody on its own.
     */
    public function test_a_profile_field_places_nobody(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $field = $this->make_field('rlms_department');

        $DB->insert_record('user_info_data', (object) [
            'userid' => $learner->id,
            'fieldid' => $field->id,
            'data' => 'Utrecht',
            'dataformat' => 0,
        ]);

        $this->assertSame('department:Unassigned', $this->node_of($learner->id));
    }

    /**
     * A unit exists in the tree because somebody made it, whether or not
     * anybody is in it yet.
     */
    public function test_a_hand_built_unit_is_declared_in_the_tree(): void {
        $learner = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);

        $keys = array_column(org::collect()->nodes, 'key');

        $this->assertContains('department:Rotterdam', $keys);
    }

    /**
     * A department under a sector keys as `department:Sector/Name`.
     *
     * The property the migration rested on: the same string the tree used to be
     * derived into, so every fact Report LMS holds stayed attached to its node
     * when the sites stopped deriving.
     */
    public function test_a_unit_under_a_sector_keys_the_same_way_as_a_derived_one(): void {
        $sector = mapping::save_node('Operations', 'sector');
        $node = mapping::save_node('Rotterdam', 'department', $sector);

        $this->assertSame('department:Operations/Rotterdam', mapping::nodekey($node));
    }

    /**
     * Releasing takes them out, which is what makes a mistake survivable.
     *
     * They land in Unassigned rather than back in a department worked out from
     * their profile, because there is nowhere else for them to land. That is
     * the cost of a stated tree and the reason the settings page counts them.
     */
    public function test_releasing_a_learner_returns_them_to_unassigned(): void {
        $learner = $this->getDataGenerator()->create_user();

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);
        mapping::release((int) $learner->id);

        $this->assertSame('department:Unassigned', $this->node_of($learner->id));
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

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);
        mapping::delete_node($node);

        $this->assertSame([], mapping::membership());
        $this->assertSame('department:Unassigned', $this->node_of($learner->id));
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
        mapping::reset();

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
    private function node_of(int $userid): ?string {
        mapping::reset();

        foreach (org::collect()->members as $member) {
            if ((int) $member->userid === $userid) {
                return $member->nodekey;
            }
        }

        return null;
    }


    /**
     * A text profile field, reusing one the install baseline already made.
     *
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
     * Choosing courses chooses their participants.
     *
     * The thing an administrator knows is "everybody on these courses". Naming
     * four hundred people one at a time is why that list stayed
     * empty on every connected site.
     */
    public function test_the_people_on_the_chosen_courses_are_the_candidates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $onit = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($onit->id, $course->id);
        $this->getDataGenerator()->enrol_user($elsewhere->id, $other->id);

        $node = mapping::save_node('Rotterdam', 'department');

        $this->assertSame(
            [(int) $onit->id],
            mapping::candidates_from_courses([(int) $course->id], $node)
        );
    }

    /**
     * A manager of another unit is a learner here.
     *
     * The head of Sales still takes the compliance course, so filtering every
     * manager out would make it impossible to put them where they work.
     */
    public function test_an_administrator_is_left_out_and_a_manager_is_not(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $elsewheresmanager = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($elsewheresmanager->id, $course->id);

        global $USER;
        $this->getDataGenerator()->enrol_user($USER->id, $course->id);

        $theirs = mapping::save_node('Sales', 'department');
        mapping::add_manager($theirs, (int) $elsewheresmanager->id, null);
        mapping::reset();

        $here = mapping::save_node('Rotterdam', 'department');

        $this->assertSame(
            [(int) $elsewheresmanager->id],
            mapping::candidates_from_courses([(int) $course->id], $here)
        );
    }

    /**
     * Somebody already in the unit is not offered twice, so the number in the
     * confirmation is what pressing it changes.
     */
    public function test_people_already_in_the_unit_are_not_offered_again(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $node);

        $this->assertSame([], mapping::candidates_from_courses([(int) $course->id], $node));
    }

    /**
     * A unit's own manager is not one of its people.
     *
     * They are on the courses they run, so they arrive in their own list.
     * Measured on a real site: choosing tom's course for the unit tom manages
     * offered four people, three learners and tom. Placing him would count him
     * among the people he manages, so a department of three reads as four and
     * the person opening the report is one of its figures.
     */
    public function test_the_units_own_manager_is_not_one_of_its_candidates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($manager->id, $course->id);
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::add_manager($node, (int) $manager->id, null);
        mapping::reset();

        $this->assertSame(
            [(int) $learner->id],
            mapping::candidates_from_courses([(int) $course->id], $node)
        );
    }

    /**
     * A category means the whole branch, not the courses filed directly in it.
     *
     * "Everyone under Sales" is what somebody means by choosing Sales, and a
     * site that files its courses two levels down would otherwise get nobody.
     */
    public function test_a_category_reaches_the_courses_beneath_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $parent = $this->getDataGenerator()->create_category(['name' => 'Sales']);
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);

        $deep = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $outside = $this->getDataGenerator()->create_course();

        $inbranch = $this->getDataGenerator()->create_user();
        $notinbranch = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($inbranch->id, $deep->id);
        $this->getDataGenerator()->enrol_user($notinbranch->id, $outside->id);

        $node = mapping::save_node('Rotterdam', 'department');

        $this->assertSame(
            [(int) $inbranch->id],
            mapping::candidates_from(['categories' => [(int) $parent->id]], $node)
        );
    }

    /**
     * A cohort is a list somebody already keeps, so it is one of the ways in.
     *
     * Cohort members need no enrolment, so they come from a different query
     * than the course ones and have to be filtered the same way.
     */
    public function test_a_cohort_names_its_members(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $member = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();

        cohort_add_member($cohort->id, $member->id);

        $node = mapping::save_node('Rotterdam', 'department');

        $this->assertSame(
            [(int) $member->id],
            mapping::candidates_from(['cohorts' => [(int) $cohort->id]], $node)
        );
        $this->assertNotContains((int) $stranger->id, mapping::candidates_from(
            ['cohorts' => [(int) $cohort->id]],
            $node
        ));
    }

    /**
     * Two sources naming the same person name them once.
     */
    public function test_the_same_person_from_two_sources_appears_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cohort = $this->getDataGenerator()->create_cohort();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        cohort_add_member($cohort->id, $learner->id);

        $node = mapping::save_node('Rotterdam', 'department');

        $this->assertSame([(int) $learner->id], mapping::candidates_from([
            'courses' => [(int) $course->id],
            'cohorts' => [(int) $cohort->id],
        ], $node));
    }
}
