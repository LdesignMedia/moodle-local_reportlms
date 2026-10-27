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
 * Marking whoever changed in a source table since it was last looked at.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * One source table, swept against its own watermark.
 *
 * The nightly reconcile task sweeps every source this way. The push sweeps the
 * certificate tables this way too, on every run, because a certificate is the
 * one change nothing else reliably notices in time: it is often issued a few
 * seconds after the completion it rewards, so the push that sends the
 * completion goes out without it, and nothing marks the learner again. Waiting
 * for the night left it out of the report for up to a day.
 */
class source_sweep {
    /**
     * How far behind the clock a certificate sweep stops, in seconds.
     *
     * A certificate is only looked for once it is this old, so one saved a
     * moment after its time was written, by a request or a cron runner still
     * finishing, is in the table by the time its window is read. It also
     * absorbs a little clock difference between web servers. The cost is that
     * a certificate waits up to this long on top of the push interval.
     */
    public const CERTIFICATE_LAG = 120;

    /**
     * The certificate issue tables installed on this site.
     *
     * mod_customcert is the Moodle LMS side and tool_certificate the Workplace
     * side. Either may be missing, so each is only listed when its table
     * exists: an unguarded read of a table that is not installed would throw.
     *
     * Swept rather than observed, even where an event exists. tool_certificate
     * raises certificate_issued and is observed as well (see db/events.php).
     * mod_customcert raises \mod_customcert\event\issue_created only from 4.4.6
     * and 5.0.0 (June 2025); older releases raise nothing when they issue one.
     * The table covers every release of both. On the releases that raise it,
     * an observer would get a certificate out up to one push sooner, since the
     * window waits two minutes; it is left out so that a certificate has one
     * way into the report rather than two that could disagree.
     *
     * Each is swept in closed windows (see {@see sweep()}), never with an
     * overlap: the push sweeps them four times an hour, and an overlap would
     * send the newest certificate's learner again on every run.
     *
     * On a site whose watermark was never set, the first sweep marks every
     * certificate holder once. That is deliberate: it is what sends the
     * certificates a site missed while nothing looked at these tables, and
     * re-sending somebody is an upsert, never a duplicate.
     *
     * @return array<string, array{table: string, column: string, userfield: string, lag: int}>
     */
    public static function certificate_sources(): array {
        global $DB;

        $manager = $DB->get_manager();
        $sources = [];

        $certificates = [
            'customcertissues' => ['table' => 'customcert_issues', 'column' => 'timecreated', 'userfield' => 'userid'],
            'toolcertissues' => ['table' => 'tool_certificate_issues', 'column' => 'timecreated', 'userfield' => 'userid'],
        ];

        foreach ($certificates as $key => $source) {
            if ($manager->table_exists($source['table'])) {
                $sources[$key] = $source + ['lag' => self::CERTIFICATE_LAG];
            }
        }

        return $sources;
    }

    /**
     * Mark dirty everybody with a row in this source newer than its watermark.
     *
     * Two ways of reading a source:
     *
     * - With a lag, a closed window: rows after the watermark and no newer
     *   than the lag allows, after which the watermark moves to the window's
     *   end. Every row falls in one window, so it is marked once (bar a few
     *   seconds read twice when both tasks read a source at once), and a row
     *   saved late is still found, provided it is saved within the lag. A row
     *   dated in the future waits for its window rather than dragging the
     *   watermark past rows that are not saved yet.
     * - Without, the nightly sweep's way: rows newer than the watermark less a
     *   minute of overlap, after which the watermark moves to the newest row
     *   seen. The overlap catches rows written in the same second as the last
     *   look, at the price of looking at that minute again each night.
     *
     * A source may add a join and a further condition, both written against
     * the source table as "s", and a longer overlap than the default minute.
     *
     * @param string $key The source's name, which names its watermark.
     * @param array{table: string, column: string, userfield: string, lag?: int, overlap?: int, join?: string,
     *     where?: string} $source
     *
     * @return int How many learner rows were marked.
     */
    public static function sweep(string $key, array $source): int {
        global $DB;

        $watermark = (int) get_config('local_reportlms', 'watermark_' . $key);
        $params = [];
        $until = null;

        if (isset($source['lag'])) {
            $until = time() - $source['lag'];

            // A watermark past the clock was left by the nightly sweep's old
            // way of reading these tables, from a certificate dated in the
            // future, and would hide every certificate until that date. Read
            // back a day instead: a day of certificates sent again is cheaper
            // than none sent until then.
            if ($watermark > time()) {
                $watermark = max(0, $until - DAYSECS);
            }

            $params['from'] = $watermark;
            $params['until'] = $until;
            $window = "AND s.{$source['column']} <= :until";
        } else {
            // A source can ask for more overlap; see reconcile_userstate's
            // courseaccess.
            $params['from'] = max(0, $watermark - ($source['overlap'] ?? 60));
            $window = '';
        }

        $highest = $watermark;
        $marked = 0;

        // Joined to user rather than read straight from the source table:
        // grade and enrolment rows outlive the account, so a plain sweep marks
        // deleted learners dirty and the next push carries people who must not
        // be reported on at all.
        $join = $source['join'] ?? '';
        $where = $source['where'] ?? '';
        $sql = "SELECT s.{$source['userfield']} AS userid, MAX(s.{$source['column']}) AS changed
                  FROM {{$source['table']}} s
                  JOIN {user} u ON u.id = s.{$source['userfield']}
                       {$join}
                 WHERE s.{$source['column']} > :from
                       {$window}
                   AND u.deleted = 0
                   AND u.id >= " . userstate::FIRST_REPORTABLE_USERID . "
                       {$where}
              GROUP BY s.{$source['userfield']}";

        $recordset = $DB->get_recordset_sql($sql, $params);

        foreach ($recordset as $row) {
            userstate::mark_dirty((int) $row->userid);
            $marked++;
            $highest = max($highest, (int) $row->changed);
        }

        $recordset->close();

        // Only advance once the sweep finished. A crash mid-source repeats it
        // next time, which is wasteful but never loses a change.
        //
        // A windowed source can step back a few seconds when the push and the
        // nightly sweep read it at once and the earlier window is written last.
        // That only means reading those seconds twice.
        set_config('watermark_' . $key, $until === null ? $highest : max($watermark, $until), 'local_reportlms');

        return $marked;
    }
}
