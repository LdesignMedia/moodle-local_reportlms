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
 * Tests for the dimensions catalogue.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\batch_builder;
use local_reportlms\local\collector\completion;
use local_reportlms\local\collector\dimensions;
use local_reportlms\local\pseudonym;

/**
 * The catalogue has to name every course a fact can point at.
 *
 * @covers \local_reportlms\local\collector\dimensions
 * @covers \local_reportlms\local\batch_builder::dimensions_section_if_changed
 */
final class dimensions_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The course ids the catalogue currently names.
     *
     * @return array<int, int>
     */
    private function catalogued(): array {
        return array_map(
            static fn($course) => $course->courseid,
            dimensions::collect()->courses
        );
    }

    public function test_a_course_with_an_enrolment_is_catalogued(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $this->assertContains((int) $course->id, $this->catalogued());
    }

    public function test_a_course_nothing_touches_is_not_catalogued(): void {
        // The catalogue is not the course list. A site with 800 empty courses
        // should not send 800 rows nobody can ask a question about.
        $course = $this->getDataGenerator()->create_course();

        $this->assertNotContains((int) $course->id, $this->catalogued());
    }

    /**
     * The one that was live in a customer dashboard.
     *
     * A learner unenrolled after finishing keeps their course_completions row,
     * so the completion collector still reports that course. If the catalogue
     * only knew about courses with a live enrolment, Report LMS minted a
     * dimension row from the fact, never received a title for it, and showed
     * the raw coded id in the training picker. Nothing revisited it either,
     * because the catalogue hash had no reason to move.
     */
    public function test_a_completed_course_is_catalogued_after_the_learner_is_unenrolled(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => time(),
            'timecompleted' => time(),
        ]);

        // Gone from the course, still finished it.
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        enrol_get_plugin('manual')->unenrol_user($instance, $user->id);

        $this->assertSame(
            [],
            $DB->get_records_sql(
                "SELECT ue.id
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = ?",
                [$course->id]
            )
        );

        // The fact still ships, which is correct: "who completed this" is the
        // question the product exists to answer.
        $reported = array_map(
            static fn($row) => $row->courseid,
            completion::collect([(int) $user->id])
        );
        $this->assertContains((int) $course->id, $reported);

        // So the catalogue has to be able to name it.
        $this->assertContains((int) $course->id, $this->catalogued());
    }

    public function test_every_course_the_completion_collector_reports_can_be_named(): void {
        global $DB;

        // The general form of the rule, rather than the one case that broke:
        // whatever the completion collector says, the catalogue covers.
        $enrolled = $this->getDataGenerator()->create_course();
        $abandoned = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($user->id, $enrolled->id);

        foreach ([$enrolled->id, $abandoned->id] as $courseid) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $user->id,
                'course' => $courseid,
                'timeenrolled' => time(),
                'timestarted' => time(),
                'timecompleted' => time(),
            ]);
        }

        $reported = array_unique(array_map(
            static fn($row) => $row->courseid,
            completion::collect([(int) $user->id])
        ));

        $this->assertSame([], array_diff($reported, $this->catalogued()));
    }

    /**
     * The other half of the same rule, from the other end.
     *
     * Deleting a course does not always take its course_completions rows with
     * it. Three such orphans were sitting on one customer site, each one a
     * dimension row Report LMS could never name because the course was gone.
     * Nothing can fix that after the fact, so it must not be sent.
     */
    public function test_a_completion_for_a_deleted_course_is_not_reported(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => time(),
            'timecompleted' => time(),
        ]);

        // The orphan: the course row goes, the completion row stays.
        $DB->delete_records('course', ['id' => $course->id]);
        $this->assertTrue($DB->record_exists('course_completions', ['course' => $course->id]));

        $reported = array_map(
            static fn($row) => $row->courseid,
            completion::collect([(int) $user->id])
        );

        $this->assertNotContains((int) $course->id, $reported);
    }

    /**
     * The course's own created date travels, in the contract's date form.
     *
     * The training modal starts its timeline here. Without it the report falls
     * back to the first enrolment, which on an old course imported in one go
     * is the import, not the training.
     */
    public function test_a_course_carries_its_created_date(): void {
        global $DB;

        $course = $this->catalogued_course();
        $created = 1735732800; // 1 January 2025, 12:00 UTC.
        $DB->set_field('course', 'timecreated', $created, ['id' => $course->id]);

        $this->assertSame($created, $this->collected($course)->timecreated);
        $this->assertSame('2025-01-01T12:00:00Z', $this->sent($course)['created_at']);
    }

    /**
     * A course Moodle holds no created date for says so, rather than 1970.
     *
     * A restore or a creation script can leave 0 behind. Sent as a date, that
     * is 1 January 1970, and a timeline drawn from it is fifty years of empty
     * bars.
     */
    public function test_a_course_without_a_created_date_sends_null(): void {
        global $DB;

        $course = $this->catalogued_course();
        $DB->set_field('course', 'timecreated', 0, ['id' => $course->id]);

        $this->assertNull($this->collected($course)->timecreated);

        $sent = $this->sent($course);
        $this->assertArrayHasKey('created_at', $sent, 'Null is sent, not left out.');
        $this->assertNull($sent['created_at']);
    }

    /**
     * Upgrading moves the catalogue hash, which is what re-sends every course.
     *
     * The date is new on courses nothing else has changed about, so without
     * the hash moving they would never carry it.
     */
    public function test_the_created_date_is_part_of_the_catalogue_hash(): void {
        global $DB;

        $course = $this->catalogued_course();
        $before = dimensions::state_hash(dimensions::collect());

        $DB->set_field('course', 'timecreated', 1735732800, ['id' => $course->id]);

        $this->assertNotSame($before, dimensions::state_hash(dimensions::collect()));
    }

    /**
     * The course's category travels as a code, never by name.
     *
     * It is what tells apart trainings that share a title, and a category can
     * be named after a person, so the dashboard asks this site for the name.
     */
    public function test_a_course_carries_its_category_as_a_code(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category(['name' => 'TM - Jane Doe']);
        $course = $this->catalogued_course();
        $DB->set_field('course', 'category', $category->id, ['id' => $course->id]);

        $row = $this->sent($course);

        $this->assertSame(pseudonym::category((int) $category->id), $row['category_ref']);
        $this->assertStringNotContainsString('Jane', json_encode($row));
    }

    public function test_the_catalogue_hash_moves_when_a_course_changes_category(): void {
        global $DB;

        $course = $this->catalogued_course();
        $before = dimensions::state_hash(dimensions::collect());

        $category = $this->getDataGenerator()->create_category();
        $DB->set_field('course', 'category', $category->id, ['id' => $course->id]);

        $this->assertNotSame($before, dimensions::state_hash(dimensions::collect()));
    }

    /**
     * A course with somebody enrolled, so the catalogue names it.
     *
     * @return \stdClass
     */
    private function catalogued_course(): \stdClass {
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        return $course;
    }

    /**
     * The collector's row for one course.
     *
     * @param \stdClass $course
     *
     * @return \stdClass
     */
    private function collected(\stdClass $course): \stdClass {
        foreach (dimensions::collect()->courses as $row) {
            if ($row->courseid === (int) $course->id) {
                return $row;
            }
        }

        $this->fail('The course is not in the catalogue.');
    }

    /**
     * The row for one course as it goes on the wire.
     *
     * @param \stdClass $course
     *
     * @return array<string, mixed>
     */
    private function sent(\stdClass $course): array {
        // Never delivered, so the section is always built.
        unset_config('lastdimhash', 'local_reportlms');

        $section = batch_builder::dimensions_section_if_changed();
        $this->assertNotNull($section);

        $ref = pseudonym::course((int) $course->id);

        foreach ($section['section']['courses'] as $row) {
            if ($row['course_ref'] === $ref) {
                return $row;
            }
        }

        $this->fail('The course is not in the dimensions section.');
    }

    public function test_the_catalogue_hash_moves_when_a_course_joins_it(): void {
        // The hash is what stops the block being resent every push, so a course
        // arriving has to move it or the new title never travels.
        $before = dimensions::state_hash(dimensions::collect());

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $this->assertNotSame($before, dimensions::state_hash(dimensions::collect()));
    }
}
