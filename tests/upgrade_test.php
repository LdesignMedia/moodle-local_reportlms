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
 * Tests for the upgrade step that re-sends learners with counted attempts.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\userstate;

/**
 * The 2026102200 step: who it marks dirty so last_attempt_at reaches Report LMS.
 *
 * Facts only travel for dirty learners and an upgrade marks nobody, so this
 * step is the only way the new date reaches attempt rows Report LMS already
 * holds. It must reach exactly the learners the attempts collector would send
 * a row for, and only on a site that is connected.
 *
 * @covers ::xmldb_local_reportlms_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * @var int The version just before the step. Later steps run too; 2026102300
     *          marks only learners with an unfinished completion record, and no
     *          fixture here has one.
     */
    private const BEFORE = 2026102100;

    /** @var int Next value for quiz_attempts.uniqueid, which has to be unique. */
    private $uniqueid = 1000;

    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        require_once($CFG->dirroot . '/local/reportlms/db/upgrade.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    /**
     * Somebody with a counted attempt is marked, whether or not they had a row.
     */
    public function test_a_learner_with_a_counted_attempt_is_marked_dirty(): void {
        global $DB;

        $this->connect();

        [$tracked, $quiz] = $this->learner_on_a_main_quiz();
        $this->attempt($quiz, $tracked, 1, 'finished', 1735732800);

        [$untracked, $otherquiz] = $this->learner_on_a_main_quiz();
        $this->attempt($otherquiz, $untracked, 1, 'finished', 1735732800);

        $this->everybody_clean();
        $DB->delete_records(userstate::TABLE, ['userid' => $untracked->id]);

        $this->upgrade();

        $this->assertTrue($this->is_dirty($tracked), 'A tracked learner with an attempt is marked.');
        $this->assertTrue($this->is_dirty($untracked), 'A learner with an attempt and no row gets one, dirty.');
    }

    /**
     * Attempts the collector does not count mark nobody.
     *
     * A preview, an attempt still in progress, and a finished attempt on a quiz
     * that does not gate its course: none of them puts a row in the attempts
     * block, so none of these learners' slices changed.
     */
    public function test_attempts_the_collector_does_not_count_mark_nobody(): void {
        global $DB;

        $this->connect();

        [$previewer, $quiz] = $this->learner_on_a_main_quiz();
        $this->attempt($quiz, $previewer, 1, 'finished', 1735732800, true);
        $this->attempt($quiz, $previewer, 2, 'inprogress', 0);

        // Same set-up, but without the completion criterion that makes the
        // quiz the course's main test.
        [$sideline, $sidequiz, $sidecm] = $this->learner_on_a_main_quiz();
        $DB->delete_records('course_completion_criteria', ['moduleinstance' => $sidecm->id]);
        $this->attempt($sidequiz, $sideline, 1, 'finished', 1735732800);

        $bystander = $this->getDataGenerator()->create_user();

        $this->everybody_clean();

        $this->upgrade();

        $this->assertFalse($this->is_dirty($previewer));
        $this->assertFalse($this->is_dirty($sideline));
        $this->assertFalse($this->is_dirty($bystander));
    }

    /**
     * An unconnected site is left alone: connecting marks everybody anyway.
     */
    public function test_an_unconnected_site_marks_nobody(): void {
        [$user, $quiz] = $this->learner_on_a_main_quiz();
        $this->attempt($quiz, $user, 1, 'finished', 1735732800);

        $this->everybody_clean();

        $this->upgrade();

        $this->assertFalse($this->is_dirty($user));
        $this->assertSame(2026102303, (int) get_config('local_reportlms', 'version'), 'The last savepoint is still reached.');
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
     * Mark every tracked learner as delivered.
     *
     * Creating users and enrolling them raises events the plugin observes, so
     * every fixture starts dirty. The step is only visible against a registry
     * that had nothing waiting.
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
     *
     * @return bool
     */
    private function is_dirty(\stdClass $user): bool {
        global $DB;

        return (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]) === 1;
    }

    /**
     * A learner enrolled as a student on a course whose completion a quiz gates.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} User, quiz, course module.
     */
    private function learner_on_a_main_quiz(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $quiz = $generator->create_module('quiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'quiz',
            'moduleinstance' => $cm->id,
        ]);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        return [$user, $quiz, $cm];
    }

    /**
     * Write one attempt row, the way the quiz module leaves it.
     *
     * @param \stdClass $quiz
     * @param \stdClass $user
     * @param int $number
     * @param string $state
     * @param int $timefinish
     * @param bool $preview
     *
     * @return void
     */
    private function attempt(
        \stdClass $quiz,
        \stdClass $user,
        int $number,
        string $state,
        int $timefinish,
        bool $preview = false
    ): void {
        global $DB;

        $start = $timefinish > 0 ? $timefinish - 600 : 1735732800;

        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => $number,
            'uniqueid' => $this->uniqueid++,
            'layout' => '1,0',
            'currentpage' => 0,
            'preview' => $preview ? 1 : 0,
            'state' => $state,
            'timestart' => $start,
            'timefinish' => $timefinish,
            'timemodified' => max($start, $timefinish),
            'timemodifiedoffline' => 0,
        ]);
    }
}
