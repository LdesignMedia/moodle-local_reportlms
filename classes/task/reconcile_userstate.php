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
 * Safety net for changes that raise no event.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\task;

use local_reportlms\local\source_sweep;
use local_reportlms\local\userstate;

/**
 * Sweeps the source tables for changes the observers never saw.
 *
 * Events are the fast path, but they are not complete. Course restores, plugin
 * upgrades, enrolment sync tasks, gradebook recalculation and anything written
 * straight to the database all change reported data without raising an event we
 * observe. Without this sweep the SaaS drifts quietly, which is worse than
 * failing, because the dashboard keeps showing numbers that look fine.
 *
 * Runs far less often than the push, since it exists to catch what leaked
 * rather than to be the primary mechanism.
 */
class reconcile_userstate extends \core\task\scheduled_task {
    /**
     * Where each source keeps its modification time.
     *
     * course_completions is the odd one out: it has timeenrolled, timestarted
     * and timecompleted but no timemodified, so a completion being revoked or
     * an in-progress change cannot be swept. Those rely on the event, which is
     * why the pair matters rather than either alone.
     *
     * A source may add a join and a further condition, both written against
     * the source table as "s", when only some of its rows can change a
     * learner's slice, and a longer overlap than the default minute.
     *
     * @return array<string, array{table: string, column: string, userfield: string, lag?: int, overlap?: int,
     *     join?: string, where?: string}>
     */
    protected function sources(): array {
        $sources = [
            'user' => ['table' => 'user', 'column' => 'timemodified', 'userfield' => 'id'],
            'enrolments' => ['table' => 'user_enrolments', 'column' => 'timemodified', 'userfield' => 'userid'],
            'completion' => ['table' => 'course_completions', 'column' => 'timecompleted', 'userfield' => 'userid'],
            'cmcompletion' => ['table' => 'course_modules_completion', 'column' => 'timemodified', 'userfield' => 'userid'],
            'grades' => ['table' => 'grade_grades', 'column' => 'timemodified', 'userfield' => 'userid'],
            'quizattempts' => ['table' => 'quiz_attempts', 'column' => 'timemodified', 'userfield' => 'userid'],
            // Role assignments decide whether an enrolment counts as a
            // learner at all (see local\gradable), so a missed role event has
            // to be sweepable the same as a missed enrolment event. Deletions
            // leave no row to sweep; the role_unassigned observer carries
            // those, which is why the pair matters here too.
            'roles' => ['table' => 'role_assignments', 'column' => 'timemodified', 'userfield' => 'userid'],
            /*
             * Opening a course is what turns not started into in progress (see
             * collector\completion::started()), and it raises no event this
             * plugin observes. Swept, a first visit lands within a night.
             *
             * Narrowed to the visits that can change anything: an unfinished
             * record, with no activity done in the course yet. Somebody who has
             * done one is in progress already, so their browsing would only
             * re-send the same slice night after night.
             *
             * A day of overlap rather than a minute. The record is made by
             * Moodle's daily completion task, at its own hour, and that raises
             * no event either. Somebody enrolled today who opens the course
             * before tonight's sweep has no record yet, so the sweep skips them;
             * with a minute's overlap their visit would be behind the watermark
             * by the next night, and they would read not started until they did
             * something else. The cost is that a visit is looked at twice.
             *
             * The watermark starts at the upgrade that added this source rather
             * than at zero, or the first sweep would mark everybody who ever
             * opened anything.
             */
            'courseaccess' => [
                'table' => 'user_lastaccess',
                'column' => 'timeaccess',
                'userfield' => 'userid',
                'overlap' => DAYSECS,
                'join' => 'JOIN {course_completions} cc
                                ON cc.userid = s.userid
                               AND cc.course = s.courseid
                               AND cc.timecompleted IS NULL',
                'where' => 'AND NOT EXISTS (
                                SELECT 1
                                  FROM {course_modules_completion} cmc
                                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                                 WHERE cmc.userid = s.userid
                                   AND cm.course = s.courseid
                                   AND cmc.completionstate > 0
                            )',
            ],
        ];

        /*
         * Certificates, which nothing else reliably notices.
         *
         * Every push sweeps these too, the same way and against the same
         * watermarks (see source_sweep), which is what gets a certificate to
         * the report within one push rather than one night. So on a site whose
         * push runs, the night finds nothing here that a push has not already
         * marked. Listed all the same: one query a night is cheap, and the
         * nightly task is where every source is swept.
         *
         * Until 2026-08-19 no certificate table was listed here at all, so a
         * customcert issue reached Report LMS only when its learner happened to
         * be marked dirty for some unrelated reason. Appended rather than woven
         * in, and only the tables that exist: an unguarded read of one that is
         * not installed would throw and abandon every source after it.
         */
        return array_merge($sources, source_sweep::certificate_sources());
    }

    /**
     * Task name shown in the scheduled task admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:reconcile', 'local_reportlms');
    }

    /**
     * Sweep every source and mark whoever changed.
     */
    public function execute(): void {
        if (!\local_reportlms\local\connect_manager::is_connected()) {
            mtrace('Report LMS is not connected, skipping the reconcile sweep.');

            return;
        }

        $marked = 0;

        foreach ($this->sources() as $key => $source) {
            $marked += source_sweep::sweep($key, $source);
        }

        // Tracking rows for users Moodle does not hold. They go out once as
        // deleted on the next push and are then removed.
        $orphans = userstate::mark_orphans_dirty();
        if ($orphans) {
            mtrace("Report LMS reconcile found {$orphans} tracking rows without a user.");
            $marked += $orphans;
        }

        mtrace("Report LMS reconcile marked {$marked} learner rows, "
            . userstate::count_dirty() . ' now waiting to be sent.');
    }
}
