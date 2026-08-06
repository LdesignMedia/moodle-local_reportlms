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
 * The people behind a figure on the dashboard.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\org;

/**
 * Turns a coded selection back into a list of actual people.
 *
 * This is the half of the report that Report LMS structurally cannot do. It
 * holds counts against coded identifiers and has never seen a name, so
 * "who are those 23 people" can only be answered here, from this site's own
 * tables, under this site's own access checks.
 *
 * The dashboard hands over the codes it was filtering by. Nothing comes back
 * to it: the answer is rendered on a Moodle page or written to a file the
 * administrator downloads.
 */
class people {
    /** @var int Never return more than this in one page. */
    public const MAX_ROWS = 5000;

    /**
     * Learners in scope who have not completed a course they are enrolled in.
     *
     * "Not completed" rather than "past the deadline": the deadline resolver
     * does not exist yet, so every due date this site sends is null and nothing
     * can honestly be called late. Once the resolver lands, this is where the
     * due date filter goes, and the dashboard's overdue tile can point at it
     * without changing.
     *
     * @param filter $filter
     * @param int $limit
     *
     * @return array<int, \stdClass> Rows: userid, fullname, email, courseid,
     *         coursename, enrolled_at, status.
     */
    public static function not_completed(filter $filter, int $limit = self::MAX_ROWS): array {
        global $DB;

        $params = [];
        $where = [
            'u.deleted = 0',
            'u.suspended = 0',
            // Guest and the admin placeholder are never learners.
            'u.id > 2',
            // No completion row, or one that was never finished.
            '(cc.timecompleted IS NULL OR cc.timecompleted = 0)',
            'e.status = 0',
            'ue.status = 0',
        ];

        if ($filter->courseid !== null) {
            $where[] = 'c.id = :courseid';
            $params['courseid'] = $filter->courseid;
        }

        [$profilesql, $profileparams] = self::profile_conditions($filter);
        $where = array_merge($where, $profilesql);
        $params = array_merge($params, $profileparams);

        /*
         * Every name field, not just first and last.
         *
         * fullname() honours the site's fullnamedisplay setting, which can
         * name middlename, alternatename or the phonetic fields. Handed a
         * record without them it raises a debugging warning and falls back,
         * so on a site that uses any of those the drill-through would quietly
         * render the wrong name for everybody. Invisible in production, where
         * debugging is off, which is exactly why it needs pinning.
         */
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $sql = "SELECT " . $DB->sql_concat('u.id', "'-'", 'c.id') . " AS rowkey,
                       u.id AS userid, u.email, {$namefields},
                       c.id AS courseid, c.fullname AS coursename,
                       ue.timestart, ue.timecreated, cc.timestarted
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = c.id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY u.lastname, u.firstname, c.fullname";

        $rows = [];

        foreach ($DB->get_records_sql($sql, $params, 0, $limit) as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'fullname' => fullname($record),
                'email' => $record->email,
                'courseid' => (int) $record->courseid,
                'coursename' => $record->coursename,
                'enrolled_at' => (int) ($record->timestart ?: $record->timecreated),
                'status' => empty($record->timestarted) ? 'notstarted' : 'inprogress',
            ];
        }

        return $rows;
    }

    /**
     * WHERE fragments restricting to the filter's sector, department and function.
     *
     * A department node carries its sector, because that is what makes it
     * identifiable: "HR" under Transport and "HR" under Office are two
     * different departments and the code the dashboard sent means exactly one
     * of them.
     *
     * @param filter $filter
     *
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    protected static function profile_conditions(filter $filter): array {
        global $DB;

        $map = org::fieldmap();
        $conditions = [];
        $params = [];

        $levels = [
            'sector' => $filter->sector,
            'department' => $filter->department,
            'function' => $filter->function,
        ];

        foreach ($levels as $level => $value) {
            if ($value === null) {
                continue;
            }

            // One EXISTS per level rather than a join per level: a join would
            // multiply the enrolment rows by the number of matching field rows
            // and silently inflate every count on the page.
            $conditions[] = "EXISTS (
                SELECT 1
                  FROM {user_info_data} d_{$level}
                  JOIN {user_info_field} f_{$level} ON f_{$level}.id = d_{$level}.fieldid
                 WHERE d_{$level}.userid = u.id
                   AND f_{$level}.shortname = :field_{$level}
                   AND " . $DB->sql_compare_text("d_{$level}.data", 255) . ' = '
                        . $DB->sql_compare_text(":value_{$level}", 255) . '
            )';

            $params["field_{$level}"] = $map[$level];
            $params["value_{$level}"] = $value;
        }

        return [$conditions, $params];
    }
}
