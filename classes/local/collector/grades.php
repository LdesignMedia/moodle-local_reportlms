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
 * Collector for the grades fact block.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The grades block: the learner's score on the main activity, as a percentage.
 *
 * Every grade is normalised to a 100-point scale before it leaves the site:
 * (finalgrade - grademin) / (grademax - grademin) * 100. One customer grades
 * out of 10, another out of 20, another weights items; the SaaS should never
 * need to know.
 *
 * Scale grades (competent / not competent style) are skipped on purpose. A
 * position on a wordy scale is not a percentage, and pretending it is one
 * would put misleading numbers on an audit dashboard. Only value-type grade
 * items are collected.
 *
 * Like attempts, this is main-activity only: the graded quiz that gates the
 * course's completion.
 */
class grades {

    /**
     * Normalised grades for a batch of learners.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, cmid (the activity_ref
     *         source), grade (float, 0-100), grade_max (always 100).
     */
    public static function collect(array $userids): array {
        global $CFG, $DB;

        if (!$userids) {
            return [];
        }

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        require_once($CFG->libdir . '/gradelib.php');

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['criteriatype'] = COMPLETION_CRITERIA_TYPE_ACTIVITY;
        $params['gradetype'] = GRADE_TYPE_VALUE;

        // The criteria join selects the main activity, the same rule the
        // attempts collector uses. gradetype filters out scale and text items.
        $sql = "SELECT gg.id, gg.userid, cm.id AS cmid,
                       gg.finalgrade, gi.grademax, gi.grademin
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                       AND gi.itemtype = 'mod'
                       AND gi.gradetype = :gradetype
                  JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                  JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
                  JOIN {course_completion_criteria} ccc
                       ON ccc.moduleinstance = cm.id
                      AND ccc.course = cm.course
                      AND ccc.criteriatype = :criteriatype
                  JOIN {user} u ON u.id = gg.userid
                 WHERE u.deleted = 0
                   AND gg.finalgrade IS NOT NULL
                   AND gg.userid {$insql}
              ORDER BY gg.userid, cm.id";

        $rows = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $range = (float) $record->grademax - (float) $record->grademin;
            if ($range <= 0) {
                // A zero-range item cannot be expressed as a percentage.
                continue;
            }
            $percentage = ((float) $record->finalgrade - (float) $record->grademin) / $range * 100;

            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'cmid' => (int) $record->cmid,
                'grade' => round($percentage, 2),
                'grade_max' => 100,
            ];
        }

        return $rows;
    }
}
