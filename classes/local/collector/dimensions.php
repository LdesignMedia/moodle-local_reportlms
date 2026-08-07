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
 * Collector for the dimensions section of the push payload.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The dimensions section: the catalogue the facts point at.
 *
 * Course and activity titles are the one label the contract lets leave the
 * site as text, because a course name is organisational, not personal — the
 * plan's data model says "codes with optional non-personal titles". People,
 * departments and functions stay coded; this is what makes the dashboard's
 * dropdowns readable without touching that rule.
 *
 * Full state, like the org tree: the catalogue is small (courses the facts
 * reference plus their reportable activities), and last-state-wins is the only
 * merge that cannot drift.
 *
 * "Courses the facts reference" is the rule, and it is deliberately wider than
 * "courses with an enrolment". A learner unenrolled after finishing keeps their
 * course_completions row, so the completion collector reports a course nobody
 * is enrolled in any more. Report LMS mints a dimension row for a course it is
 * sent a fact about, and if the catalogue never names that course the row keeps
 * its coded id forever: nothing revisits it, because the catalogue hash has no
 * reason to move. That is what put a raw c_... code in a live dashboard.
 */
class dimensions {
    /**
     * The full catalogue state.
     *
     * Courses: every real course the facts can reference, which means one
     * with an enrolment or a completion. Activities:
     * the course modules facts are reported on — those with completion
     * tracking, plus the main (completion-gating) activity, which is also
     * the one flagged summative, mirroring the attempts collector's rule.
     *
     * @return \stdClass {courses: [{courseid, title, active}],
     *                    activities: [{cmid, courseid, title, kind, summative}]}
     */
    public static function collect(): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $courses = [];
        $activities = [];

        $courserecords = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.visible
               FROM {course} c
              WHERE c.id > 1
                AND (
                    EXISTS (
                        SELECT 1
                          FROM {enrol} e
                          JOIN {user_enrolments} ue ON ue.enrolid = e.id
                         WHERE e.courseid = c.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {course_completions} cc
                         WHERE cc.course = c.id
                    )
                )
           ORDER BY c.id"
        );

        foreach ($courserecords as $course) {
            $courses[] = (object) [
                'courseid' => (int) $course->id,
                'title' => trim($course->fullname),
                'active' => (bool) $course->visible,
            ];

            // The completion-gating activities, summative by the same rule
            // the attempts collector applies.
            $criteriacmids = $DB->get_fieldset_select(
                'course_completion_criteria',
                'moduleinstance',
                'course = ? AND criteriatype = ?',
                [$course->id, COMPLETION_CRITERIA_TYPE_ACTIVITY]
            );
            $criteriacmids = array_map('intval', $criteriacmids);

            $modinfo = get_fast_modinfo((int) $course->id, -1);
            foreach ($modinfo->cms as $cm) {
                if ($cm->deletioninprogress) {
                    continue;
                }
                $ismain = in_array((int) $cm->id, $criteriacmids, true);
                if ($cm->completion == COMPLETION_TRACKING_NONE && !$ismain) {
                    // Facts are only reported on tracked activities and the
                    // main test; the rest of the catalogue would be noise.
                    continue;
                }
                $activities[] = (object) [
                    'cmid' => (int) $cm->id,
                    'courseid' => (int) $course->id,
                    'title' => trim((string) $cm->name),
                    'kind' => $cm->modname,
                    'summative' => $ismain,
                ];
            }
        }

        return (object) ['courses' => $courses, 'activities' => $activities];
    }

    /**
     * Deterministic fingerprint of the catalogue, for send-only-on-change.
     *
     * @param \stdClass $dimensions As returned by collect().
     *
     * @return string 40 hex chars.
     */
    public static function state_hash(\stdClass $dimensions): string {
        return sha1(json_encode($dimensions));
    }
}
