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
 * Tests for which unfinished completion records the collector reports.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\completion;

/**
 * A completion record is not a start.
 *
 * Moodle's daily completion task gives every enrolled student a record, so an
 * unfinished one says nothing about whether they began. Each fixture here
 * starts from exactly that record: enrolled, timestarted 0, not finished. A
 * record with no sign of a start is left out, which Report LMS reads as not
 * started.
 *
 * @covers \local_reportlms\local\collector\completion
 */
final class completion_collector_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/completionlib.php');
    }

    public function test_somebody_who_never_opened_the_course_is_left_out(): void {
        [$user, $course] = $this->enrolled_with_a_record();

        $this->assertNull($this->status($user, $course));
    }

    public function test_opening_the_course_is_a_start(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $course->id,
            'timeaccess' => time(),
        ]);

        $this->assertSame('in_progress', $this->status($user, $course));
    }

    /**
     * Moodle sets timestarted for criteria its tasks mark with nobody present:
     * a date passing, or another course being finished.
     */
    public function test_a_criterion_a_task_met_is_no_start(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $DB->set_field('course_completions', 'timestarted', time(), ['userid' => $user->id, 'course' => $course->id]);

        $this->assertNull($this->status($user, $course));
    }

    /**
     * A teacher can tick an activity off for somebody who never opened it.
     */
    public function test_a_completed_activity_is_a_start_without_a_visit(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);

        $this->assertSame('in_progress', $this->status($user, $course));
    }

    public function test_an_activity_that_is_not_complete_is_no_start(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid,
            'userid' => $user->id,
            'completionstate' => COMPLETION_INCOMPLETE,
            'timemodified' => time(),
        ]);

        $this->assertNull($this->status($user, $course));
    }

    /**
     * Unenrolling deletes the visit and keeps the attempts, so somebody
     * re-enrolled has attempts and no visit.
     */
    public function test_a_quiz_attempt_is_a_start_without_a_visit(): void {
        [$user, $course] = $this->enrolled_with_a_record();
        $this->attempt($user, $course, false);

        $this->assertSame('in_progress', $this->status($user, $course));
    }

    /**
     * A teacher previewing the quiz is not the learner sitting it.
     */
    public function test_a_preview_is_no_start(): void {
        [$user, $course] = $this->enrolled_with_a_record();
        $this->attempt($user, $course, true);

        $this->assertNull($this->status($user, $course));
    }

    /**
     * A test graded offline: they did it, even if they never signed in.
     */
    public function test_a_grade_on_an_activity_is_a_start(): void {
        [$user, $course] = $this->enrolled_with_a_record();
        $this->grade($user, $course, 7.5);

        $this->assertSame('in_progress', $this->status($user, $course));
    }

    /**
     * The gradebook keeps empty grade rows for people with no grade yet.
     */
    public function test_an_empty_grade_row_is_no_start(): void {
        [$user, $course] = $this->enrolled_with_a_record();
        $this->grade($user, $course, null);

        $this->assertNull($this->status($user, $course));
    }

    /**
     * "Absent" in an attendance register, or a zero entered for missing work.
     */
    public function test_a_grade_at_the_minimum_is_no_start(): void {
        [$user, $course] = $this->enrolled_with_a_record();
        $this->grade($user, $course, 0.0);

        $this->assertNull($this->status($user, $course));
    }

    /**
     * An exam sat on paper and typed into the gradebook.
     */
    public function test_a_grade_on_a_manual_item_is_a_start(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $item = $this->getDataGenerator()->create_grade_item(['courseid' => $course->id, 'itemtype' => 'manual']);
        $DB->insert_record('grade_grades', (object) [
            'itemid' => $item->id,
            'userid' => $user->id,
            'rawgrade' => 60,
            'finalgrade' => 60,
            'timemodified' => time(),
        ]);

        $this->assertSame('in_progress', $this->status($user, $course));
    }

    public function test_a_finished_record_stays_completed(): void {
        global $DB;

        [$user, $course] = $this->enrolled_with_a_record();
        $DB->set_field('course_completions', 'timecompleted', 1735732800, ['userid' => $user->id, 'course' => $course->id]);

        $row = $this->row($user, $course);
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertSame(1735732800, $row->completed_at);
    }

    /**
     * A visit to one course says nothing about another.
     */
    public function test_each_course_is_judged_on_its_own(): void {
        global $DB;

        [$user, $opened] = $this->enrolled_with_a_record();
        [, $untouched] = $this->enrolled_with_a_record($user);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $user->id,
            'courseid' => $opened->id,
            'timeaccess' => time(),
        ]);

        $this->assertSame('in_progress', $this->status($user, $opened));
        $this->assertNull($this->status($user, $untouched));
    }

    /**
     * And one learner's visit says nothing about the next learner.
     */
    public function test_each_learner_is_judged_on_their_own(): void {
        global $DB;

        [$visitor, $course] = $this->enrolled_with_a_record();
        $absentee = $this->getDataGenerator()->create_user();
        $this->enrol_with_a_record($absentee, $course);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $visitor->id,
            'courseid' => $course->id,
            'timeaccess' => time(),
        ]);

        $rows = completion::collect([(int) $visitor->id, (int) $absentee->id]);
        $statuses = [];
        foreach ($rows as $row) {
            $statuses[$row->userid] = $row->status;
        }

        $this->assertSame('in_progress', $statuses[(int) $visitor->id]);
        $this->assertArrayNotHasKey((int) $absentee->id, $statuses);
    }

    /**
     * A learner enrolled in a course with completion tracking, holding the
     * record the daily task would have made.
     *
     * @param \stdClass|null $user Reuse this learner rather than make one.
     * @return array{0: \stdClass, 1: \stdClass} The learner and the course.
     */
    private function enrolled_with_a_record(?\stdClass $user = null): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $user ?? $this->getDataGenerator()->create_user();
        $this->enrol_with_a_record($user, $course);

        return [$user, $course];
    }

    /**
     * Enrol, then leave exactly the daily task's record and nothing else.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return void
     */
    private function enrol_with_a_record(\stdClass $user, \stdClass $course): void {
        global $DB;

        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $DB->delete_records('course_completions', ['userid' => $user->id, 'course' => $course->id]);
        $DB->delete_records('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => 0,
            'timecompleted' => null,
        ]);
    }

    /**
     * Write one finished attempt on a new quiz in the course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @param bool $preview
     * @return void
     */
    private function attempt(\stdClass $user, \stdClass $course, bool $preview): void {
        global $DB;

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => 5000 + (int) $quiz->id,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => $preview ? 1 : 0,
            'state' => 'finished',
            'timestart' => 1735732200,
            'timefinish' => 1735732800,
            'timemodified' => 1735732800,
            'timemodifiedoffline' => 0,
        ]);
    }

    /**
     * Give the learner a grade row on a new assignment in the course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @param float|null $finalgrade Null leaves the row empty.
     * @return void
     */
    private function grade(\stdClass $user, \stdClass $course, ?float $finalgrade): void {
        global $DB;

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $itemid = $DB->get_field('grade_items', 'id', [
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
        ], MUST_EXIST);

        $DB->insert_record('grade_grades', (object) [
            'itemid' => $itemid,
            'userid' => $user->id,
            'rawgrade' => $finalgrade,
            'finalgrade' => $finalgrade,
            'timemodified' => time(),
        ]);
    }

    /**
     * The row the collector reports for this learner and course, if any.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return \stdClass|null
     */
    private function row(\stdClass $user, \stdClass $course): ?\stdClass {
        foreach (completion::collect([(int) $user->id]) as $row) {
            if ($row->courseid === (int) $course->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The status the collector reports for this learner and course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return string|null Null when the record is left out, as not started.
     */
    private function status(\stdClass $user, \stdClass $course): ?string {
        $row = $this->row($user, $course);

        return $row ? $row->status : null;
    }
}
