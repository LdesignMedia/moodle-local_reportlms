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
 * Events are the fast path but they are not complete. Course restores, plugin
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
     * @return array<string, array{table: string, column: string, userfield: string}>
     */
    protected function sources(): array {
        return [
            'user' => ['table' => 'user', 'column' => 'timemodified', 'userfield' => 'id'],
            'enrolments' => ['table' => 'user_enrolments', 'column' => 'timemodified', 'userfield' => 'userid'],
            'completion' => ['table' => 'course_completions', 'column' => 'timecompleted', 'userfield' => 'userid'],
            'cmcompletion' => ['table' => 'course_modules_completion', 'column' => 'timemodified', 'userfield' => 'userid'],
            'grades' => ['table' => 'grade_grades', 'column' => 'timemodified', 'userfield' => 'userid'],
            'quizattempts' => ['table' => 'quiz_attempts', 'column' => 'timemodified', 'userfield' => 'userid'],
        ];
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
            // the last run would otherwise fall between two sweeps.
            $from = max(0, $watermark - 60);
            $highest = $watermark;

            // Joined to user rather than read straight from the source table:
            // grade and enrolment rows outlive the account, so a plain sweep
            // marks deleted learners dirty and the next push carries people
            // who must not be reported on at all.
            $sql = "SELECT s.{$source['userfield']} AS userid, MAX(s.{$source['column']}) AS changed
                      FROM {{$source['table']}} s
                      JOIN {user} u ON u.id = s.{$source['userfield']}
                     WHERE s.{$source['column']} > :from
                       AND u.deleted = 0
                       AND u.id >= " . userstate::FIRST_REPORTABLE_USERID . "
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
