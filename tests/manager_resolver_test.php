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
 * Tests for the manager resolver.
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

/**
 * Who manages whom, and whose department wins.
 *
 * This is the class that decides whose training progress a person can see, so
 * the cases below are deliberately about the boundaries rather than the happy
 * path: a manager with nothing filled in, two managers over one learner, a
 * grant at the wrong level, and a learner nobody manages.
 *
 * @covers \local_reportlms\local\manager_resolver
 */
final class manager_resolver_test extends \advanced_testcase {
    /** @var \stdClass The department profile field every test maps against. */
    private $departmentfield;

    /** @var \stdClass */
    private $sectorfield;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        manager_resolver::reset();

        // Coding the org tree needs the salt a connect would have generated.
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        $map = org::fieldmap();
        $this->sectorfield = $this->make_field($map['sector']);
        $this->departmentfield = $this->make_field($map['department']);
    }

    /**
     * A text profile field, since the generator has no helper for one.
     *
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

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
     * @param int $userid
     * @param string $level sector or department
     * @param string $value
     */
    private function set_field(int $userid, string $level, string $value): void {
        global $DB;

        $field = $level === 'sector' ? $this->sectorfield : $this->departmentfield;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * Give a user the manage capability in a context, through a real role.
     *
     * @param int $userid
     * @param \context $context
     */
    private function make_manager(int $userid, \context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $userid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        manager_resolver::reset();
    }

    public function test_a_participant_takes_their_managers_department(): void {
        // The rule the whole model rests on: one person is responsible for the
        // group, and the group inherits their place in the tree.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $membership = manager_resolver::membership();

        $this->assertSame('Rotterdam', $membership[$learner->id]->department);
        $this->assertSame((int) $manager->id, $membership[$learner->id]->managerid);
    }

    public function test_the_org_collector_applies_that_precedence(): void {
        // The resolver being right is not enough; the collector has to use it,
        // and that is what actually reaches the wire.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $collected = org::collect();
        $bylearner = [];

        foreach ($collected->members as $member) {
            $bylearner[$member->userid] = $member->nodekey;
        }

        $this->assertSame('department:Rotterdam', $bylearner[$learner->id]);
        // And Utrecht never becomes a node at all, because nobody is left in it.
        $this->assertNotContains('department:Utrecht', array_column($collected->nodes, 'key'));
    }

    public function test_a_manager_with_no_department_places_nobody(): void {
        // The failure that would otherwise be silent: the capability is set up
        // but the manager's own field is empty, so the group has nowhere to go.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $this->assertSame([], manager_resolver::membership());
        $this->assertSame([(int) $manager->id], manager_resolver::unplaceable());

        // And the learner keeps their own value rather than vanishing.
        $bylearner = [];
        foreach (org::collect()->members as $member) {
            $bylearner[$member->userid] = $member->nodekey;
        }
        $this->assertSame('department:Utrecht', $bylearner[$learner->id]);
    }

    public function test_a_learner_nobody_manages_keeps_their_own_department(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->assertSame([], manager_resolver::membership());

        $bylearner = [];
        foreach (org::collect()->members as $member) {
            $bylearner[$member->userid] = $member->nodekey;
        }
        $this->assertSame('department:Utrecht', $bylearner[$learner->id]);
    }

    public function test_a_learner_with_nothing_lands_in_unassigned(): void {
        $learner = $this->getDataGenerator()->create_user();

        $bylearner = [];
        foreach (org::collect()->members as $member) {
            $bylearner[$member->userid] = $member->nodekey;
        }

        $this->assertSame(org::UNASSIGNED, $bylearner[$learner->id]);
    }

    public function test_a_category_grant_covers_the_courses_beneath_it(): void {
        $category = $this->getDataGenerator()->create_category();
        $one = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $two = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $elsewhere = $this->getDataGenerator()->create_course();

        $manager = $this->getDataGenerator()->create_user();
        $this->set_field($manager->id, 'department', 'Rotterdam');

        $inside = $this->getDataGenerator()->create_user();
        $outside = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($inside->id, $two->id);
        $this->getDataGenerator()->enrol_user($outside->id, $elsewhere->id);

        $this->make_manager($manager->id, \context_coursecat::instance($category->id));

        $membership = manager_resolver::membership();

        $this->assertArrayHasKey($inside->id, $membership);
        $this->assertArrayNotHasKey($outside->id, $membership);

        $courseids = manager_resolver::managers()[$manager->id]->courseids;
        sort($courseids);
        $this->assertSame([(int) $one->id, (int) $two->id], $courseids);
    }

    public function test_a_system_grant_reaches_every_course(): void {
        // This is how an organisation-wide manager happens without anyone
        // choosing viewall, so it needs to be true on purpose, not by accident.
        $one = $this->getDataGenerator()->create_course();
        $two = $this->getDataGenerator()->create_course();

        $manager = $this->getDataGenerator()->create_user();
        $this->set_field($manager->id, 'department', 'Rotterdam');

        $here = $this->getDataGenerator()->create_user();
        $there = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($here->id, $one->id);
        $this->getDataGenerator()->enrol_user($there->id, $two->id);

        $this->make_manager($manager->id, \context_system::instance());

        $membership = manager_resolver::membership();

        $this->assertArrayHasKey($here->id, $membership);
        $this->assertArrayHasKey($there->id, $membership);
    }

    public function test_a_learner_managed_twice_lands_in_one_department(): void {
        // v1.x supports one department per user. Which manager wins is settled
        // by id order rather than by whichever query came back first, so the
        // result does not move between runs.
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($first->id, 'department', 'Rotterdam');
        $this->set_field($second->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->getDataGenerator()->enrol_user($learner->id, $other->id);

        $this->make_manager($first->id, \context_course::instance($course->id));
        $this->make_manager($second->id, \context_course::instance($other->id));

        $membership = manager_resolver::membership();

        $this->assertCount(1, array_filter([$membership[$learner->id] ?? null]));
        $this->assertContains($membership[$learner->id]->department, ['Rotterdam', 'Utrecht']);
    }

    public function test_a_manager_is_recorded_against_their_own_node(): void {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'sector', 'Transport');
        $this->set_field($manager->id, 'department', 'Rotterdam');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $managers = org::collect()->managers;
        $keys = array_column($managers, 'nodekey', 'userid');

        // The department key carries its sector, so the manager is recorded
        // against the same node their participants were just placed in.
        $this->assertSame('department:Transport/Rotterdam', $keys[$manager->id]);
    }

    public function test_the_state_hash_moves_when_the_manager_graph_moves(): void {
        // If it did not, a role change would never be pushed and the SaaS would
        // keep serving the old scope.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $before = org::state_hash(org::collect());

        $this->make_manager($manager->id, \context_course::instance($course->id));

        $this->assertNotSame($before, org::state_hash(org::collect()));
    }

    public function test_a_manager_may_drill_into_their_own_node_only(): void {
        // The drill-through arrives as a plain URL, so editing the node in the
        // address bar is the way round every filter. This is where that stops.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $this->setUser($manager);

        $mine = \local_reportlms\local\pseudonym::node('department', 'Rotterdam');
        $theirs = \local_reportlms\local\pseudonym::node('department', 'Utrecht');

        // Their own node is fine, and asserting no exception is the point.
        access::require_scope($mine);
        $this->assertTrue(true);

        $this->expectException(\moodle_exception::class);
        access::require_scope($theirs);
    }

    public function test_an_unfiltered_drill_through_is_refused_for_a_manager(): void {
        // No node at all would list every department on the site.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->getDataGenerator()->enrol_user(
            $this->getDataGenerator()->create_user()->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $this->setUser($manager);

        $this->expectException(\moodle_exception::class);
        access::require_scope('');
    }

    public function test_viewall_drills_anywhere(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEWALL, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $viewer->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($viewer);

        // Including with no node, which is the whole organisation.
        access::require_scope('');
        access::require_scope(\local_reportlms\local\pseudonym::node('department', 'Utrecht'));
        $this->assertTrue(true);
    }

    public function test_an_inherited_department_keeps_its_managers_sector(): void {
        // The bug this pins: inheriting the department but keeping your own
        // sector builds a node key that no real person carries, so label_map
        // has nothing to resolve it against and the dashboard shows the hash.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'sector', 'Transport');
        $this->set_field($manager->id, 'department', 'Rotterdam');

        // A different sector of their own, which must not survive.
        $this->set_field($learner->id, 'sector', 'Office');
        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        $bylearner = [];
        foreach (org::collect()->members as $member) {
            $bylearner[$member->userid] = $member->nodekey;
        }

        $this->assertSame('department:Transport/Rotterdam', $bylearner[$learner->id]);
        $this->assertNotSame('department:Office/Rotterdam', $bylearner[$learner->id]);
    }

    public function test_every_node_the_collector_emits_can_be_named(): void {
        // The end-to-end version: whatever keys reach the wire, the label map
        // has to be able to turn all of them back into words. A code with no
        // label is a hash on somebody's dashboard.
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->set_field($manager->id, 'sector', 'Transport');
        $this->set_field($manager->id, 'department', 'Rotterdam');
        $this->set_field($learner->id, 'sector', 'Office');
        $this->set_field($learner->id, 'department', 'Utrecht');

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->make_manager($manager->id, \context_course::instance($course->id));

        \local_reportlms\local\label_map::purge();
        $map = \local_reportlms\local\label_map::all();

        foreach (org::collect()->nodes as $node) {
            [$level, $value] = explode(':', $node->key, 2);
            $code = \local_reportlms\local\pseudonym::node($level, $value);

            $this->assertArrayHasKey($code, $map, "No label for node {$node->key}");
        }
    }
}
