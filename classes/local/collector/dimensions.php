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
 * site as text, because a course name is organisational, not personal, the
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
     * the course modules facts are reported on, those with completion
     * tracking, plus the main (completion-gating) activity, which is also
     * the one flagged summative, mirroring the attempts collector's rule.
     *
     * @return \stdClass {courses: [{courseid, title, active, measurable, timecreated}],
     *                    activities: [{cmid, courseid, title, kind, summative}]}
     */
    /**
     * Course ids holding at least one completion criterion, as a lookup.
     *
     * One query for the site rather than one per course: this is read while
     * walking every reportable course.
     *
     * @return array<int, int> courseid => position, for isset() tests.
     */
    private static function courses_with_criteria(): array {
        global $DB;

        $ids = $DB->get_fieldset_sql('SELECT DISTINCT course FROM {course_completion_criteria}');

        return array_flip(array_map('intval', $ids));
    }

    /**
     * The courses this site reports on: anything with an enrolment or a
     * completion behind it.
     *
     * Shared with unmeasurable_courses() so the page that tells an
     * administrator which courses are missing a rule cannot come to a
     * different answer than the collector that hid them.
     *
     * @return array<int, \stdClass> id, fullname, visible, enablecompletion, timecreated.
     */
    private static function reportable_courses(): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.visible, c.enablecompletion, c.timecreated
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
    }

    /**
     * Reportable courses that can never complete anybody, for the manage page.
     *
     * The report drops these, so somebody has to be told which they are and
     * where to go. It has to be said here rather than on the dashboard: course
     * identifiers reach the SaaS as one-way pseudonyms, so it can count these
     * courses and can never name one or link to it.
     *
     * @return array<int, string> courseid => course fullname.
     */
    public static function unmeasurable_courses(): array {
        $withcriteria = self::courses_with_criteria();
        $unmeasurable = [];

        foreach (self::reportable_courses() as $course) {
            if (!$course->enablecompletion || !isset($withcriteria[(int) $course->id])) {
                $unmeasurable[(int) $course->id] = trim($course->fullname);
            }
        }

        // Sorted here rather than in the query, which is shared with collect()
        // whose output is hashed to decide whether the catalogue changed.
        // Reordering it there would re-push the whole catalogue for nothing.
        \core_collator::asort($unmeasurable);

        return $unmeasurable;
    }

    /**
     * The catalogue: every reportable course and its activities.
     *
     * @return \stdClass {courses, activities}
     */
    public static function collect(): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $courses = [];
        $activities = [];

        /*
         * Which courses Moodle is able to complete anybody on.
         *
         * A course states what finishing means through its completion criteria.
         * Without any, Moodle never writes a course_completions row however
         * well somebody scores, so the course cannot report completion, cannot
         * report overdue (nothing unfinished can ever become finished), and
         * yields no grade or attempt rows either: both of those collectors find
         * the main activity by joining course_completion_criteria.
         *
         * Such a course still has enrolments, so left alone it puts people in
         * the denominator who can never reach the numerator, and one of them
         * holds a whole department at nought per cent.
         *
         * Collected in one query rather than per course: this runs over every
         * reportable course on the site.
         */
        $withcriteria = self::courses_with_criteria();
        $courserecords = self::reportable_courses();

        foreach ($courserecords as $course) {
            $courses[] = (object) [
                'courseid' => (int) $course->id,
                'title' => trim($course->fullname),
                'active' => (bool) $course->visible,
                /*
                 * Both halves are required and they fail the same way.
                 *
                 * Tracking switched off is the obvious case. Tracking on with
                 * no criteria behind it is the one that fools people, because
                 * the course looks configured and Moodle's own completion
                 * report refuses to run for it.
                 */
                'measurable' => (bool) $course->enablecompletion
                    && isset($withcriteria[(int) $course->id]),
                /*
                 * When the course was made, so the training's timeline can
                 * start where the training did rather than at whoever happened
                 * to be enrolled first.
                 *
                 * Null rather than 0 when Moodle holds none: a course restored
                 * or created by a script can carry 0, and 0 sent as a date is
                 * 1 January 1970, which every reader would take literally.
                 *
                 * Part of the hashed catalogue on purpose. The first push after
                 * an upgrade therefore re-sends every course once, which is
                 * how the date reaches courses that have not changed since.
                 *
                 * The same push also re-sends every learner with a counted
                 * attempt on a main quiz, for the attempts block's
                 * last_attempt_at: the 2026102200 step in db/upgrade.php marks
                 * them dirty, because facts only travel for dirty learners.
                 */
                'timecreated' => (int) $course->timecreated > 0 ? (int) $course->timecreated : null,
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
