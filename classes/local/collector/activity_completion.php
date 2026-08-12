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
 * Collector for the activity_completion fact block.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The activity_completion block: which activities a learner has finished.
 *
 * This is what progress-within-a-training is built from: 7 of 11 activities
 * done. All three of Moodle's complete states count as finished here -
 * complete, complete-pass and complete-fail - because "did the learner get
 * through this activity" is a different question from "did they pass it",
 * and the pass question is already answered by the grades block.
 *
 * Only activities with completion tracking enabled can appear, which is a
 * property of the course setup, not of this collector.
 */
class activity_completion {

    /**
     * Completed-activity rows for a batch of learners.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, cmid (the activity_ref
     *         source), kind (module type, e.g. 'page', 'quiz'),
     *         completed_at (unix).
     */
    public static function collect(array $userids): array {
        global $CFG, $DB;

        if (!$userids) {
            return [];
        }

        require_once($CFG->libdir . '/completionlib.php');

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['incomplete'] = COMPLETION_INCOMPLETE;

        $sql = "SELECT cmc.id, cmc.userid, cm.id AS cmid, cm.course AS courseid, m.name AS kind, cmc.timemodified
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {user} u ON u.id = cmc.userid
                 WHERE u.deleted = 0
                   AND cmc.completionstate <> :incomplete
                   AND cmc.userid {$insql}
              ORDER BY cmc.userid, cm.id";

        $rows = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'cmid' => (int) $record->cmid,
                // Internal, never serialised: the batch builder filters rows
                // by who counts as a learner in the course.
                'courseid' => (int) $record->courseid,
                'kind' => $record->kind,
                'completed_at' => (int) $record->timemodified,
            ];
        }

        return $rows;
    }
}
