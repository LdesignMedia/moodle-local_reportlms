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
 * A unit stores what it draws from, and the answer is worked out fresh.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\org;
use local_reportlms\local\mapping;

/**
 * The point of the whole thing is the second test in this file.
 *
 * A unit used to store the people a course had resolved to, once, on the day
 * somebody pressed the button. The sentence "everybody on the induction course"
 * was true when it was said and never again, so a department of four hundred
 * was four hundred rows the next reorganisation could not move.
 *
 * @covers \local_reportlms\local\mapping
 */
final class sources_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/user/profile/lib.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        mapping::reset();
    }

    /**
     * A course source draws the people on that course.
     */
    public function test_a_course_source_draws_its_participants(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Onboarding', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);

        $this->assertSame([(int) $learner->id], mapping::members_of($node));
        $this->assertNotContains((int) $elsewhere->id, mapping::members_of($node));
    }

    /**
     * And somebody enrolled afterwards is in the unit, without anybody opening
     * the editor again. This is the whole reason sources exist.
     */
    public function test_somebody_enrolled_later_is_drawn_without_being_named(): void {
        $course = $this->getDataGenerator()->create_course();
        $node = mapping::save_node('Onboarding', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);

        $this->assertSame([], mapping::members_of($node));

        $joiner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($joiner->id, $course->id);
        mapping::reset();

        $this->assertSame([(int) $joiner->id], mapping::members_of($node));
    }

    /**
     * Nothing a source draws is written into the member table.
     *
     * The member table is the exceptions: the names somebody typed. If a source
     * wrote rows there too, the Person box could not tell the two apart, which
     * is the bug this replaced.
     */
    public function test_a_source_writes_no_member_rows(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Onboarding', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);

        $this->assertSame([(int) $learner->id], mapping::members_of($node));
        $this->assertSame([], mapping::named_members_of($node));
        $this->assertSame(0, $DB->count_records('local_reportlms_member'));
    }

    /**
     * A group and the course holding it, chosen for two units, put the person
     * in both.
     *
     * The narrower claim used to win and take the person out of the other unit,
     * which then showed a course chosen and somebody on it missing.
     */
    public function test_a_group_and_the_course_holding_it_both_draw(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $learner->id]);

        $wide = mapping::save_node('Everybody', 'department');
        $narrow = mapping::save_node('The team', 'department');

        mapping::save_sources($wide, ['courses' => [$course->id]]);
        mapping::save_sources($narrow, ['groups' => [$group->id]]);

        $this->assertSame($this->ids($wide, $narrow), mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::members_of($wide));
        $this->assertSame([(int) $learner->id], mapping::members_of($narrow));
    }

    /**
     * A category and a course in it, for two units: both, for the same reason.
     */
    public function test_a_category_and_a_course_in_it_both_draw(): void {
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $wide = mapping::save_node('The whole branch', 'department');
        $narrow = mapping::save_node('One course', 'department');

        mapping::save_sources($wide, ['categories' => [$category->id]]);
        mapping::save_sources($narrow, ['courses' => [$course->id]]);

        $this->assertSame($this->ids($wide, $narrow), mapping::resolved_membership()[(int) $learner->id]);
    }

    /**
     * One course chosen for two units puts everybody on it in both.
     *
     * The case this change exists for. It used to be called a contradiction and
     * leave the person in neither unit, while the editor showed the course
     * chosen in both.
     */
    public function test_two_units_drawing_one_course_both_hold_the_person(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        $this->getDataGenerator()->enrol_user($other->id, $course->id);

        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');

        mapping::save_sources($sales, ['courses' => [$course->id]]);
        mapping::save_sources($support, ['courses' => [$course->id]]);

        $both = [(int) $learner->id, (int) $other->id];
        sort($both);

        $this->assertSame($this->ids($sales, $support), mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame($both, $this->sorted(mapping::members_of($sales)));
        $this->assertSame($both, $this->sorted(mapping::members_of($support)));
        $this->assertSame([$sales => 2, $support => 2], $this->sorted_keys(mapping::member_counts()));
        $this->assertSame(['department:Sales', 'department:Support'], $this->nodes_of((int) $learner->id));
        $this->assertSame(0, mapping::unplaced_count() - $this->unplaced_without([$learner, $other]));
    }

    /**
     * Naming somebody into a third unit adds it, and leaves the units their
     * sources put them in alone.
     */
    public function test_naming_somebody_adds_a_unit(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');
        $named = mapping::save_node('Named', 'department');

        mapping::save_sources($sales, ['courses' => [$course->id]]);
        mapping::save_sources($support, ['courses' => [$course->id]]);
        mapping::place((int) $learner->id, $named);
        mapping::reset();

        $this->assertSame($this->ids($sales, $support, $named), mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::named_members_of($named));
        $this->assertSame([], mapping::named_members_of($sales));
    }

    /**
     * A placement the migration left adds its unit to the ones a source draws.
     *
     * It is a name in that unit's Person box, and a name there that did nothing
     * showed somebody in a unit's box and missing from its people list.
     */
    public function test_a_placement_the_migration_left_adds_its_unit(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $old = mapping::save_node('Where they were', 'department');
        $new = mapping::save_node('Where the course says', 'department');

        $this->adopt((int) $learner->id, $old);
        mapping::save_sources($new, ['courses' => [$course->id]]);

        $this->assertSame($this->ids($old, $new), mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::members_of($old));
        $this->assertSame([], mapping::named_members_of($old), 'Not a name anybody typed.');
    }

    /**
     * With no source at all, the migration's placement is the whole answer. It
     * is the whole organisation on a site that has not started using sources.
     */
    public function test_the_migration_stands_where_no_source_speaks(): void {
        $learner = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Where they were', 'department');

        $this->adopt((int) $learner->id, $node);
        mapping::reset();

        $this->assertSame([$node => $node], mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([], mapping::named_members_of($node), 'Not a name anybody typed.');
    }

    /**
     * A typed name in the unit a source already draws them into is still one
     * membership, not two.
     */
    public function test_a_named_person_on_a_unit_that_draws_them_is_in_it_once(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Drawn and named', 'department');

        mapping::save_sources($node, ['courses' => [$course->id]]);
        mapping::place((int) $learner->id, $node);
        mapping::reset();

        $this->assertSame([$node => $node], mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::members_of($node));
        $this->assertSame(['department:Drawn and named'], $this->nodes_of((int) $learner->id));
    }

    /**
     * Removing somebody from one unit leaves them in the others.
     *
     * Remove used to delete every row the person had anywhere, which was the
     * same thing while a person could be in one unit only.
     */
    public function test_removing_somebody_from_one_unit_keeps_the_others(): void {
        $learner = $this->getDataGenerator()->create_user();
        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');
        $migrated = mapping::save_node('Migrated', 'department');

        mapping::place((int) $learner->id, $sales);
        mapping::place((int) $learner->id, $support);
        $this->adopt((int) $learner->id, $migrated);

        mapping::release((int) $learner->id, $sales);

        $this->assertSame($this->ids($support, $migrated), mapping::resolved_membership()[(int) $learner->id]);

        mapping::release((int) $learner->id, $migrated);

        $this->assertSame([$support => $support], mapping::resolved_membership()[(int) $learner->id]);
    }

    /**
     * Remove takes out the row, never what a source draws: that stays until the
     * source changes, which is why the editor offers Remove only on rows.
     */
    public function test_removing_a_named_person_a_source_draws_leaves_them_drawn(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Drawn and named', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);
        mapping::place((int) $learner->id, $node);

        mapping::release((int) $learner->id, $node);

        $this->assertSame([(int) $learner->id], mapping::members_of($node));
        $this->assertSame([], mapping::named_members_of($node));
    }

    /**
     * A person in two departments of one sector is one person in that sector.
     *
     * The editor's roll-up summed the departments, which was only right while a
     * person could be in one unit.
     */
    public function test_the_roll_up_counts_a_person_once(): void {
        $course = $this->getDataGenerator()->create_course();
        $shared = $this->getDataGenerator()->create_user();
        $only = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($shared->id, $course->id);

        $sector = mapping::save_node('Operations', 'sector');
        $north = mapping::save_node('North', 'department', $sector);
        $south = mapping::save_node('South', 'department', $sector);

        mapping::save_sources($north, ['courses' => [$course->id]]);
        mapping::save_sources($south, ['courses' => [$course->id]]);
        mapping::place((int) $only->id, $south);
        mapping::reset();

        $counts = mapping::rolled_up_counts();

        $this->assertSame(1, $counts[$north]);
        $this->assertSame(2, $counts[$south]);
        $this->assertSame(2, $counts[$sector], 'Two people, not three.');
    }

    /**
     * A profile value draws the people who hold it, which is what makes a site
     * with good HR data and no capabilities work.
     */
    public function test_a_profile_value_draws_the_people_who_hold_it(): void {
        $field = $this->make_field('afdeling');
        $here = $this->getDataGenerator()->create_user();
        $there = $this->getDataGenerator()->create_user();

        $this->set_value($field, (int) $here->id, 'Rotterdam');
        $this->set_value($field, (int) $there->id, 'Utrecht');

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::save_sources($node, ['profilevalues' => [$field->id . ':Rotterdam']]);

        $this->assertSame([(int) $here->id], mapping::members_of($node));
    }

    /**
     * Saving replaces the list, because the form shows the whole list: a source
     * that is gone from it is one somebody removed.
     */
    public function test_saving_replaces_what_the_unit_draws_from(): void {
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        $node = mapping::save_node('Onboarding', 'department');

        mapping::save_sources($node, ['courses' => [$first->id, $second->id]]);
        $this->assertCount(2, mapping::sources_of($node));

        mapping::save_sources($node, ['courses' => [$second->id]]);

        $left = mapping::sources_of($node);

        $this->assertCount(1, $left);
        $this->assertSame((int) $second->id, (int) reset($left)->itemid);
    }

    /**
     * The tree that reaches Report LMS is the resolved one, so a person a
     * course drew arrives in their unit without ever being a member row.
     */
    public function test_the_pushed_tree_carries_people_a_source_drew(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Onboarding', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);

        $this->assertSame('department:Onboarding', $this->node_of((int) $learner->id));
    }

    /**
     * Deleting a unit takes its sources with it, or they would draw people into
     * a node nobody can see.
     */
    public function test_deleting_a_unit_removes_what_it_drew_from(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $node = mapping::save_node('Onboarding', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);

        mapping::delete_node($node);

        $this->assertSame(0, $DB->count_records('local_reportlms_source', ['nodeid' => $node]));
    }

    /**
     * The Person box holds every individual placement, whoever made it.
     *
     * A row the migration carried over is still a statement about one person,
     * still visible in the people list and still removable, so it belongs in
     * the box that lists individuals. Only the people a source drew are absent,
     * because taking one of those out of the box would not remove them.
     */
    public function test_the_person_box_holds_migrated_placements_too(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $drawn = $this->getDataGenerator()->create_user();
        $typed = $this->getDataGenerator()->create_user();
        $carried = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($drawn->id, $course->id);

        $node = mapping::save_node('Flexpool A', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);
        mapping::place((int) $typed->id, $node);

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $node,
            'userid' => $carried->id,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::reset();

        $inbox = mapping::placed_members_of($node);
        sort($inbox);

        $expected = [(int) $typed->id, (int) $carried->id];
        sort($expected);

        $this->assertSame($expected, $inbox);
        $this->assertNotContains((int) $drawn->id, $inbox, 'A source is not an individual.');

        // And the unit still holds all three.
        $this->assertCount(3, mapping::members_of($node));
    }

    /**
     * Saving an untouched form changes nothing, and in particular does not
     * promote a migrated placement into a typed one.
     *
     * Promotion would be silent and one-way: those rows outrank every source
     * once they are typed names, so a unit that had merely been opened would
     * stop responding to the sources somebody adds to it afterwards.
     */
    public function test_a_migrated_placement_survives_being_saved_back(): void {
        global $DB;

        $carried = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Flexpool A', 'department');

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $node,
            'userid' => $carried->id,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::reset();

        // What the editor does with a form nobody edited: the box came back
        // holding exactly what it opened with, so nothing is added or removed.
        $wanted = array_flip(mapping::placed_members_of($node));
        $current = array_flip(mapping::placed_members_of($node));

        $this->assertSame([], array_diff_key($wanted, $current));
        $this->assertSame([], array_diff_key($current, $wanted));

        $this->assertSame(
            mapping::ADOPTED,
            $DB->get_field('local_reportlms_member', 'source', ['nodeid' => $node, 'userid' => $carried->id])
        );
    }

    /**
     * The headcount beside a course is the number choosing it would draw.
     *
     * A promise counted differently from the thing it describes is worse than
     * no number, so this asserts the two against each other rather than against
     * a literal.
     */
    public function test_the_course_headcount_matches_what_it_would_draw(): void {
        $course = $this->getDataGenerator()->create_course();
        $quiet = $this->getDataGenerator()->create_course();

        foreach (range(1, 3) as $ignored) {
            $learner = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        }

        $counts = mapping::participant_counts();

        $this->assertSame(3, $counts[(int) $course->id]);
        $this->assertSame(count(mapping::participants_of([(int) $course->id])), $counts[(int) $course->id]);
        $this->assertArrayNotHasKey((int) $quiet->id, $counts, 'A course nobody is on has no entry.');
    }

    /**
     * The units table counts the people a unit actually holds, sources and
     * names together, and counts them the same way opening the unit does.
     */
    public function test_the_unit_headcount_matches_the_people_behind_it(): void {
        $course = $this->getDataGenerator()->create_course();
        $drawn = $this->getDataGenerator()->create_user();
        $named = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($drawn->id, $course->id);

        $node = mapping::save_node('Onboarding', 'department');
        $empty = mapping::save_node('Nobody here', 'department');

        mapping::save_sources($node, ['courses' => [$course->id]]);
        mapping::place((int) $named->id, $node);
        mapping::reset();

        $counts = mapping::member_counts();

        $this->assertSame(2, $counts[$node], 'One drawn by the course, one named.');
        $this->assertSame(count(mapping::members_of($node)), $counts[$node]);
        $this->assertArrayNotHasKey($empty, $counts, 'An empty unit is absent, and reads as zero.');
    }

    /**
     * Node ids as the resolver keys them, in node id order.
     *
     * @param int ...$nodeids
     *
     * @return array<int, int>
     */
    private function ids(int ...$nodeids): array {
        sort($nodeids);

        return array_combine($nodeids, $nodeids);
    }

    /**
     * A list of ints, sorted.
     *
     * @param array $ids User ids.
     *
     * @return array
     */
    private function sorted(array $ids): array {
        sort($ids);

        return $ids;
    }

    /**
     * A map, sorted by key.
     *
     * @param array $map Node id to count.
     *
     * @return array
     */
    private function sorted_keys(array $map): array {
        ksort($map);

        return $map;
    }

    /**
     * How many people are in no unit, leaving out these users.
     *
     * @param array $users User records.
     *
     * @return int
     */
    private function unplaced_without(array $users): int {
        global $DB;

        return $DB->count_records_select('user', 'deleted = 0 AND id > 2') - count($users);
    }

    /**
     * Write a placement the way the migration did.
     *
     * @param int $userid
     * @param int $nodeid
     *
     * @return void
     */
    private function adopt(int $userid, int $nodeid): void {
        global $DB;

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $nodeid,
            'userid' => $userid,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::reset();
    }

    /**
     * Every node key the collector sends for this user, sorted.
     *
     * @param int $userid
     *
     * @return array<int, string>
     */
    private function nodes_of(int $userid): array {
        mapping::reset();

        $keys = [];

        foreach (org::collect()->members as $member) {
            if ((int) $member->userid === $userid) {
                $keys[] = $member->nodekey;
            }
        }

        return $keys;
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
     * A drawn row says which source drew it, by name.
     *
     * The editor used to print "drawn by a source" on every one of these, which
     * names our concept instead of answering the question: somebody who cannot
     * remove the row needs to know what to go and change.
     */
    public function test_a_drawn_person_names_the_source_that_drew_them(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'VCA basis']);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $node = mapping::save_node('Transport', 'department');
        mapping::save_sources($node, ['courses' => [$course->id]]);
        mapping::reset();

        $drawn = mapping::drawn_by($node);

        $this->assertSame(
            [['kind' => 'course', 'name' => 'VCA basis']],
            $drawn[(int) $learner->id]
        );
    }

    /**
     * Two sources drawing one person name both, because either one would have to
     * change to move them.
     */
    public function test_somebody_two_sources_draw_names_both(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'BHV Basis']);
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Nieuwe medewerkers']);
        $learner = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);
        cohort_add_member($cohort->id, $learner->id);

        $node = mapping::save_node('Warehouse', 'department');
        mapping::save_sources($node, ['courses' => [$course->id], 'cohorts' => [$cohort->id]]);
        mapping::reset();

        $named = array_column(mapping::drawn_by($node)[(int) $learner->id], 'name');
        sort($named);

        $this->assertSame(['BHV Basis', 'Nieuwe medewerkers'], $named);
    }

    /**
     * A source belonging to another unit says nothing about this one's rows.
     */
    public function test_it_only_names_this_units_own_sources(): void {
        $ours = $this->getDataGenerator()->create_course(['fullname' => 'Ours']);
        $theirs = $this->getDataGenerator()->create_course(['fullname' => 'Theirs']);
        $learner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($learner->id, $ours->id);
        $this->getDataGenerator()->enrol_user($other->id, $theirs->id);

        $mine = mapping::save_node('Mine', 'department');
        $yours = mapping::save_node('Yours', 'department');
        mapping::save_sources($mine, ['courses' => [$ours->id]]);
        mapping::save_sources($yours, ['courses' => [$theirs->id]]);
        mapping::reset();

        $drawn = mapping::drawn_by($mine);

        $this->assertSame([['kind' => 'course', 'name' => 'Ours']], $drawn[(int) $learner->id]);
        $this->assertArrayNotHasKey((int) $other->id, $drawn);
    }

    /**
     * A text profile field.
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
     * Give one user a value in one field.
     *
     * @param \stdClass $field
     * @param int $userid
     * @param string $value
     */
    private function set_value(\stdClass $field, int $userid, string $value): void {
        global $DB;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }
}
