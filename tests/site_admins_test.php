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
 * Tests for leaving site administrators out of every figure.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\batch_builder;
use local_reportlms\local\gradable;
use local_reportlms\local\pseudonym;
use local_reportlms\local\site_admins;
use local_reportlms\local\userstate;

/**
 * An administrator enrolled as a student is not a learner.
 *
 * Every fixture enrols with the student role, the one gradable role a fresh
 * site has, so the only thing that differs between a counted person and an
 * uncounted one is the site administrators list.
 *
 * @covers \local_reportlms\local\gradable::pairs
 * @covers \local_reportlms\local\site_admins
 * @covers \local_reportlms\local\batch_builder::build
 */
final class site_admins_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/completionlib.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    public function test_an_administrator_enrolled_as_a_student_is_not_counted(): void {
        [$admin, $course] = $this->student();
        [$student] = $this->student($course);
        $this->make_admins($admin);

        $pairs = gradable::pairs([(int) $admin->id, (int) $student->id]);

        $this->assertArrayNotHasKey((int) $admin->id, $pairs);
        $this->assertTrue($pairs[(int) $student->id][(int) $course->id]);
    }

    public function test_somebody_taken_off_the_list_counts_again(): void {
        [$user, $course] = $this->student();
        $this->make_admins($user);
        $this->assertArrayNotHasKey((int) $user->id, gradable::pairs([(int) $user->id]));

        $this->make_admins();

        $this->assertTrue(gradable::pairs([(int) $user->id])[(int) $user->id][(int) $course->id]);
    }

    /**
     * A batch of nobody but administrators is answered, not refused.
     */
    public function test_a_batch_of_only_administrators_counts_nobody(): void {
        [$admin] = $this->student();
        $this->make_admins($admin);

        $this->assertSame([], gradable::pairs([(int) $admin->id]));
    }

    /**
     * A context tree mid-rebuild holds nobody's push back on an administrator's
     * account: their answer is no whatever the tree says.
     */
    public function test_an_unbuilt_context_does_not_hold_up_an_administrator(): void {
        [$admin, $course] = $this->student();
        $this->make_admins($admin);
        $this->unbuild($course);

        $this->assertSame([], gradable::pairs([(int) $admin->id]));
    }

    /**
     * Everybody else's push is still refused on one, so the guard stands.
     */
    public function test_an_unbuilt_context_still_refuses_everybody_else(): void {
        [$admin, $course] = $this->student();
        [$student] = $this->student($course);
        $this->make_admins($admin);
        $this->unbuild($course);

        $this->expectException(\local_reportlms\local\push_refused_exception::class);

        gradable::pairs([(int) $admin->id, (int) $student->id]);
    }

    /**
     * Their enrolment still goes, flagged, and nothing hanging off it does.
     *
     * The flag is what makes Report LMS stop counting somebody it counted
     * before they became an administrator. Leaving the row out would leave the
     * one it already holds standing, counted.
     */
    public function test_the_push_flags_an_administrators_enrolment_and_drops_their_results(): void {
        [$admin, $course] = $this->student();
        [$student] = $this->student($course);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);

        foreach ([$admin, $student] as $user) {
            $this->complete($user, $course);
            $this->tick($user, $page);
        }

        $this->make_admins($admin);

        $facts = $this->sent_facts([(int) $admin->id, (int) $student->id]);
        $adminpid = pseudonym::learner((int) $admin->id);

        $enrolments = array_column($facts['enrolments'], 'counted', 'learner_pid');
        $this->assertFalse($enrolments[$adminpid]);
        $this->assertTrue($enrolments[pseudonym::learner((int) $student->id)]);

        foreach ($facts as $block => $rows) {
            if ($block === 'enrolments') {
                continue;
            }

            $this->assertNotContains($adminpid, array_column($rows, 'learner_pid'), "{$block} carried an administrator");
        }

        // The student's rows went, so neither block was simply empty.
        $this->assertCount(1, $facts['completion']);
        $this->assertCount(1, $facts['activity_completion']);
    }

    /**
     * The person is named too, so Report LMS also stops counting the
     * enrolments that ended before they became an administrator, which
     * Moodle has nothing left of to flag.
     */
    public function test_the_push_names_an_administrator_as_uncounted(): void {
        [$admin, $course] = $this->student();
        [$student] = $this->student($course);
        $this->make_admins($admin);

        $chunks = batch_builder::build([(int) $admin->id, (int) $student->id], time());

        $this->assertSame([pseudonym::learner((int) $admin->id)], $chunks[0]->payload['uncounted']);
    }

    /**
     * The case `uncounted` exists for: an administrator whose only enrolment
     * ended. Moodle has nothing of it to flag, so naming them is all there is.
     */
    public function test_an_administrator_with_no_enrolment_left_is_still_named(): void {
        $admin = $this->getDataGenerator()->create_user();
        $this->make_admins($admin);

        $chunks = batch_builder::build([(int) $admin->id], time());
        $pid = pseudonym::learner((int) $admin->id);

        $this->assertSame([$pid], $chunks[0]->payload['learners']);
        $this->assertSame([$pid], $chunks[0]->payload['uncounted']);
    }

    /**
     * A site that ran the build before `uncounted` remembered the list
     * already, and would never send its administrators again on its own.
     *
     * @covers ::xmldb_local_reportlms_upgrade
     */
    public function test_the_upgrade_has_every_administrator_sent_again(): void {
        global $CFG;

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reportlms/db/upgrade.php');

        $admin = $this->getDataGenerator()->create_user();
        $this->make_admins($admin);
        site_admins::sync();
        $this->clean($admin);

        set_config('version', 2026102302, 'local_reportlms');
        $this->assertTrue(xmldb_local_reportlms_upgrade(2026102302));
        site_admins::sync();

        $this->assertTrue($this->dirty($admin));
    }

    /**
     * Sent empty rather than left out: an empty list is what tells Report LMS
     * that somebody off the list counts again.
     */
    public function test_the_list_goes_empty_when_nobody_is_an_administrator(): void {
        [$student] = $this->student();

        $chunks = batch_builder::build([(int) $student->id], time());

        $this->assertSame([], $chunks[0]->payload['uncounted']);
    }

    /**
     * The push notices the change itself, and in the same run.
     *
     * Somebody made an administrator a moment ago, on a site where nobody
     * else changed: the run that follows sends them, already flagged.
     */
    public function test_the_next_push_sends_a_new_administrator_flagged(): void {
        [$user, $course] = $this->student();
        $this->connected();
        site_admins::sync();
        $this->clean($user);
        $this->make_admins($user);

        $sent = [];
        $task = new \local_reportlms\task\push_batch();
        $task->set_client($this->recording_client($sent));

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('1 person(s) to send again', $output);
        $this->assertCount(1, $sent);
        $enrolments = array_column($sent[0]['facts']['enrolments'], 'counted', 'learner_pid');
        $this->assertFalse($enrolments[pseudonym::learner((int) $user->id)]);
        $this->assertFalse($this->dirty($user));
    }

    /**
     * Nothing remembered yet, as on the first push after the upgrade: every
     * current administrator is new, which is what takes them back out of
     * figures Report LMS already counted them in.
     */
    public function test_the_first_run_marks_every_administrator(): void {
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->make_admins($one, $two);
        $this->clean($one, $two, $bystander);

        $this->assertSame(2, site_admins::sync());

        $this->assertTrue($this->dirty($one));
        $this->assertTrue($this->dirty($two));
        $this->assertFalse($this->dirty($bystander));
    }

    public function test_the_list_is_remembered_and_a_second_run_marks_nobody(): void {
        $one = $this->getDataGenerator()->create_user();
        $this->make_admins($one);
        site_admins::sync();
        $this->clean($one);

        $this->assertSame(0, site_admins::sync());

        $this->assertFalse($this->dirty($one));
        $this->assertSame('2,' . $one->id, get_config('local_reportlms', site_admins::SEEN));
    }

    public function test_somebody_added_to_the_list_is_marked(): void {
        $was = $this->getDataGenerator()->create_user();
        $joins = $this->getDataGenerator()->create_user();
        $this->make_admins($was);
        site_admins::sync();
        $this->make_admins($was, $joins);
        $this->clean($was, $joins);

        $this->assertSame(1, site_admins::sync());

        $this->assertTrue($this->dirty($joins));
        $this->assertFalse($this->dirty($was));
    }

    /**
     * An id on the list with no account behind it is nobody to send.
     */
    public function test_an_id_nobody_has_is_not_marked(): void {
        global $DB;

        $real = $this->getDataGenerator()->create_user();
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 100;
        set_config('siteadmins', "2,{$real->id},{$missing}");
        $this->clean($real);

        $this->assertSame(1, site_admins::sync());

        $this->assertFalse($DB->record_exists(userstate::TABLE, ['userid' => $missing]));
    }

    public function test_somebody_taken_off_the_list_is_marked(): void {
        $stays = $this->getDataGenerator()->create_user();
        $leaves = $this->getDataGenerator()->create_user();
        $this->make_admins($stays, $leaves);
        site_admins::sync();
        $this->make_admins($stays);
        $this->clean($stays, $leaves);

        $this->assertSame(1, site_admins::sync());

        $this->assertTrue($this->dirty($leaves));
        $this->assertFalse($this->dirty($stays));
    }

    /**
     * A list forced in config.php is the list, and can carry spaces and come
     * in any order.
     */
    public function test_a_list_forced_in_config_php_is_read_as_written(): void {
        global $CFG;

        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $CFG->config_php_settings['siteadmins'] = " {$two->id}, 2 ,{$one->id},";
        $CFG->siteadmins = $CFG->config_php_settings['siteadmins'];

        $this->assertSame([2, (int) $one->id, (int) $two->id], site_admins::ids());
    }

    /**
     * Read where it is kept, not from a $CFG a long cron process loaded at
     * its start and has not looked at since.
     */
    public function test_the_list_is_read_fresh_rather_than_from_an_old_cfg(): void {
        global $CFG, $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('config', 'value', "2,{$user->id}", ['name' => 'siteadmins']);
        $CFG->siteadmins = '2';

        $this->assertSame([2, (int) $user->id], site_admins::ids());
    }

    /**
     * Nobody can place an administrator, and they are in no figure, so they
     * are not reported as somebody waiting to be placed.
     *
     * @covers \local_reportlms\local\mapping::unplaced_count
     */
    public function test_an_administrator_is_not_waiting_to_be_placed(): void {
        $user = $this->getDataGenerator()->create_user();
        $before = \local_reportlms\local\mapping::unplaced_count();

        $this->make_admins($user);

        $this->assertSame($before - 1, \local_reportlms\local\mapping::unplaced_count());
    }

    /**
     * Somebody enrolled as a student, on a new course or the one given.
     *
     * @param \stdClass|null $course
     * @return array{0: \stdClass, 1: \stdClass} The user and the course.
     */
    private function student(?\stdClass $course = null): array {
        $generator = $this->getDataGenerator();
        $course = $course ?? $generator->create_course(['enablecompletion' => 1]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        return [$user, $course];
    }

    /**
     * Make these people, and the primary administrator, the site administrators.
     *
     * @param \stdClass ...$users
     * @return void
     */
    private function make_admins(\stdClass ...$users): void {
        set_config('siteadmins', implode(',', array_merge([2], array_map(static fn($user) => (int) $user->id, $users))));
    }

    /**
     * Record a finished course, the one fact every learner on it can have.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return void
     */
    private function complete(\stdClass $user, \stdClass $course): void {
        global $DB;

        $DB->delete_records('course_completions', ['userid' => $user->id, 'course' => $course->id]);
        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time() - DAYSECS,
            'timestarted' => time() - DAYSECS,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Tick an activity off for somebody.
     *
     * @param \stdClass $user
     * @param \stdClass $module As create_module() returns it.
     * @return void
     */
    private function tick(\stdClass $user, \stdClass $module): void {
        global $DB;

        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $module->cmid,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);
    }

    /**
     * Leave a course's context as it is mid-rebuild: no path.
     *
     * @param \stdClass $course
     * @return void
     */
    private function unbuild(\stdClass $course): void {
        global $DB;

        $DB->set_field('context', 'path', null, ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $course->id]);
    }

    /**
     * Put the site in the state a push runs in.
     *
     * @return void
     */
    private function connected(): void {
        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');
    }

    /**
     * A client that accepts every push and keeps what it was sent.
     *
     * @param array $sent Filled with each decoded payload, by reference.
     * @return api_client
     */
    private function recording_client(array &$sent): api_client {
        return new class (\local_reportlms\local\config::baseurl(), $sent) extends api_client {
            /** @var array Decoded payloads, by reference. */
            private array $sent;

            /**
             * Constructor.
             *
             * @param string $baseurl
             * @param array $sent
             */
            public function __construct(string $baseurl, array &$sent) {
                parent::__construct($baseurl);
                $this->sent = &$sent;
            }

            /**
             * Keep the payload and accept it.
             *
             * @param string $pushpath
             * @param string $rawbody
             * @param string $apikey
             * @param string $signingsecret
             * @param string $idempotencykey
             * @return array
             */
            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                $this->sent[] = json_decode($rawbody, true);

                return ['batch_id' => 'batch-test'];
            }
        };
    }

    /**
     * The fact blocks of the one chunk a push of these people would send.
     *
     * @param array<int, int> $userids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sent_facts(array $userids): array {
        $chunks = batch_builder::build($userids, time());

        $this->assertCount(1, $chunks);

        return $chunks[0]->payload['facts'];
    }

    /**
     * Clear the flags creating and enrolling these people set.
     *
     * @param \stdClass ...$users
     * @return void
     */
    private function clean(\stdClass ...$users): void {
        foreach ($users as $user) {
            userstate::mark_dirty((int) $user->id);
        }

        userstate::clear(array_map(static fn($user) => (int) $user->id, $users));
    }

    /**
     * Whether this person is waiting to be sent.
     *
     * @param \stdClass $user
     * @return bool
     */
    private function dirty(\stdClass $user): bool {
        global $DB;

        return (bool) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]);
    }
}
