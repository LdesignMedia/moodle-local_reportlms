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
 * The registry of learners whose data has changed.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Tracks who changed, so a push carries only them.
 *
 * At 100k learners, sending everyone every fifteen minutes is almost entirely
 * wasted: most of it is identical to what the SaaS already holds.
 *
 * A flag rather than a "timechanged > timepushed" comparison on purpose. A
 * column-to-column comparison cannot use an index, so the selection query would
 * table-scan exactly on the sites where it matters most.
 */
class userstate {
    /** @var string Table holding one row per tracked learner. */
    public const TABLE = 'local_reportlms_userstate';

    /**
     * The lowest id that can belong to a learner.
     *
     * Every Moodle reserves 1 for the guest and 2 for the primary admin.
     * Neither is a person being trained, so neither belongs in a report.
     * The rule lives here once and is applied by every path that fills the
     * registry: the bulk backfill, the nightly sweep and the event
     * observers. Applied on only some of them, an admin who happens to be
     * enrolled somewhere quietly becomes a reported employee.
     */
    public const FIRST_REPORTABLE_USERID = 3;

    /**
     * Note that a learner changed.
     *
     * Cheap by design: one indexed upsert, called from event observers on the
     * request path.
     *
     * @param int $userid
     */
    public static function mark_dirty(int $userid): void {
        global $DB;

        if ($userid < self::FIRST_REPORTABLE_USERID || \core_user::is_real_user($userid) === false) {
            return;
        }

        $now = time();
        $existing = $DB->get_field(self::TABLE, 'id', ['userid' => $userid]);

        if ($existing) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $existing,
                'dirty' => 1,
                'timechanged' => $now,
            ]);

            return;
        }

        try {
            $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'dirty' => 1,
                'timechanged' => $now,
                'timepushed' => 0,
            ]);
        } catch (\dml_exception $e) {
            // Two requests raced to first-touch the same learner. The other one
            // won and the row now exists, which is the outcome we wanted.
            $DB->set_field(self::TABLE, 'dirty', 1, ['userid' => $userid]);
        }
    }

    /**
     * Mark every reportable learner dirty.
     *
     * Needed for the first push after connecting, after a contract change, and
     * to recover from any incident where the registry is not trusted.
     *
     * @return int Learners marked.
     */
    public static function mark_all_dirty(): int {
        global $DB;

        // Existing rows first, then everyone missing a row.
        $DB->set_field(self::TABLE, 'dirty', 1, []);
        $DB->set_field(self::TABLE, 'timechanged', time(), []);

        /*
         * Deleted users are not backfilled, and that is deliberate.
         *
         * The removal-semantics plan called for this filter to go, so that
         * departures could be reported. It only needs to go for people the SaaS
         * has facts about, and this query is the opposite case: it adds rows for
         * users who have never been tracked. Somebody deleted before the plugin
         * was installed has no enrolments, no completions and no history on the
         * far side, so marking them departed would create a learner who appears
         * in no course, no department and no figure — noise standing in for
         * information.
         *
         * Deletions that matter are learned from the user_deleted event while
         * the plugin is running, and those users already have a row here, which
         * the statement above marks dirty like any other.
         */
        $sql = 'INSERT INTO {' . self::TABLE . '} (userid, dirty, timechanged, timepushed)
                SELECT u.id, 1, ?, 0
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.id >= ' . self::FIRST_REPORTABLE_USERID . '
                   AND NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} s WHERE s.userid = u.id)';
        // INSERT ... SELECT has no DML-API equivalent; every value is a placeholder or a class constant.
        $DB->execute($sql, [time()]);

        return $DB->count_records(self::TABLE, ['dirty' => 1]);
    }

    /**
     * The next learners to send.
     *
     * Ordered by id so chunking is stable across runs and a stuck learner does
     * not silently block the rest.
     *
     * @param int $limit
     * @return array<int, int> User ids.
     */
    public static function get_dirty_batch(int $limit): array {
        global $DB;

        $rows = $DB->get_records_select(self::TABLE, 'dirty = 1', [], 'userid ASC', 'userid', 0, $limit);

        return array_map('intval', array_keys($rows));
    }

    /**
     * Count of learners waiting to be sent.
     *
     * @return int
     */
    public static function count_dirty(): int {
        global $DB;

        return $DB->count_records(self::TABLE, ['dirty' => 1]);
    }

    /**
     * Record that the SaaS accepted these learners.
     *
     * Only called after a 202. A failed or partial push leaves the flag set, so
     * the next run retries exactly those learners rather than losing them, and
     * that is why the flag is per learner instead of one global watermark.
     *
     * @param array<int, int> $userids
     * @param array<int, string> $hashes Optional userid => slice hash.
     * @param int|null $readat When the batch was read. A learner changed after
     *                         that moment stays dirty, because what was sent
     *                         predates the change. Null clears regardless.
     */
    public static function clear(array $userids, array $hashes = [], ?int $readat = null): void {
        global $DB;

        if (!$userids) {
            return;
        }

        $now = time();

        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $unchanged = '';

            if ($readat !== null) {
                $unchanged = ' AND timechanged <= ?';
                $params[] = $readat;
            }

            // Two columns in one statement, guarded by the read timestamp; set_field_select() sets one column only.
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET dirty = 0, timepushed = ? WHERE userid ' . $insql . $unchanged,
                array_merge([$now], $params),
            );
        }

        foreach ($hashes as $userid => $hash) {
            $DB->set_field(self::TABLE, 'contenthash', $hash, ['userid' => $userid]);
        }
    }

    /**
     * Flag the given users as changed, adding a row for anybody not tracked yet.
     *
     * Set-based per chunk: the rows that exist are flagged, then a dirty row is
     * inserted for each user who has none.
     *
     * @param int[] $userids Real, non-deleted users; the caller has already filtered.
     *
     * @return void
     */
    public static function flag_dirty(array $userids): void {
        global $DB;

        $now = time();

        foreach (array_chunk(array_values(array_unique(array_map('intval', $userids))), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);

            $DB->set_field_select(self::TABLE, 'dirty', 1, 'userid ' . $insql, $params);
            $DB->set_field_select(self::TABLE, 'timechanged', $now, 'userid ' . $insql, $params);

            $tracked = $DB->get_fieldset_select(self::TABLE, 'userid', 'userid ' . $insql, $params);
            $rows = [];

            foreach (array_diff($chunk, array_map('intval', $tracked)) as $userid) {
                $rows[] = (object) ['userid' => $userid, 'dirty' => 1, 'timechanged' => $now, 'timepushed' => 0];
            }

            if ($rows) {
                $DB->insert_records(self::TABLE, $rows);
            }
        }
    }

    /**
     * Mark tracking rows whose user is gone from the user table as dirty.
     *
     * The next push then sends them as deleted, once, and drops them (see
     * purge_orphans). One LEFT JOIN selects the orphans in batches, so a large
     * site costs a few indexed queries rather than a lookup per row. Rows
     * already dirty are skipped, which is also what lets the loop finish.
     *
     * @param int $batchsize Rows handled per query.
     *
     * @return int Rows newly marked.
     */
    public static function mark_orphans_dirty(int $batchsize = 1000): int {
        global $DB;

        $marked = 0;
        $now = time();

        do {
            $sql = 'SELECT s.userid
                      FROM {' . self::TABLE . '} s
                 LEFT JOIN {user} u ON u.id = s.userid
                     WHERE u.id IS NULL
                       AND s.dirty = 0
                  ORDER BY s.userid ASC';
            $userids = array_map('intval', array_keys($DB->get_records_sql($sql, [], 0, $batchsize)));

            if (!$userids) {
                break;
            }

            [$insql, $params] = $DB->get_in_or_equal($userids);
            // Two columns in one statement; set_field_select() sets one column only.
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET dirty = 1, timechanged = ? WHERE userid ' . $insql,
                array_merge([$now], $params),
            );
            $marked += count($userids);
        } while (count($userids) === $batchsize);

        return $marked;
    }

    /**
     * Drop the tracking rows of these users when the user no longer exists.
     *
     * Called after a push that carried them as deleted was accepted. A user who
     * still has a row in the user table, deleted or not, is left alone: that
     * row stays so a later change can still be tracked.
     *
     * @param array<int, int> $userids
     */
    public static function purge_orphans(array $userids): void {
        global $DB;

        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $DB->delete_records_select(
                self::TABLE,
                'userid ' . $insql . '
                         AND NOT EXISTS (SELECT 1 FROM {user} u WHERE u.id = {' . self::TABLE . '}.userid)',
                $params
            );
        }
    }

    /**
     * The hash of the slice last accepted for a learner.
     *
     * Lets the sender skip a learner whose data is byte-identical to what the
     * SaaS already holds, which stops a noisy event causing pointless traffic.
     *
     * @param array<int, int> $userids
     * @return array<int, string> userid => hash
     */
    public static function content_hashes(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids);

        return $DB->get_records_sql_menu(
            'SELECT userid, contenthash FROM {' . self::TABLE . '} WHERE contenthash IS NOT NULL AND userid ' . $insql,
            $params,
        );
    }
}
