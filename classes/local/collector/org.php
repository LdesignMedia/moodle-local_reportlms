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
 * Collector for the org section of the push payload.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local\collector;

/**
 * The org section: the company tree, memberships and learner attributes.
 *
 * Unlike the fact collectors this is always full state, never a batch: the
 * contract's tree consistency model is last-snapshot-wins, and a partial
 * tree cannot be merged safely. When the state hash is unchanged since the
 * last accepted push, the batch builder omits the section entirely.
 *
 * Node identity is the value's path, not the bare value. "HR under
 * Transport" and "HR under Office" are two different departments, so a
 * department node's key includes its sector. A rename therefore produces a
 * new node and the members move to it, which is the behaviour the plan
 * specifies.
 *
 * People without a mapped value land in a visible "Unassigned" node rather
 * than disappearing, so HR can spot data gaps.
 *
 * Managers are empty on purpose: how a site defines who manages which node
 * is the open design of the org and permissions task, and the dashboard is
 * admin-only until that lands.
 */
class org {
    /** @var string Node key of the unassigned bucket. */
    public const UNASSIGNED = 'department:Unassigned';

    /**
     * The profile field shortnames that drive the tree.
     *
     * Admin-configurable later, alongside the mapping preview. The defaults
     * are the dedicated fields the plugin will create at install.
     *
     * @return array{sector: string, department: string, function: string}
     */
    public static function fieldmap(): array {
        return [
            'sector' => get_config('local_reportlms', 'orgfield_sector') ?: 'rlms_sector',
            'department' => get_config('local_reportlms', 'orgfield_department') ?: 'rlms_department',
            'function' => get_config('local_reportlms', 'orgfield_function') ?: 'rlms_function',
        ];
    }

    /**
     * The full org state.
     *
     * @return \stdClass {nodes, members, managers, attrs}:
     *         nodes    key => {key, parent (key|null), kind}
     *         members  list of {userid, nodekey}
     *         managers empty for now
     *         attrs    list of {userid, auth, active, function (value|null)}
     */
    public static function collect(): \stdClass {
        global $DB;

        $map = self::fieldmap();
        [$fieldsql, $fieldparams] = $DB->get_in_or_equal(array_values($map), SQL_PARAMS_NAMED);

        // One pass: every real user with their auth, suspension state and
        // any of the three mapped field values.
        $sql = "SELECT u.id, u.auth, u.suspended, f.shortname, d.data
                  FROM {user} u
             LEFT JOIN {user_info_data} d ON d.userid = u.id
             LEFT JOIN {user_info_field} f ON f.id = d.fieldid AND f.shortname {$fieldsql}
                 WHERE u.deleted = 0 AND u.id > 2
              ORDER BY u.id";

        $users = [];
        foreach ($DB->get_recordset_sql($sql, $fieldparams) as $row) {
            $uid = (int) $row->id;
            if (!isset($users[$uid])) {
                $users[$uid] = (object) [
                    'auth' => $row->auth, 'suspended' => (int) $row->suspended,
                    'sector' => null, 'department' => null, 'function' => null,
                ];
            }
            foreach ($map as $level => $shortname) {
                if ($row->shortname === $shortname && trim((string) $row->data) !== '') {
                    $users[$uid]->{$level} = trim($row->data);
                }
            }
        }

        $nodes = [];
        $members = [];
        $attrs = [];

        $nodes[self::UNASSIGNED] = (object) ['key' => self::UNASSIGNED, 'parent' => null, 'kind' => 'department'];

        foreach ($users as $uid => $u) {
            $sectorkey = $u->sector !== null ? 'sector:' . $u->sector : null;
            if ($sectorkey !== null && !isset($nodes[$sectorkey])) {
                $nodes[$sectorkey] = (object) ['key' => $sectorkey, 'parent' => null, 'kind' => 'sector'];
            }

            // A department's identity includes its sector: same label under
            // two sectors is two nodes. A department without a sector hangs
            // off the root.
            $deptkey = null;
            if ($u->department !== null) {
                $deptkey = 'department:' . ($u->sector !== null ? $u->sector . '/' : '') . $u->department;
                if (!isset($nodes[$deptkey])) {
                    $nodes[$deptkey] = (object) ['key' => $deptkey, 'parent' => $sectorkey, 'kind' => 'department'];
                }
            }

            // Membership: the most specific node the person maps to.
            $members[] = (object) ['userid' => $uid, 'nodekey' => $deptkey ?? $sectorkey ?? self::UNASSIGNED];

            $attrs[] = (object) [
                'userid' => $uid,
                'auth' => $u->auth,
                'active' => $u->suspended == 0,
                'function' => $u->function,
            ];
        }

        ksort($nodes);

        return (object) [
            'nodes' => array_values($nodes),
            'members' => $members,
            'managers' => [],
            'attrs' => $attrs,
        ];
    }

    /**
     * Deterministic fingerprint of the org state.
     *
     * The SaaS treats the hash as opaque and only compares it to the last
     * accepted one, so it is computed over the canonical raw structure. Same
     * org, same hash, on every run.
     *
     * @param \stdClass $org As returned by collect().
     *
     * @return string 40 hex chars.
     */
    public static function state_hash(\stdClass $org): string {
        return sha1(json_encode($org));
    }
}
