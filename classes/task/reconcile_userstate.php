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
     * @return array<string, array{table: string, column: string, userfield: string, overlap?: int, join?: string,
     *     where?: string}>
     */
    protected function sources(): array {
        global $DB;

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
         * mod_customcert raises no event when it issues one — its events cover
         * templates, pages and elements, which are authoring actions rather
         * than a learner receiving anything. db/events.php said the reconcile
         * sweep was the fallback for that, and docs/known-issues.md said the
         * fallback worked; neither was true, because no certificate table was
         * ever listed here. A customcert issue reached Report LMS only when the
         * learner happened to be marked dirty for some unrelated reason, so on
         * a site that issues certificates and little else the tile could stay
         * wrong indefinitely.
         *
         * Both tables are optional, and appended rather than woven in: the
         * sweep loop has no error handling, so an unguarded read of a table
         * that is not installed would throw and abandon every source after it.
         * Guarded and last, the worst case is that these two are missing.
         *
         * tool_certificate does raise events and is observed, so sweeping it is
         * belt and braces. It costs one query per sweep and closes the same gap
         * if an event is ever missed.
         */
        $manager = $DB->get_manager();

        $certificates = [
            'customcertissues' => ['table' => 'customcert_issues', 'column' => 'timecreated', 'userfield' => 'userid'],
            'toolcertissues' => ['table' => 'tool_certificate_issues', 'column' => 'timecreated', 'userfield' => 'userid'],
        ];

        foreach ($certificates as $key => $source) {
            if ($manager->table_exists($source['table'])) {
                $sources[$key] = $source;
            }
        }

        return $sources;
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
        global $DB;

        if (!\local_reportlms\local\connect_manager::is_connected()) {
            mtrace('Report LMS is not connected, skipping the reconcile sweep.');

            return;
        }

        $marked = 0;

        foreach ($this->sources() as $key => $source) {
            $watermark = (int) get_config('local_reportlms', 'watermark_' . $key);
            // A little overlap on purpose: rows written in the same second as
            // the last run would otherwise fall between two sweeps. A source
            // can ask for more; see courseaccess.
            $from = max(0, $watermark - ($source['overlap'] ?? 60));
            $highest = $watermark;

            // Joined to user rather than read straight from the source table:
            // grade and enrolment rows outlive the account, so a plain sweep
            // marks deleted learners dirty and the next push carries people
            // who must not be reported on at all.
            $join = $source['join'] ?? '';
            $where = $source['where'] ?? '';
            $sql = "SELECT s.{$source['userfield']} AS userid, MAX(s.{$source['column']}) AS changed
                      FROM {{$source['table']}} s
                      JOIN {user} u ON u.id = s.{$source['userfield']}
                           {$join}
                     WHERE s.{$source['column']} > :from
                       AND u.deleted = 0
                       AND u.id >= " . userstate::FIRST_REPORTABLE_USERID . "
                           {$where}
                  GROUP BY s.{$source['userfield']}";

            $recordset = $DB->get_recordset_sql($sql, ['from' => $from]);

            foreach ($recordset as $row) {
                userstate::mark_dirty((int) $row->userid);
                $marked++;
                $highest = max($highest, (int) $row->changed);
            }

            $recordset->close();

            // Only advance once the sweep finished. A crash mid-source repeats
            // it next time, which is wasteful but never loses a change.
            set_config('watermark_' . $key, $highest, 'local_reportlms');
        }

        mtrace("Report LMS reconcile marked {$marked} learner rows, "
            . userstate::count_dirty() . ' now waiting to be sent.');
    }
}
