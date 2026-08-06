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
 * The certificates block.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local\collector;

/**
 * Certificates a learner holds, from whichever certificate plugin the site runs.
 *
 * Two stacks, because our customers run both:
 *
 *   Moodle Workplace   tool_certificate, issued through mod_coursecertificate.
 *                      Carries a real expiry date.
 *   Moodle LMS         mod_customcert. Has no expiry concept at all, so every
 *                      row it produces expires_at is null, and the dashboard
 *                      counts those as valid forever. That is the plugin's
 *                      model, not a gap in ours.
 *
 * A site may have neither, one, or in principle both. Each source is skipped
 * when its table is absent, so this runs unchanged on all of them.
 *
 * Nothing but ids and dates is read. tool_certificate_issues carries a `data`
 * column holding the learner's full name and the course title, and none of that
 * is touched: the whole point of the wire format is that Report LMS receives a
 * coded learner and a coded course and cannot turn either back into a person.
 */
class certificates {

    /**
     * Certificates held by a batch of learners.
     *
     * One row per learner per course. Where a learner holds several
     * certificates for the same course, the most recent issue wins: the
     * dashboard asks "is this person certified for this training", and two
     * answers to that question would be counted twice.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, \stdClass> Rows: userid, courseid, issuedat (int),
     *         expiresat (int|null, null meaning it does not expire).
     */
    public static function collect(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        $rows = [];

        foreach ([self::workplace($userids), self::customcert($userids)] as $source) {
            foreach ($source as $row) {
                $key = $row->userid . ':' . $row->courseid;

                // Most recent issue wins. A reissue after a renewal is the
                // answer; the one it replaced is history.
                if (isset($rows[$key]) && $rows[$key]->issuedat >= $row->issuedat) {
                    continue;
                }

                $rows[$key] = $row;
            }
        }

        return array_values($rows);
    }

    /**
     * Moodle Workplace: tool_certificate.
     *
     * Archived issues are skipped. Workplace archives an issue when the
     * template or the course is retired, and an archived certificate is not one
     * the learner can show anybody.
     *
     * @param array<int, int> $userids
     *
     * @return array<int, \stdClass>
     */
    protected static function workplace(array $userids): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // courseid is nullable on the table: an issue can be granted outside a
        // course entirely, through a certification or by hand. Those are real
        // certificates but they belong to no training, and the dashboard counts
        // per course, so they are left out rather than attached to a guess.
        $sql = "SELECT i.id, i.userid, i.courseid, i.timecreated, i.expires
                  FROM {tool_certificate_issues} i
                  JOIN {user} u ON u.id = i.userid
                 WHERE u.deleted = 0
                   AND i.archived = 0
                   AND i.courseid IS NOT NULL
                   AND i.courseid > 1
                   AND i.userid {$insql}
              ORDER BY i.userid, i.courseid, i.timecreated";

        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);

        foreach ($recordset as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'issuedat' => (int) $record->timecreated,
                // Workplace stores "never expires" as 0, which is not a date.
                'expiresat' => (int) $record->expires > 0 ? (int) $record->expires : null,
            ];
        }

        $recordset->close();

        return $rows;
    }

    /**
     * Moodle LMS: mod_customcert.
     *
     * The issue table names the activity, not the course, so the course comes
     * through the customcert instance. There is no expiry: customcert has no
     * such field, so every row here reports null and stays valid.
     *
     * @param array<int, int> $userids
     *
     * @return array<int, \stdClass>
     */
    protected static function customcert(array $userids): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('customcert_issues')) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $sql = "SELECT i.id, i.userid, c.course AS courseid, i.timecreated
                  FROM {customcert_issues} i
                  JOIN {customcert} c ON c.id = i.customcertid
                  JOIN {user} u ON u.id = i.userid
                 WHERE u.deleted = 0
                   AND c.course > 1
                   AND i.userid {$insql}
              ORDER BY i.userid, c.course, i.timecreated";

        // A recordset, never get_records_sql: that helper keys rows by the
        // first column, and a learner holding certificates in several courses
        // would lose all but one of them.
        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);

        foreach ($recordset as $record) {
            $rows[] = (object) [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'issuedat' => (int) $record->timecreated,
                'expiresat' => null,
            ];
        }

        $recordset->close();

        return $rows;
    }
}
