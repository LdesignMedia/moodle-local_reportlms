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
 * The organisation the site reports is the organisation somebody wrote down.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\capability_holders;
use local_reportlms\local\collector\org;
use local_reportlms\local\mapping;

/**
 * Nothing infers a tree any more, and this is the file that keeps it that way.
 *
 * The collector used to read a department and a sector off two profile fields
 * and work management out from who held local/reportlms:view on which course.
 * Both guessed at something only a person knows, and on connected sites both
 * were wrong in ways better inference cannot fix: a blank field looks exactly
 * like a wrong one, a manager of two departments placed all their people in
 * one, and an all-staff enrolment made one manager look responsible for 1,467
 * of a site's 1,495 learners.
 *
 * @covers \local_reportlms\local\collector\org
 */
final class org_collector_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/user/profile/lib.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        capability_holders::reset();
        mapping::reset();
    }

    /**
     * A learner is where somebody put them.
     */
    public function test_a_placed_learner_is_reported_in_their_unit(): void {
        $learner = $this->getDataGenerator()->create_user();
        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $nodeid);
        mapping::reset();

        $this->assertSame('department:Rotterdam', $this->node_of((int) $learner->id));
    }

    /**
     * A learner in two units is sent once per unit, in a stable order, so the
     * same organisation hashes the same on every run.
     */
    public function test_a_learner_in_two_units_is_sent_in_both(): void {
        $learner = $this->getDataGenerator()->create_user();
        $utrecht = mapping::save_node('Utrecht', 'department');
        $rotterdam = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $utrecht);
        mapping::place((int) $learner->id, $rotterdam);
        mapping::reset();

        $first = org::collect();
        $keys = [];

        foreach ($first->members as $member) {
            if ((int) $member->userid === (int) $learner->id) {
                $keys[] = $member->nodekey;
            }
        }

        $this->assertSame(['department:Rotterdam', 'department:Utrecht'], $keys);

        mapping::reset();
        $this->assertSame(org::state_hash($first), org::state_hash(org::collect()));
    }

    /**
     * Two units that share a key are one unit on the wire, so a person in both
     * is sent once. Sending the pair twice failed the whole org write.
     */
    public function test_two_units_with_one_key_send_the_learner_once(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $first = mapping::save_node('Rotterdam', 'department');

        // From before names were checked: the editor refuses this today.
        $second = $DB->insert_record('local_reportlms_node', (object) [
            'name' => 'Rotterdam',
            'parentid' => null,
            'kind' => 'department',
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::place((int) $learner->id, $first);
        mapping::place((int) $learner->id, (int) $second);
        mapping::reset();

        $rows = array_filter(org::collect()->members, fn($m) => (int) $m->userid === (int) $learner->id);

        $this->assertSame(['department:Rotterdam'], array_values(array_column($rows, 'nodekey')));
    }

    /**
     * The regression this change exists to prevent coming back.
     *
     * A profile field holding a department name places nobody. It can still be
     * pointed at as a source in the editor, which writes a placement; what it
     * cannot do is place somebody by existing.
     */
    public function test_a_department_in_a_profile_field_places_nobody(): void {
        $learner = $this->getDataGenerator()->create_user();
        $field = $this->make_field('rlms_department');
        $this->set_field($field, (int) $learner->id, 'Utrecht');

        $keys = array_column(org::collect()->nodes, 'key');

        $this->assertSame('department:Unassigned', $this->node_of((int) $learner->id));
        $this->assertNotContains('department:Utrecht', $keys, 'A field value is not a unit.');
    }

    /**
     * Holding the capability on a course makes nobody a manager of anybody.
     *
     * The 1,467 case, as a test. The grant is real, the enrolment is real, and
     * the reported tree says nothing about either.
     */
    public function test_holding_the_capability_manages_nobody(): void {
        $course = $this->getDataGenerator()->create_course();
        $holder = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->getDataGenerator()->enrol_user($holder->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $holder->id, \context_course::instance($course->id)->id);

        capability_holders::reset();

        $state = org::collect();

        $this->assertSame([], $state->managers);
        $this->assertSame('department:Unassigned', $this->node_of((int) $learner->id));
    }

    /**
     * A manager is somebody named against a unit, and they manage that unit.
     *
     * Keyed to the unit they were named against rather than to anything about
     * them, because a naming says which unit somebody runs and that is the
     * whole content of it.
     */
    public function test_a_named_manager_manages_the_unit_they_were_named_against(): void {
        $manager = $this->getDataGenerator()->create_user();
        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::add_manager($nodeid, (int) $manager->id, null);
        mapping::reset();

        $state = org::collect();

        $this->assertCount(1, $state->managers);
        $this->assertSame((int) $manager->id, (int) $state->managers[0]->userid);
        $this->assertSame('department:Rotterdam', $state->managers[0]->nodekey);
    }

    /**
     * A role named as a manager is not sent.
     *
     * The wire carries people, and a role names a set that changes on its own.
     */
    public function test_a_role_naming_does_not_travel(): void {
        $roleid = $this->getDataGenerator()->create_role();
        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::add_manager($nodeid, null, (int) $roleid);
        mapping::reset();

        $this->assertSame([], org::collect()->managers);
    }

    /**
     * An empty unit is still a unit.
     *
     * It used to reach the wire only by having a member, because a unit was a
     * profile value and a value nobody holds does not exist. A unit is now a
     * row somebody created deliberately, usually a moment before they start
     * filling it, and one that vanished in between would read as the editor
     * having failed to save it.
     */
    public function test_a_unit_with_nobody_in_it_is_still_declared(): void {
        mapping::save_node('Rotterdam', 'department');
        mapping::reset();

        $this->assertContains('department:Rotterdam', array_column(org::collect()->nodes, 'key'));
    }

    /**
     * A department under a sector carries its sector, and names it as parent.
     */
    public function test_a_department_carries_its_sector(): void {
        $sectorid = mapping::save_node('Operations', 'sector');
        mapping::save_node('Rotterdam', 'department', $sectorid);
        mapping::reset();

        $nodes = [];

        foreach (org::collect()->nodes as $node) {
            $nodes[$node->key] = $node;
        }

        $this->assertArrayHasKey('department:Operations/Rotterdam', $nodes);
        $this->assertSame('sector:Operations', $nodes['department:Operations/Rotterdam']->parent);
    }

    /**
     * Unassigned is always there, so nobody drops out of a total.
     */
    public function test_somebody_nobody_placed_is_unassigned_and_still_counted(): void {
        $learner = $this->getDataGenerator()->create_user();

        $state = org::collect();
        $userids = array_map(static fn($m) => (int) $m->userid, $state->members);

        $this->assertContains((int) $learner->id, $userids);
        $this->assertContains('department:Unassigned', array_column($state->nodes, 'key'));
    }

    /**
     * Function is still read from a profile field, because it is an attribute
     * of a person rather than a place in the tree.
     */
    public function test_function_still_comes_from_its_profile_field(): void {
        $learner = $this->getDataGenerator()->create_user();
        $field = $this->make_field(org::function_field());
        $this->set_field($field, (int) $learner->id, 'Machinist');

        foreach (org::collect()->attrs as $attr) {
            if ((int) $attr->userid === (int) $learner->id) {
                $this->assertSame('Machinist', $attr->function);

                return;
            }
        }

        $this->fail('The learner has no attributes row.');
    }

    /**
     * A site with no function field at all still collects.
     *
     * The field is looked up by id rather than joined by shortname so that its
     * absence is "nobody has one" instead of a query whose shape depends on
     * whether a row exists.
     */
    public function test_a_site_without_the_function_field_still_collects(): void {
        global $DB;

        $DB->delete_records('user_info_field', ['shortname' => org::function_field()]);

        $learner = $this->getDataGenerator()->create_user();

        foreach (org::collect()->attrs as $attr) {
            if ((int) $attr->userid === (int) $learner->id) {
                $this->assertNull($attr->function);

                return;
            }
        }

        $this->fail('The learner has no attributes row.');
    }

    /**
     * A suspended account is reported as inactive rather than dropped.
     */
    public function test_a_suspended_learner_is_reported_inactive(): void {
        $learner = $this->getDataGenerator()->create_user(['suspended' => 1]);

        foreach (org::collect()->attrs as $attr) {
            if ((int) $attr->userid === (int) $learner->id) {
                $this->assertFalse($attr->active);

                return;
            }
        }

        $this->fail('A suspended learner must still be reported.');
    }

    /**
     * The hash moves when the stated tree moves, and not otherwise.
     *
     * It is the whole mechanism by which an edit reaches Report LMS: the batch
     * builder omits the org section entirely while the hash is unchanged.
     */
    public function test_the_hash_follows_the_tree(): void {
        $before = org::state_hash(org::collect());

        mapping::save_node('Rotterdam', 'department');
        mapping::reset();

        $after = org::state_hash(org::collect());

        $this->assertNotSame($before, $after);
        $this->assertSame($after, org::state_hash(org::collect()), 'Same tree, same hash.');
    }

    /**
     * The reported node of one learner.
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

        $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);

        if ($existing) {
            return $existing;
        }

        $id = $DB->insert_record('user_info_field', (object) [
            'shortname' => $shortname,
            'name' => $shortname,
            'categoryid' => 1,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 30,
            'param2' => 2048,
        ]);

        return $DB->get_record('user_info_field', ['id' => $id]);
    }

    /**
     * Give one user a value in one profile field.
     *
     * @param \stdClass $field
     * @param int $userid
     * @param string $value
     */
    private function set_field(\stdClass $field, int $userid, string $value): void {
        global $DB;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }
}
