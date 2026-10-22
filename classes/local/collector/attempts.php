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
 * Collector for the attempts fact block.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The attempts block: tries on the main test, per learner.
 *
 * Attempts are counted on the main activity only, never summed across a
 * course: adding tries from different activities together would mislead.
 * The main activity is the graded quiz that gates course completion, found
 * through the course's completion criteria. Version 1 is quiz-only, per the
 * plan; a course whose completion is not gated by a quiz produces no rows.
 *
 * Only finished, non-preview attempts count. An attempt a learner abandoned
 * halfway is not a try, and a teacher previewing the quiz is not a learner.
 */
class attempts {
    /**
     * Attempt counts for a batch of learners.
     *
     * One row per learner per main quiz: the summative attempt count the
     * dashboard's 1/2/3/4+ bands are built from.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, cmid (course module id, the
     *         activity_ref source), kind ('quiz'), summative (true),
     *         attempts (int), lastattemptat (int unix time, or null).
     */
    public static function collect(array $userids): array {
        global $CFG, $DB;

        if (!$userids) {
            return [];
        }

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['criteriatype'] = COMPLETION_CRITERIA_TYPE_ACTIVITY;

        /*
         * The criteria join is what selects the main activity: only attempts
         * on a quiz that gates its course's completion are counted.
         *
         * The last finished attempt rides with the count, over the same rows,
         * so the date can never belong to an attempt the count left out. The
         * state filter below already excludes an attempt still in progress,
         * whose timefinish is 0; a finished one with 0 stored anyway (an
         * import, a repair script) would still make MAX() answer 0, so that
         * is turned into "unknown" when the row is built rather than sent as
         * 1 January 1970.
         */
        $sql = "SELECT qa.userid, cm.id AS cmid, cm.course AS courseid, COUNT(qa.id) AS attempts,
                       MAX(qa.timefinish) AS lastattemptat
                  FROM {quiz_attempts} qa
                  JOIN {course_modules} cm ON cm.instance = qa.quiz
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {course_completion_criteria} ccc
                       ON ccc.moduleinstance = cm.id
                      AND ccc.course = cm.course
                      AND ccc.criteriatype = :criteriatype
                  JOIN {user} u ON u.id = qa.userid
                 WHERE u.deleted = 0
                   AND qa.state = 'finished'
                   AND qa.preview = 0
                   AND qa.userid {$insql}
              GROUP BY qa.userid, cm.id, cm.course
              ORDER BY qa.userid, cm.id";

        // A recordset, never get_records_sql: that helper keys rows by the
        // first column, and userid repeats once per main quiz here, so on a
        // site with several criteria-gated courses all but one attempt row
        // per learner would be silently dropped.
        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'cmid' => (int) $record->cmid,
                // Internal, never serialised: the batch builder filters rows
                // by who counts as a learner in the course.
                'courseid' => (int) $record->courseid,
                'kind' => 'quiz',
                'summative' => true,
                'attempts' => (int) $record->attempts,
                'lastattemptat' => (int) $record->lastattemptat > 0 ? (int) $record->lastattemptat : null,
            ];
        }
        $recordset->close();

        return $rows;
    }
}
