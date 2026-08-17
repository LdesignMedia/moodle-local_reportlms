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
 * Tests for the deadline the enrolment carries.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\enrolments;

/**
 * A deadline is the end of an enrolment, and only when every one of them ends.
 *
 * @covers \local_reportlms\local\collector\enrolments
 */
final class enrolment_deadline_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $learner;

    /** @var int */
    protected $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->learner = $this->getDataGenerator()->create_user();
        $this->courseid = (int) $this->getDataGenerator()->create_course()->id;
    }

    /**
     * The one row this learner has on this course.
     *
     * @return \stdClass
     */
    protected function row(): \stdClass {
        $rows = enrolments::collect([(int) $this->learner->id]);

        $this->assertCount(1, $rows, 'methods collapse to one row per course');

        return $rows[0];
    }

    /**
     * Enrol on a second method, because the collapsing rules are the point.
     *
     * @param string $method
     * @param int $timeend
     *
     * @return void
     */
    protected function enrol(string $method, int $timeend): void {
        $this->getDataGenerator()->enrol_user(
            $this->learner->id,
            $this->courseid,
            null,
            $method,
            0,
            $timeend
        );
    }

    public function test_an_unlimited_enrolment_is_no_deadline(): void {
        $this->enrol('manual', 0);

        $this->assertNull($this->row()->due_at, 'unlimited is an absence, not a date');
    }

    public function test_an_enrolment_that_ends_is_the_deadline(): void {
        $end = time() + (86400 * 30);
        $this->enrol('manual', $end);

        $this->assertSame($end, $this->row()->due_at);
    }

    public function test_one_unlimited_method_beats_every_bounded_one(): void {
        /*
         * The case a plain MAX() gets wrong, and the reason for the pair of
         * columns. This learner can still finish through the self enrolment
         * whatever the manual one says, so they are not on a clock.
         */
        $this->enrol('manual', time() + 86400);
        $this->enrol('self', 0);

        $this->assertNull($this->row()->due_at);
    }

    public function test_the_latest_end_wins_when_all_of_them_end(): void {
        $soon = time() + 86400;
        $later = time() + (86400 * 60);

        $this->enrol('manual', $soon);
        $this->enrol('self', $later);

        $this->assertSame($later, $this->row()->due_at, 'the last door to close is the deadline');
    }

    public function test_an_enrolment_already_over_is_still_collected(): void {
        /*
         * The whole feature rests on this. If a lapsed enrolment fell out of
         * the collector, the people who are most overdue would be the people
         * the report could not see.
         */
        $past = time() - 86400;
        $this->enrol('manual', $past);

        $row = $this->row();

        $this->assertSame($past, $row->due_at);
        $this->assertSame(1, (int) $row->active, 'lapsed is not the same as suspended');
    }
}
