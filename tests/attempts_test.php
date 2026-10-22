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
 * Tests for the attempts collector's count and last attempt date.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\batch_builder;
use local_reportlms\local\collector\attempts;
use local_reportlms\local\pseudonym;

/**
 * The last attempt date has to describe the same attempts the count does.
 *
 * Both come out of one GROUP BY, so an attempt the count leaves out (a preview,
 * one still in progress, one abandoned) must not be the one the date is read
 * from either. A date belonging to an attempt nobody counted would put "last
 * tried yesterday" next to somebody whose last real try was a month ago.
 *
 * @covers \local_reportlms\local\collector\attempts
 * @covers \local_reportlms\local\batch_builder::build
 */
final class attempts_test extends \advanced_testcase {
    /** @var int Next value for quiz_attempts.uniqueid, which has to be unique. */
    private $uniqueid = 1000;

    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    /**
     * The latest finished attempt is the date, whatever order they were made in.
     */
    public function test_the_last_attempt_is_the_latest_finished_one(): void {
        [$user, $quiz] = $this->learner_on_a_main_quiz();

        $this->attempt($quiz, $user, 1, 'finished', 1735732800);
        $this->attempt($quiz, $user, 2, 'finished', 1738411200);
        $this->attempt($quiz, $user, 3, 'finished', 1736942400);

        $row = $this->only_row($user);

        $this->assertSame(3, $row->attempts);
        $this->assertSame(1738411200, $row->lastattemptat);
    }

    /**
     * Unfinished and preview attempts move neither the count nor the date.
     *
     * Each one here is later than the real attempt, so a date read from any of
     * them would show.
     */
    public function test_unfinished_and_preview_attempts_are_left_out_of_the_date(): void {
        [$user, $quiz] = $this->learner_on_a_main_quiz();

        $this->attempt($quiz, $user, 1, 'finished', 1735732800);
        // Started later and never submitted: Moodle stores timefinish as 0.
        $this->attempt($quiz, $user, 2, 'inprogress', 0);
        // Ran out of time and was never submitted.
        $this->attempt($quiz, $user, 3, 'abandoned', 1738411200);
        // A teacher trying the quiz out.
        $this->attempt($quiz, $user, 4, 'finished', 1740000000, true);

        $row = $this->only_row($user);

        $this->assertSame(1, $row->attempts);
        $this->assertSame(1735732800, $row->lastattemptat);
    }

    /**
     * A finished attempt with no finish time stored is "not known", not 1970.
     */
    public function test_a_finished_attempt_without_a_time_gives_no_date(): void {
        [$user, $quiz] = $this->learner_on_a_main_quiz();

        $this->attempt($quiz, $user, 1, 'finished', 0);

        $row = $this->only_row($user);

        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->lastattemptat);
    }

    /**
     * On the wire: the contract's date form, and null sent rather than left out.
     */
    public function test_the_push_carries_last_attempt_at(): void {
        [$user, $quiz, $cm] = $this->learner_on_a_main_quiz();
        [$other, $otherquiz] = $this->learner_on_a_main_quiz();

        $this->attempt($quiz, $user, 1, 'finished', 1735732800);
        $this->attempt($quiz, $user, 2, 'finished', 1738411200);

        $sent = $this->sent_attempts([(int) $user->id]);

        $this->assertCount(1, $sent);
        $this->assertSame(pseudonym::activity((int) $cm->id), $sent[0]['activity_ref']);
        $this->assertSame(2, $sent[0]['attempts']);
        $this->assertSame('2025-02-01T12:00:00Z', $sent[0]['last_attempt_at']);

        // A finished attempt with no time behind it.
        $this->attempt($otherquiz, $other, 1, 'finished', 0);

        $sent = $this->sent_attempts([(int) $other->id]);

        $this->assertCount(1, $sent);
        $this->assertArrayHasKey('last_attempt_at', $sent[0]);
        $this->assertNull($sent[0]['last_attempt_at']);
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

        // The criterion is what makes this the course's main test.
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

    /**
     * The collector's one row for this learner.
     *
     * @param \stdClass $user
     *
     * @return \stdClass
     */
    private function only_row(\stdClass $user): \stdClass {
        $rows = attempts::collect([(int) $user->id]);

        $this->assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * The attempts block of the first chunk a push would send.
     *
     * @param array<int, int> $userids
     *
     * @return array<int, array<string, mixed>>
     */
    private function sent_attempts(array $userids): array {
        $chunks = batch_builder::build($userids, time());

        $this->assertNotEmpty($chunks);

        return $chunks[0]->payload['facts']['attempts'] ?? [];
    }
}
