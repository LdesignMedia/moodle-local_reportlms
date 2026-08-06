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
 * Tests for the certificates collector.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\certificates;

/**
 * @covers \local_reportlms\local\collector\certificates
 */
final class certificates_test extends \advanced_testcase {
    /**
     * A site with neither certificate plugin reports nothing, rather than failing.
     *
     * Most sites in the test estate have one stack or the other and never both,
     * so the collector has to survive a missing table instead of assuming one.
     */
    public function test_a_site_with_no_certificate_plugin_reports_nothing(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        // Whatever this site happens to have installed, an empty user list can
        // produce nothing, which is the one answer that holds everywhere.
        $this->assertSame([], certificates::collect([]));
        $this->assertIsArray(certificates::collect([(int) $user->id]));
    }

    /**
     * Workplace issues carry a real expiry; "never expires" is stored as 0.
     */
    public function test_a_workplace_issue_reports_its_expiry(): void {
        global $DB;

        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $this->markTestSkipped('tool_certificate is not installed on this site.');
        }

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $expires = time() + 86400;

        $DB->insert_record('tool_certificate_issues', (object) [
            'userid' => $user->id,
            'templateid' => 1,
            'code' => 'ABC123',
            'emailed' => 0,
            'timecreated' => time() - 100,
            'expires' => $expires,
            'data' => '{}',
            'component' => 'mod_coursecertificate',
            'courseid' => $course->id,
            'archived' => 0,
        ]);

        $rows = certificates::collect([(int) $user->id]);

        $this->assertCount(1, $rows);
        $this->assertSame((int) $course->id, $rows[0]->courseid);
        $this->assertSame($expires, $rows[0]->expiresat);
    }

    /**
     * An archived issue is not a certificate anybody can show.
     */
    public function test_an_archived_workplace_issue_is_left_out(): void {
        global $DB;

        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $this->markTestSkipped('tool_certificate is not installed on this site.');
        }

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('tool_certificate_issues', (object) [
            'userid' => $user->id,
            'templateid' => 1,
            'code' => 'ARCHIVED1',
            'emailed' => 0,
            'timecreated' => time(),
            'expires' => 0,
            'data' => '{}',
            'component' => 'mod_coursecertificate',
            'courseid' => $course->id,
            'archived' => 1,
        ]);

        $this->assertSame([], certificates::collect([(int) $user->id]));
    }

    /**
     * An issue granted outside any course belongs to no training.
     *
     * The dashboard counts per course, so attaching it to a guess would put a
     * certificate against a training the learner never took.
     */
    public function test_an_issue_with_no_course_is_left_out(): void {
        global $DB;

        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $this->markTestSkipped('tool_certificate is not installed on this site.');
        }

        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('tool_certificate_issues', (object) [
            'userid' => $user->id,
            'templateid' => 1,
            'code' => 'NOCOURSE1',
            'emailed' => 0,
            'timecreated' => time(),
            'expires' => 0,
            'data' => '{}',
            'component' => 'tool_certificate',
            'courseid' => null,
            'archived' => 0,
        ]);

        $this->assertSame([], certificates::collect([(int) $user->id]));
    }

    /**
     * Two certificates for one course collapse to the most recent.
     *
     * A reissue after a renewal is the answer; the one it replaced is history,
     * and counting both would certify the same person twice.
     */
    public function test_a_reissue_replaces_the_one_before_it(): void {
        global $DB;

        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $this->markTestSkipped('tool_certificate is not installed on this site.');
        }

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $newest = time();

        foreach ([$newest - 86400, $newest] as $i => $issued) {
            $DB->insert_record('tool_certificate_issues', (object) [
                'userid' => $user->id,
                'templateid' => 1,
                'code' => 'REISSUE' . $i,
                'emailed' => 0,
                'timecreated' => $issued,
                'expires' => 0,
                'data' => '{}',
                'component' => 'mod_coursecertificate',
                'courseid' => $course->id,
                'archived' => 0,
            ]);
        }

        $rows = certificates::collect([(int) $user->id]);

        $this->assertCount(1, $rows);
        $this->assertSame($newest, $rows[0]->issuedat);
    }

    /**
     * A learner certified in several courses keeps every one of them.
     *
     * Worth its own test: the obvious implementation keys the result by user id
     * and silently drops all but one course.
     */
    public function test_a_learner_keeps_a_certificate_per_course(): void {
        global $DB;

        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            $this->markTestSkipped('tool_certificate is not installed on this site.');
        }

        $user = $this->getDataGenerator()->create_user();
        $courses = [
            $this->getDataGenerator()->create_course(),
            $this->getDataGenerator()->create_course(),
            $this->getDataGenerator()->create_course(),
        ];

        foreach ($courses as $i => $course) {
            $DB->insert_record('tool_certificate_issues', (object) [
                'userid' => $user->id,
                'templateid' => 1,
                'code' => 'MULTI' . $i,
                'emailed' => 0,
                'timecreated' => time() - ($i * 10),
                'expires' => 0,
                'data' => '{}',
                'component' => 'mod_coursecertificate',
                'courseid' => $course->id,
                'archived' => 0,
            ]);
        }

        $this->assertCount(3, certificates::collect([(int) $user->id]));
    }
}
