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
     *         attempts (int).
     */
    public static function collect(array $userids): array {
        global $CFG, $DB;

        if (!$userids) {
            return [];
        }

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['criteriatype'] = COMPLETION_CRITERIA_TYPE_ACTIVITY;

        // The criteria join is what selects the main activity: only attempts
        // on a quiz that gates its course's completion are counted.
        $sql = "SELECT qa.userid, cm.id AS cmid, COUNT(qa.id) AS attempts
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
              GROUP BY qa.userid, cm.id
              ORDER BY qa.userid, cm.id";

        $rows = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'cmid' => (int) $record->cmid,
                'kind' => 'quiz',
                'summative' => true,
                'attempts' => (int) $record->attempts,
            ];
        }

        return $rows;
    }
}
