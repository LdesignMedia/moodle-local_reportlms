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
 * Tests for turning a coded selection back into actual people.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\org;
use local_reportlms\local\filter;
use local_reportlms\local\label_map;
use local_reportlms\local\people;
use local_reportlms\local\pseudonym;

/**
 * @covers \local_reportlms\local\people
 */
final class people_test extends \advanced_testcase {
    /** @var \stdClass */
    private $sectorfield;

    /** @var \stdClass */
    private $departmentfield;

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        label_map::purge();

        $map = org::fieldmap();
        $this->sectorfield = $this->make_field($map['sector']);
        $this->departmentfield = $this->make_field($map['department']);
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
    }

    /**
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => 'Report LMS test']);

        if (!$categoryid) {
            $categoryid = $DB->insert_record('user_info_category', (object) [
                'name' => 'Report LMS test',
                'sortorder' => 1,
            ]);
        }

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
            'defaultdata' => '',
            'param1' => 30,
            'param2' => 2048,
        ]);

        return $DB->get_record('user_info_field', ['id' => $id]);
    }

    /**
     * A learner in a department, enrolled, optionally finished.
     *
     * @param string $sector
     * @param string $department
     * @param bool $completed
     *
     * @return \stdClass
     */
    private function learner(string $sector, string $department, bool $completed = false): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        foreach ([$this->sectorfield->id => $sector, $this->departmentfield->id => $department] as $fieldid => $value) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => 0,
            ]);
        }

        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);

        if ($completed) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $user->id,
                'course' => $this->course->id,
                'timeenrolled' => time() - 100,
                'timestarted' => time() - 90,
                'timecompleted' => time() - 10,
                'reaggregate' => 0,
            ]);
        }

        return $user;
    }

    public function test_only_the_unfinished_are_listed(): void {
        $unfinished = $this->learner('Operations', 'Rotterdam');
        $this->learner('Operations', 'Rotterdam', true);

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/Rotterdam'), '', ''
        ));

        $this->assertCount(1, $rows);
        $this->assertSame((int) $unfinished->id, $rows[0]->userid);
    }

    /**
     * A department filter constrains its sector too, so a same-named
     * department elsewhere is not swept in.
     */
    public function test_a_department_filter_does_not_reach_another_sector(): void {
        $ours = $this->learner('Operations', 'HR');
        $this->learner('Finance', 'HR');

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/HR'), '', ''
        ));

        $this->assertCount(1, $rows);
        $this->assertSame((int) $ours->id, $rows[0]->userid);
    }

    /**
     * Two profile conditions must narrow, not multiply.
     *
     * The profile values live in a row-per-field table, so the obvious join
     * returns one row per matching field and a learner appears twice for a
     * filter that named two of them.
     */
    public function test_filtering_on_two_fields_does_not_duplicate_a_learner(): void {
        $this->learner('Operations', 'Rotterdam');

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/Rotterdam'), '', ''
        ));

        $this->assertCount(1, $rows);
    }

    /**
     * One row per enrolment, because a person can be behind on two courses.
     */
    public function test_a_learner_behind_on_two_courses_appears_once_per_course(): void {
        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->learner('Operations', 'Rotterdam');
        $this->getDataGenerator()->enrol_user($user->id, $second->id);

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/Rotterdam'), '', ''
        ));

        $this->assertCount(2, $rows);

        // Sorted before comparing: the query orders by course name for the
        // reader's benefit, so the ids come back in whatever order the names
        // happen to fall in.
        $courseids = array_map(fn ($r) => $r->courseid, $rows);
        sort($courseids);

        $expected = [(int) $this->course->id, (int) $second->id];
        sort($expected);

        $this->assertSame($expected, $courseids);
    }

    public function test_a_course_filter_narrows_to_that_course(): void {
        $second = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->learner('Operations', 'Rotterdam');
        $this->getDataGenerator()->enrol_user($user->id, $second->id);

        $rows = people::not_completed(filter::from_codes(
            '', pseudonym::course((int) $second->id), ''
        ));

        $this->assertCount(1, $rows);
        $this->assertSame((int) $second->id, $rows[0]->courseid);
    }

    /**
     * A deleted or suspended account is not somebody to chase.
     */
    public function test_deleted_and_suspended_accounts_are_left_out(): void {
        global $DB;

        $deleted = $this->learner('Operations', 'Rotterdam');
        $suspended = $this->learner('Operations', 'Rotterdam');
        $active = $this->learner('Operations', 'Rotterdam');

        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/Rotterdam'), '', ''
        ));

        $this->assertCount(1, $rows);
        $this->assertSame((int) $active->id, $rows[0]->userid);
    }

    /**
     * The rows carry the names, which is the whole point of asking here.
     *
     * Report LMS holds counts against coded identifiers and has never seen a
     * name, so this is the only place the question can be answered at all.
     */
    public function test_the_rows_carry_the_names_report_lms_cannot_hold(): void {
        $user = $this->learner('Operations', 'Rotterdam');

        $rows = people::not_completed(filter::from_codes(
            pseudonym::node('department', 'Operations/Rotterdam'), '', ''
        ));

        $this->assertNotEmpty($rows[0]->fullname);
        $this->assertSame($user->email, $rows[0]->email);
        $this->assertContains($rows[0]->status, ['notstarted', 'inprogress']);
    }
}
