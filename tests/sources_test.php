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
     * A group beats the course holding it, because it is the narrower thing to
     * have pointed at.
     */
    public function test_a_group_beats_the_course_that_holds_it(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $learner->id]);

        $wide = mapping::save_node('Everybody', 'department');
        $narrow = mapping::save_node('The team', 'department');

        mapping::save_sources($wide, ['courses' => [$course->id]]);
        mapping::save_sources($narrow, ['groups' => [$group->id]]);

        $this->assertSame($narrow, mapping::resolved_membership()[(int) $learner->id]);
    }

    /**
     * A course beats a whole category, for the same reason.
     */
    public function test_a_course_beats_a_category(): void {
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $wide = mapping::save_node('The whole branch', 'department');
        $narrow = mapping::save_node('One course', 'department');

        mapping::save_sources($wide, ['categories' => [$category->id]]);
        mapping::save_sources($narrow, ['courses' => [$course->id]]);

        $this->assertSame($narrow, mapping::resolved_membership()[(int) $learner->id]);
    }

    /**
     * Two units drawing the same course is a contradiction, and the person
     * lands nowhere until somebody resolves it.
     *
     * Deliberately not "lowest id wins". That is stable and meaningless, it
     * hides the contradiction behind a plausible answer, and the answer changes
     * if a unit is deleted and recreated.
     */
    public function test_two_units_drawing_one_course_leaves_the_person_unplaced(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');

        mapping::save_sources($sales, ['courses' => [$course->id]]);
        mapping::save_sources($support, ['courses' => [$course->id]]);

        $this->assertArrayNotHasKey((int) $learner->id, mapping::resolved_membership());
        $this->assertSame([(int) $learner->id], mapping::contested());
        $this->assertSame('department:Unassigned', $this->node_of((int) $learner->id));
    }

    /**
     * And naming them settles it, because that is somebody answering the
     * question the sources disagreed about.
     */
    public function test_naming_somebody_settles_a_contested_claim(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');

        mapping::save_sources($sales, ['courses' => [$course->id]]);
        mapping::save_sources($support, ['courses' => [$course->id]]);
        mapping::place((int) $learner->id, $support);
        mapping::reset();

        $this->assertSame($support, mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([], mapping::contested());
    }

    /**
     * A source beats what the migration left behind.
     *
     * The migration wrote one row per learner to reproduce a tree that had been
     * worked out from profile fields, and stored it the only way there was at
     * the time: as though somebody had typed every name. Left at that rank it
     * outranked every source, so on a migrated site a course could draw nobody,
     * because all 3,262 people were already spoken for.
     */
    public function test_a_source_beats_a_placement_the_migration_left(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $old = mapping::save_node('Where they were', 'department');
        $new = mapping::save_node('Where the course says', 'department');

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $old,
            'userid' => $learner->id,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::save_sources($new, ['courses' => [$course->id]]);

        $this->assertSame($new, mapping::resolved_membership()[(int) $learner->id]);
    }

    /**
     * With no source saying otherwise, the migration's placement stands. It is
     * the whole organisation on a site that has not started using sources.
     */
    public function test_the_migration_stands_where_no_source_speaks(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $node = mapping::save_node('Where they were', 'department');

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $node,
            'userid' => $learner->id,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::reset();

        $this->assertSame($node, mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([], mapping::named_members_of($node), 'Not a name anybody typed.');
    }

    /**
     * Two sources shouting over each other leaves the migration's answer
     * standing, because it is at least an answer.
     */
    public function test_a_contested_learner_keeps_what_the_migration_said(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $was = mapping::save_node('Where they were', 'department');
        $sales = mapping::save_node('Sales', 'department');
        $support = mapping::save_node('Support', 'department');

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $was,
            'userid' => $learner->id,
            'source' => mapping::ADOPTED,
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        mapping::save_sources($sales, ['courses' => [$course->id]]);
        mapping::save_sources($support, ['courses' => [$course->id]]);

        $this->assertSame($was, mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::contested(), 'Still worth saying out loud.');
    }

    /**
     * A typed name beats any source, however narrow.
     */
    public function test_a_named_person_beats_a_source(): void {
        $course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $drawn = mapping::save_node('Drawn', 'department');
        $named = mapping::save_node('Named', 'department');

        mapping::save_sources($drawn, ['courses' => [$course->id]]);
        mapping::place((int) $learner->id, $named);
        mapping::reset();

        $this->assertSame($named, mapping::resolved_membership()[(int) $learner->id]);
        $this->assertSame([(int) $learner->id], mapping::named_members_of($named));
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
