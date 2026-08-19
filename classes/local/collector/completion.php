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
 * Only learners with a completion record appear here. "Not started" is not a
 * row this collector can produce: it is derived on the SaaS side as
 * enrolments minus completion rows, which is exactly why the enrolments
 * block exists.
 *
 * due_at is always null here, and stays that way. A deadline is the
 * enrolment's end date and nothing else — decided by Nihaal on 2026-08-19 —
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

        $rows = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $completed = !empty($record->timecompleted);
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
}
