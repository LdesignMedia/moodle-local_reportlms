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
     * Note that a learner changed.
     *
     * Cheap by design: one indexed upsert, called from event observers on the
     * request path.
     *
     * @param int $userid
     */
    public static function mark_dirty(int $userid): void {
        global $DB;

        if ($userid <= 0 || \core_user::is_real_user($userid) === false) {
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
        $DB->execute('UPDATE {' . self::TABLE . '} SET timechanged = ?', [time()]);

        $sql = 'INSERT INTO {' . self::TABLE . '} (userid, dirty, timechanged, timepushed)
                SELECT u.id, 1, ?, 0
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.id > 2
                   AND NOT EXISTS (SELECT 1 FROM {' . self::TABLE . '} s WHERE s.userid = u.id)';
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
     */
    public static function clear(array $userids, array $hashes = []): void {
        global $DB;

        if (!$userids) {
            return;
        }

        $now = time();

        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET dirty = 0, timepushed = ? WHERE userid ' . $insql,
                array_merge([$now], $params),
            );
        }

        foreach ($hashes as $userid => $hash) {
            $DB->set_field(self::TABLE, 'contenthash', $hash, ['userid' => $userid]);
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
