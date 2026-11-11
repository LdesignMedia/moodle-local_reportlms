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
 * Collector for the completion fact block.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The completion block: finished or in progress, per learner per course.
 *
 * Only learners with a completion record who have started appear here. "Not
 * started" is not a row this collector produces: it is derived on the SaaS side
 * as enrolments minus completion rows, which is exactly why the enrolments
 * block exists.
 *
 * Having a record does not mean having started. Moodle's daily completion task
 * ("Marking users as started") gives every enrolled student in a course with
 * completion tracking a record the day after they are enrolled, whether or not
 * they ever sign in. This collector used to send every unfinished record as in
 * progress, so the dashboard read "in progress" for people who had never logged
 * in. Seen on a customer site: a learner with no first access, whom Moodle's own
 * completion status block called "Not yet started", was in progress in Report
 * LMS.
 *
 * So an unfinished record is sent only with a sign of a start (see started())
 * and left out otherwise. Report LMS drops an in-progress row it holds for a
 * learner the batch names but no longer mentions, so leaving it out is how an
 * old in-progress row gets cleared. That is omission rather than a new status
 * on purpose: a Report LMS that does not know a status refuses the batch, and
 * a refused chunk is rebuilt the same way every run, so the site would stop
 * pushing for good.
 *
 * due_at is always null here, and stays that way. A deadline is the
 * enrolment's end date and nothing else, decided on 2026-08-19,
 * so it belongs on the enrolment row, which every learner has, rather than on
 * a completion row, which only somebody who has started has. See the
 * enrolments collector, which resolves it.
 *
 * This reverses the TD's v1.0 plan for a per-course resolver with relative and
 * fixed modes. That is not deferred, it is dropped: a site that sets no
 * enrolment durations reads no overdue, and setting them is the customer's
 * side of the contract.
 */
class completion {
    /**
     * Completion rows for a batch of learners.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, courseid, status
     *         ('completed'|'in_progress'), completed_at (unix|null),
     *         due_at (unix|null).
     */
    public static function collect(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // The join to {course} is load-bearing, not decoration. Deleting a
        // course does not always take its course_completions rows with it, and
        // a completion for a course that no longer exists cannot be reported
        // on: the catalogue cannot name it, so Report LMS would hold a row
        // showing a raw coded id in the training picker forever. Whatever is
        // sent has to be nameable.
        $sql = "SELECT cc.id, cc.userid, cc.course AS courseid, cc.timecompleted
                  FROM {course_completions} cc
                  JOIN {user} u ON u.id = cc.userid
                  JOIN {course} c ON c.id = cc.course
                 WHERE u.deleted = 0
                   AND cc.userid {$insql}
              ORDER BY cc.userid, cc.course";

        $records = $DB->get_records_sql($sql, $params);
        $started = $records ? self::started($userids) : [];

        $rows = [];
        foreach ($records as $record) {
            $completed = !empty($record->timecompleted);

            if (!$completed && !isset($started[$record->userid . ':' . $record->courseid])) {
                continue;
            }

            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'status' => $completed ? 'completed' : 'in_progress',
                'completed_at' => $completed ? (int) $record->timecompleted : null,
                'due_at' => null,
            ];
        }

        return $rows;
    }

    /**
     * The unfinished learner and course pairs that show a sign of a start.
     *
     * Any of these counts:
     *
     * - They opened the course: a user_lastaccess row. Moodle writes none for a
     *   teacher's "log in as", so a manager looking around as them is not a
     *   start.
     * - An activity in the course is marked complete, passed or failed, by them
     *   or by a teacher.
     * - They made a quiz attempt in the course. A visit nearly always comes
     *   with it, but not always: unenrolling deletes the visit and keeps the
     *   attempts, so somebody re-enrolled would otherwise read not started.
     * - They hold a grade above the item's minimum on one of the course's
     *   activities or manual items, which covers an assessment graded offline.
     *   Above the minimum, because "absent" in an attendance register and a zero
     *   entered for missing work are grades too, and neither is a start.
     *
     * course_completions.timestarted is deliberately not one of them. Moodle
     * sets it whenever any criterion is met, including the ones its own tasks
     * mark with nobody present: a date passing, an enrolment lasting long
     * enough, another course being finished. Finishing course A would make
     * course B read in progress, which is the very thing this fixes.
     *
     * One set-based query each for the whole batch, limited to unfinished
     * records, since a finished one is reported as completed either way.
     *
     * @param array<int, int> $userids
     * @return array<string, true> Keyed "userid:courseid".
     */
    private static function started(array $userids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $queries = [
            "SELECT DISTINCT ula.userid, ula.courseid
               FROM {user_lastaccess} ula
               JOIN {course_completions} cc
                    ON cc.userid = ula.userid
                   AND cc.course = ula.courseid
                   AND cc.timecompleted IS NULL
              WHERE ula.userid {$insql}",
            "SELECT DISTINCT cmc.userid, cm.course AS courseid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {course_completions} cc
                    ON cc.userid = cmc.userid
                   AND cc.course = cm.course
                   AND cc.timecompleted IS NULL
              WHERE cmc.userid {$insql}
                AND cmc.completionstate > 0",
            "SELECT DISTINCT qa.userid, q.course AS courseid
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {course_completions} cc
                    ON cc.userid = qa.userid
                   AND cc.course = q.course
                   AND cc.timecompleted IS NULL
              WHERE qa.userid {$insql}
                AND qa.preview = 0",
            "SELECT DISTINCT gg.userid, gi.courseid
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
               JOIN {course_completions} cc
                    ON cc.userid = gg.userid
                   AND cc.course = gi.courseid
                   AND cc.timecompleted IS NULL
              WHERE gg.userid {$insql}
                AND gi.itemtype IN ('mod', 'manual')
                AND gg.finalgrade > gi.grademin",
        ];

        $started = [];
        foreach ($queries as $sql) {
            $recordset = $DB->get_recordset_sql($sql, $params);
            foreach ($recordset as $row) {
                $started[$row->userid . ':' . $row->courseid] = true;
            }
            $recordset->close();
        }

        return $started;
    }
}
