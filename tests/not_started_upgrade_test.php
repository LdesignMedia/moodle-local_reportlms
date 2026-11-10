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
 * Tests for the upgrade step and the sweep that carry "not started" across.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\userstate;
use local_reportlms\task\reconcile_userstate;

/**
 * The 2026102300 step, and the courseaccess source of the nightly sweep.
 *
 * The collector reads not started correctly on its own, but only for whoever
 * gets sent. The step re-sends the learners whose status it now reads
 * differently, and the sweep sends a learner the night after their first visit,
 * which raises no event the plugin observes.
 *
 * @covers ::xmldb_local_reportlms_upgrade
 * @covers \local_reportlms\task\reconcile_userstate
 */
final class not_started_upgrade_test extends \advanced_testcase {
    /** @var int The version just before the step, so only that step runs. */
    private const BEFORE = 2026102200;

    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reportlms/db/upgrade.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    /**
     * Who never opened the course is marked; who did, or finished, is not.
     */
    public function test_a_learner_who_never_opened_the_course_is_marked(): void {
        global $DB;

        $this->connect();

        $absentee = $this->learner_with_a_record();
        [$visitor, $visited] = $this->learner_with_a_record_and_course();
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $visitor->id,
            'courseid' => $visited->id,
            'timeaccess' => time(),
        ]);
        [$finisher, $finished] = $this->learner_with_a_record_and_course();
        $DB->set_field('course_completions', 'timecompleted', time(), ['userid' => $finisher->id, 'course' => $finished->id]);
        $untracked = $this->learner_with_a_record();
        // A criterion a task met set timestarted, with nobody present.
        [$neverthere, $taskmet] = $this->learner_with_a_record_and_course();
        $DB->set_field('course_completions', 'timestarted', time(), ['userid' => $neverthere->id, 'course' => $taskmet->id]);
        $bystander = $this->getDataGenerator()->create_user();

        $this->everybody_clean();
        $DB->delete_records(userstate::TABLE, ['userid' => $untracked->id]);

        $this->upgrade();

        $this->assertTrue($this->is_dirty($absentee));
        $this->assertTrue($this->is_dirty($untracked), 'A learner with no row gets one, dirty.');
        $this->assertTrue($this->is_dirty($neverthere), 'timestarted alone is no start.');
        $this->assertFalse($this->is_dirty($visitor));
        $this->assertFalse($this->is_dirty($finisher));
        $this->assertFalse($this->is_dirty($bystander));
    }

    /**
     * Unconnected, nobody is marked: connecting marks everybody anyway. The
     * sweep's watermark still starts now.
     */
    public function test_an_unconnected_site_marks_nobody_but_starts_the_sweep_now(): void {
        $absentee = $this->learner_with_a_record();
        $this->everybody_clean();

        $before = time();
        $this->upgrade();

        $this->assertFalse($this->is_dirty($absentee));
        $this->assertGreaterThanOrEqual($before, (int) get_config('local_reportlms', 'watermark_courseaccess'));
        $this->assertSame(2026111004, (int) get_config('local_reportlms', 'version'), 'The last savepoint is still reached.');
    }

    /**
     * A visit since the last sweep marks the visitor, and so does one from the
     * day before, made before Moodle had written their record. An older visit
     * does not, and neither does a visit that cannot change their status: to a
     * finished course, or to one where they have already done an activity.
     */
    public function test_the_sweep_sends_whoever_opened_a_course_since_it_last_ran(): void {
        global $DB;

        $this->connect();

        $now = time();
        [$recent, $course] = $this->learner_with_a_record_and_course();
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $recent->id,
            'courseid' => $course->id,
            'timeaccess' => $now,
        ]);
        // Visited two hours ago, an hour before the last sweep, when they had
        // no record yet: the daily completion task made it afterwards.
        [$early, $earlycourse] = $this->learner_with_a_record_and_course();
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $early->id,
            'courseid' => $earlycourse->id,
            'timeaccess' => $now - 7200,
        ]);
        [$earlier, $other] = $this->learner_with_a_record_and_course();
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $earlier->id,
            'courseid' => $other->id,
            'timeaccess' => $now - 3 * DAYSECS,
        ]);
        [$finisher, $finished] = $this->learner_with_a_record_and_course();
        $DB->set_field('course_completions', 'timecompleted', $now, ['userid' => $finisher->id, 'course' => $finished->id]);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $finisher->id,
            'courseid' => $finished->id,
            'timeaccess' => $now,
        ]);
        [$busy, $busycourse] = $this->learner_with_a_record_and_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $busycourse->id, 'completion' => 1]);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid,
            'userid' => $busy->id,
            'completionstate' => 1,
            'timemodified' => $now - 86400,
        ]);
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $busy->id,
            'courseid' => $busycourse->id,
            'timeaccess' => $now,
        ]);

        // Every other source past everything this test wrote, so only the
        // courseaccess source can mark anybody.
        $others = ['user', 'enrolments', 'completion', 'cmcompletion', 'grades', 'quizattempts', 'roles',
            'customcertissues', 'toolcertissues'];
        foreach ($others as $source) {
            set_config('watermark_' . $source, $now + 3600, 'local_reportlms');
        }
        set_config('watermark_courseaccess', $now - 3600, 'local_reportlms');

        $this->everybody_clean();

        $this->expectOutputRegex('/reconcile marked/');
        (new reconcile_userstate())->execute();

        $this->assertTrue($this->is_dirty($recent));
        $this->assertTrue($this->is_dirty($early), 'A visit made before the record existed is caught the next night.');
        $this->assertFalse($this->is_dirty($earlier));
        $this->assertFalse($this->is_dirty($finisher));
        $this->assertFalse($this->is_dirty($busy));
        $this->assertSame($now, (int) get_config('local_reportlms', 'watermark_courseaccess'));
    }

    /**
     * Run the upgrade from just before the step, so the step is all that runs.
     *
     * @return void
     */
    private function upgrade(): void {
        set_config('version', self::BEFORE, 'local_reportlms');

        $this->assertTrue(xmldb_local_reportlms_upgrade(self::BEFORE));
    }

    /**
     * Hold the keys a completed connect leaves behind.
     *
     * @return void
     */
    private function connect(): void {
        set_config('apikey', 'key-for-tests', 'local_reportlms');
        set_config('signingsecret', 'secret-for-tests', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
    }

    /**
     * Mark every tracked learner as delivered, so only the step can mark.
     *
     * @return void
     */
    private function everybody_clean(): void {
        global $DB;

        $DB->set_field(userstate::TABLE, 'dirty', 0, []);
    }

    /**
     * Whether the registry has this learner waiting to be sent.
     *
     * @param \stdClass $user
     * @return bool
     */
    private function is_dirty(\stdClass $user): bool {
        global $DB;

        return (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]) === 1;
    }

    /**
     * A learner holding only the daily task's record.
     *
     * @return \stdClass
     */
    private function learner_with_a_record(): \stdClass {
        return $this->learner_with_a_record_and_course()[0];
    }

    /**
     * A student on a course with completion tracking, holding exactly the
     * record Moodle's daily completion task leaves: not started, not finished.
     *
     * @return array{0: \stdClass, 1: \stdClass} The learner and the course.
     */
    private function learner_with_a_record_and_course(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $DB->delete_records('course_completions', ['userid' => $user->id, 'course' => $course->id]);
        $DB->delete_records('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id]);
        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => 0,
            'timecompleted' => null,
        ]);

        return [$user, $course];
    }
}
