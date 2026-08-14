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
 * Collector for the enrolments fact block.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The enrolments block: who is enrolled in what, since when, still active.
 *
 * This is the block that makes the not-started count possible. A learner who
 * never opened a course has no completion record and no attempts; the
 * enrolment row is the only proof they exist and are expected to train.
 *
 * A learner can be enrolled in the same course through more than one method
 * (manual plus cohort sync is common). The contract wants one row per learner
 * per course, so methods are collapsed: earliest start wins, and the row is
 * active when any method is active.
 */
class enrolments {
    /**
     * Enrolment rows for a batch of learners.
     *
     * Suspended accounts stay in (they carry active=false in the attrs block,
     * not here); excluded accounts are the exclusions helper's decision and
     * are filtered before this query by the caller passing a clean id list,
     * plus defensively here for deleted accounts.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, courseid, enrolled_at (unix), active (0/1).
     */
    public static function collect(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // CASE picks the intended start when set, falling back to the moment
        // the enrolment was created. MIN/MAX collapse multiple enrolment
        // methods into the single row the contract expects.
        $sql = "SELECT ue.userid, e.courseid,
                       MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS enrolled_at,
                       MAX(CASE WHEN ue.status = 0 AND e.status = 0 THEN 1 ELSE 0 END) AS active
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE u.deleted = 0
                   AND ue.userid {$insql}
              GROUP BY ue.userid, e.courseid
              ORDER BY ue.userid, e.courseid";

        // A recordset, never get_records_sql: that helper keys rows by the
        // first column, and userid repeats once per course here, so on any
        // multi-course site all but one enrolment per learner would be
        // silently dropped.
        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $record) {
            $rows[] = $record;
        }
        $recordset->close();

        return $rows;
    }
}
