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
 * Tests for the push checking the certificate tables itself.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\pseudonym;
use local_reportlms\local\source_sweep;
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;
use local_reportlms\task\reconcile_userstate;

/**
 * A certificate issued after the push that sent its completion goes out with the next push.
 *
 * mod_customcert is rarely installed where these tests run, so when it is
 * missing the test builds the two tables the plugin reads, with the columns it
 * reads, and drops them again afterwards.
 *
 * @covers \local_reportlms\local\source_sweep
 * @covers \local_reportlms\task\push_batch
 * @covers \local_reportlms\task\reconcile_userstate
 */
final class certificate_push_test extends \advanced_testcase {
    /** @var bool Whether this test built stand-ins for customcert's tables. */
    private $standins = false;

    /** @var array<int, array> The payloads the push sent. */
    private $sent = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');

        $this->ensure_customcert_tables();
    }

    protected function tearDown(): void {
        global $DB;

        if ($this->standins) {
            $manager = $DB->get_manager();

            foreach (['customcert_issues', 'customcert'] as $name) {
                $table = new \xmldb_table($name);

                if ($manager->table_exists($table)) {
                    $manager->drop_table($table);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * The completion went out a moment before the certificate was issued.
     *
     * Exactly what happened on a customer site: the learner finishes, the push
     * sends "Completed", the learner opens the certificate seconds later, and
     * nothing marks them again. A later push has to find it by itself.
     */
    public function test_a_certificate_issued_after_the_last_push_goes_out_with_a_later_one(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();

        $this->issue($learner->id, $course->id, $now - 300);

        $this->push();

        $certificates = $this->sent_certificates();
        $this->assertCount(1, $certificates);
        $this->assertSame(pseudonym::learner($learner->id), $certificates[0]['learner_pid']);
        $this->assertSame(pseudonym::course($course->id), $certificates[0]['course_ref']);

        // The watermark is where the window ended, two minutes behind the clock.
        $watermark = (int) get_config('local_reportlms', 'watermark_customcertissues');
        $this->assertGreaterThanOrEqual($now - source_sweep::CERTIFICATE_LAG, $watermark);
        $this->assertLessThanOrEqual(time() - source_sweep::CERTIFICATE_LAG, $watermark);
    }

    /**
     * Sent once, not with every push after it.
     *
     * Looking back from the watermark, as the nightly sweep does with its
     * other sources, would find the newest certificate again on every run and
     * send its learner four times an hour.
     */
    public function test_the_next_push_does_not_send_it_again(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();
        $this->issue($learner->id, $course->id, $now - 300);

        $this->push();
        $this->assertCount(1, $this->sent_certificates());
        $this->assertSame(0, userstate::count_dirty(), 'The push sent the learner and cleared them.');

        $this->sent = [];
        $this->push();

        $this->assertSame([], $this->sent_certificates());
        $this->assertSame(0, userstate::count_dirty());
    }

    /**
     * Two certificates issued in the same second, one of them saved just after
     * a push read the table, then a newer one: all three still go out.
     *
     * A watermark that followed the newest certificate seen would have moved
     * past the late one for good. The window waits until a certificate is two
     * minutes old, so the late one is in the table by the time it is read.
     */
    public function test_a_certificate_saved_late_is_still_sent(): void {
        [$first, $firstcourse] = $this->learner();
        [$late, $latecourse] = $this->learner();
        [$newer, $newercourse] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();

        $this->issue($first->id, $firstcourse->id, $now - 30);
        $this->push();
        $this->assertSame([], $this->sent_certificates(), 'Too new to be looked for yet.');

        // Saved after that push read the table, with the same time as the first.
        $this->issue($late->id, $latecourse->id, $now - 30);
        $this->issue($newer->id, $newercourse->id, $now - 5);
        $this->push();
        $this->assertSame([], $this->sent_certificates());

        $this->clock_moves_on(200);
        $this->push();

        $sent = array_column($this->sent_certificates(), 'learner_pid');
        sort($sent);
        $expected = [pseudonym::learner($first->id), pseudonym::learner($late->id), pseudonym::learner($newer->id)];
        sort($expected);
        $this->assertSame($expected, $sent);
    }

    /**
     * A certificate dated in the future waits for its window instead of
     * dragging the watermark past certificates that are not saved yet.
     */
    public function test_a_certificate_dated_in_the_future_does_not_move_the_watermark(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();
        $this->issue($learner->id, $course->id, $now + 3600);

        $this->push();

        $this->assertSame([], $this->sent_certificates());
        $watermark = (int) get_config('local_reportlms', 'watermark_customcertissues');
        $this->assertLessThanOrEqual(time() - source_sweep::CERTIFICATE_LAG, $watermark);

        // Once its date has come and gone, it goes out like any other.
        $this->clock_moves_on(3800);
        $this->push();

        $this->assertSame([pseudonym::learner($learner->id)], array_column($this->sent_certificates(), 'learner_pid'));
    }

    /**
     * A watermark the old nightly sweep left in the future, from a certificate
     * dated ahead, does not hide every certificate until that date.
     */
    public function test_a_watermark_left_in_the_future_reads_back_a_day(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now + 30 * DAYSECS, 'local_reportlms');
        $this->everybody_clean();
        $this->issue($learner->id, $course->id, $now - 300);

        $this->push();

        $this->assertSame([pseudonym::learner($learner->id)], array_column($this->sent_certificates(), 'learner_pid'));
        $watermark = (int) get_config('local_reportlms', 'watermark_customcertissues');
        $this->assertLessThanOrEqual(time() - source_sweep::CERTIFICATE_LAG, $watermark);
    }

    /**
     * After a push, the nightly sweep finds no certificate the push did not.
     *
     * Both read the same windows against the same watermark, so the night no
     * longer sends whoever holds the newest certificate again.
     */
    public function test_after_a_push_the_nightly_sweep_marks_nobody_for_certificates(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();
        $this->issue($learner->id, $course->id, $now - 300);

        $this->push();
        $this->assertCount(1, $this->sent_certificates());

        // Every other source past everything this test wrote, so only the
        // certificate tables could mark anybody.
        $others = ['user', 'enrolments', 'completion', 'cmcompletion', 'grades', 'quizattempts', 'roles', 'courseaccess'];
        foreach ($others as $source) {
            set_config('watermark_' . $source, $now + 3600, 'local_reportlms');
        }

        $this->expectOutputRegex('/reconcile marked 0 learner rows/');
        (new reconcile_userstate())->execute();

        $this->assertSame(0, userstate::count_dirty());
    }

    /**
     * A certificate the push already looked at is not a reason to send anybody again.
     */
    public function test_a_certificate_seen_before_sends_nobody(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        $this->issue($learner->id, $course->id, $now - 3600);
        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->everybody_clean();

        $this->assertSame(0, source_sweep::sweep('customcertissues', source_sweep::certificate_sources()['customcertissues']));
        $this->assertSame(0, userstate::count_dirty());
    }

    /**
     * A learner deleted after receiving a certificate is never marked: they
     * must not be reported on at all.
     */
    public function test_a_deleted_learner_is_not_marked(): void {
        [$learner, $course] = $this->learner();
        $now = time();

        set_config('watermark_customcertissues', $now - 600, 'local_reportlms');
        $this->issue($learner->id, $course->id, $now - 300);
        delete_user($learner);
        $this->everybody_clean();

        $this->assertSame(0, source_sweep::sweep('customcertissues', source_sweep::certificate_sources()['customcertissues']));
        $this->assertSame(0, userstate::count_dirty());
    }

    /**
     * Only the certificate tables this site has are read, each in windows.
     */
    public function test_only_installed_certificate_tables_are_read(): void {
        global $DB;

        $sources = source_sweep::certificate_sources();

        $this->assertArrayHasKey('customcertissues', $sources);
        $this->assertSame(source_sweep::CERTIFICATE_LAG, $sources['customcertissues']['lag']);
        $this->assertSame($DB->get_manager()->table_exists('tool_certificate_issues'), isset($sources['toolcertissues']));
    }

    /**
     * A learner enrolled in a course, everything sent.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function learner(): array {
        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        return [$learner, $course];
    }

    /**
     * A customcert certificate for this learner in this course, issued at this time.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $when
     *
     * @return void
     */
    private function issue(int $userid, int $courseid, int $when): void {
        global $DB;

        if ($this->standins) {
            $customcertid = $DB->insert_record('customcert', (object) ['course' => $courseid, 'name' => 'Certificate']);
        } else {
            $customcertid = $this->getDataGenerator()->create_module('customcert', ['course' => $courseid])->id;
        }

        $DB->insert_record('customcert_issues', (object) [
            'userid' => $userid,
            'customcertid' => $customcertid,
            'code' => random_string(10),
            'emailed' => 0,
            'timecreated' => $when,
        ]);
    }

    /**
     * Build customcert's two tables when the plugin is not installed.
     *
     * Only the columns local_reportlms reads, plus what an issue row needs.
     *
     * @return void
     */
    private function ensure_customcert_tables(): void {
        global $DB;

        $manager = $DB->get_manager();

        if ($manager->table_exists('customcert_issues')) {
            return;
        }

        $this->standins = true;

        $customcert = new \xmldb_table('customcert');
        $customcert->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $customcert->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $customcert->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $customcert->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $manager->create_table($customcert);

        $issues = new \xmldb_table('customcert_issues');
        $issues->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $issues->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $issues->add_field('customcertid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $issues->add_field('code', XMLDB_TYPE_CHAR, '40', null, null);
        $issues->add_field('emailed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $issues->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $issues->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $manager->create_table($issues);
    }

    /**
     * As if this many seconds passed: every certificate and the watermark move back by it.
     *
     * @param int $seconds
     *
     * @return void
     */
    private function clock_moves_on(int $seconds): void {
        global $DB;

        $DB->execute('UPDATE {customcert_issues} SET timecreated = timecreated - ?', [$seconds]);
        $watermark = (int) get_config('local_reportlms', 'watermark_customcertissues');
        set_config('watermark_customcertissues', $watermark - $seconds, 'local_reportlms');
    }

    /**
     * Nobody waiting to be sent, as after a push that went through.
     *
     * @return void
     */
    private function everybody_clean(): void {
        global $DB;

        $DB->set_field(userstate::TABLE, 'dirty', 0, []);
    }

    /**
     * Run one push against a client that accepts everything and keeps the payloads.
     *
     * @return void
     */
    private function push(): void {
        $sent = &$this->sent;
        $client = new class (\local_reportlms\local\config::baseurl(), $sent) extends api_client {
            /** @var array */
            private $sent;

            /**
             * Keep the payloads in the test's list.
             *
             * @param string $baseurl
             * @param array $sent
             */
            public function __construct(string $baseurl, array &$sent) {
                parent::__construct($baseurl);
                $this->sent = &$sent;
            }

            /**
             * Answer like a Report LMS that sends no plan limit.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                return [];
            }

            /**
             * Accept the payload and keep it.
             *
             * @param string $pushpath
             * @param string $rawbody
             * @param string $apikey
             * @param string $signingsecret
             * @param string $idempotencykey
             *
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

                return ['batch_id' => 'b-test'];
            }
        };

        $task = new push_batch();
        $task->set_client($client);

        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * Every certificate fact the push sent.
     *
     * @return array<int, array>
     */
    private function sent_certificates(): array {
        $certificates = [];

        foreach ($this->sent as $payload) {
            foreach ($payload['facts']['certificates'] ?? [] as $row) {
                $certificates[] = $row;
            }
        }

        return $certificates;
    }
}
