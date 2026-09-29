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

use local_reportlms\local\mapping;

/**
 * The org section: the company tree, memberships and learner attributes.
 *
 * Unlike the fact collectors this is always full state, never a batch: the
 * contract's tree consistency model is last-snapshot-wins, and a partial
 * tree cannot be merged safely. When the state hash is unchanged since the
 * last accepted push, the batch builder omits the section entirely.
 *
 * The tree is stated, never inferred. Every unit, every membership and every
 * manager is a row somebody wrote on the mapping page, and this reads them.
 *
 * It used to work the tree out instead, from two profile fields and from who
 * held local/reportlms:view on which course. Both are guesses about something
 * only a person knows, and on connected sites both were wrong in ways no amount
 * of better inference fixes: a blank profile field is indistinguishable from a
 * wrong one, a manager of two departments placed all their people in one, and
 * being enrolled on an all-staff course made one manager look responsible for
 * 1,467 of a site's 1,495 learners.
 *
 * Node identity is the unit's path, not its name. "HR" under "Transport" and
 * "HR" under "Office" are two different departments, so a department node's key
 * carries its sector. A rename therefore produces a new node and the members
 * move to it.
 *
 * Anybody nobody has placed lands in a visible "Unassigned" node rather than
 * disappearing, so they stay in the site's totals and the gap is something
 * somebody can see and clear.
 *
 * The state hash covers managers too, because it is computed over everything
 * returned here.
 */
class org {
    /** @var string Node key of the unassigned bucket. */
    public const UNASSIGNED = 'department:Unassigned';

    /**
     * The profile field naming what somebody does.
     *
     * The last profile field this plugin reads. Function is an attribute of a
     * person rather than a place in the tree: two people with the same job work
     * in different departments, and the aggregation slices by it without the
     * organisation depending on it. So it stays a field, where sector and
     * department became rows.
     *
     * @return string
     */
    public static function function_field(): string {
        return get_config('local_reportlms', 'orgfield_function') ?: 'rlms_function';
    }

    /**
     * The full org state.
     *
     * @return \stdClass {nodes, members, managers, attrs}:
     *         nodes    list of {key, parent (key|null), kind}
     *         members  list of {userid, nodekey}
     *         managers list of {userid, nodekey}
     *         attrs    list of {userid, auth, active, function (value|null)}
     */
    public static function collect(): \stdClass {
        global $DB;

        $nodes = [
            self::UNASSIGNED => (object) [
                'key' => self::UNASSIGNED,
                'parent' => null,
                'kind' => 'department',
            ],
        ];

        /*
         * Every stated unit, including the empty ones.
         *
         * A unit used to reach the wire only by having a member, because a unit
         * was a profile value and a value nobody holds does not exist. A unit is
         * now a row somebody created deliberately, usually just before they
         * start filling it, and a department that vanishes from the report
         * between being created and being populated reads as the editor having
         * failed to save it.
         */
        $stated = mapping::nodes();

        foreach ($stated as $node) {
            $key = mapping::nodekey((int) $node->id);

            if ($key === null) {
                continue;
            }

            $parent = null;

            if ($node->parentid !== null && isset($stated[$node->parentid])) {
                $parent = mapping::nodekey((int) $node->parentid);
            }

            $nodes[$key] = (object) ['key' => $key, 'parent' => $parent, 'kind' => $node->kind];
        }

        /*
         * Resolved once for the whole site rather than per user.
         *
         * The sources a unit draws from are read and turned into people here,
         * not stored as membership, so somebody enrolled on a course a unit
         * draws from is in that unit on the next push without anybody having
         * touched the editor.
         */
        $placed = mapping::resolved_membership();

        /*
         * The function field, by id rather than by shortname in the join.
         *
         * Looked up separately so a site without the field runs the same single
         * pass as one with it, instead of a query whose shape depends on
         * whether a row exists.
         */
        $fieldid = (int) $DB->get_field(
            'user_info_field',
            'id',
            ['shortname' => self::function_field()],
            IGNORE_MULTIPLE
        );

        $sql = "SELECT u.id, u.auth, u.suspended, d.data AS functionvalue
                  FROM {user} u
             LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid
                 WHERE u.deleted = 0 AND u.id > 2
              ORDER BY u.id";

        $members = [];
        $attrs = [];

        $recordset = $DB->get_recordset_sql($sql, ['fieldid' => $fieldid]);

        foreach ($recordset as $row) {
            $uid = (int) $row->id;

            /*
             * One row per unit the person is in.
             *
             * Keyed by node key, because two units can share one: the key is
             * built from names, and a site can hold the same name twice under
             * the same parent from before names were checked. The same pair
             * twice would be one fact sent twice. Sorted, so the same
             * organisation always hashes the same.
             */
            $nodekeys = [];

            foreach ($placed[$uid] ?? [] as $nodeid) {
                // A placement pointing at a unit that has since been deleted
                // places nobody; it is skipped rather than an error.
                $nodekey = mapping::nodekey((int) $nodeid);

                if ($nodekey !== null) {
                    $nodekeys[$nodekey] = $nodekey;
                }
            }

            // Nowhere is Unassigned, so the report still counts them.
            if (!$nodekeys) {
                $nodekeys[self::UNASSIGNED] = self::UNASSIGNED;
            }

            ksort($nodekeys);

            foreach ($nodekeys as $nodekey) {
                $members[] = (object) ['userid' => $uid, 'nodekey' => $nodekey];
            }

            $functionvalue = trim((string) $row->functionvalue);

            $attrs[] = (object) [
                'userid' => $uid,
                'auth' => $row->auth,
                'active' => $row->suspended == 0,
                'function' => $functionvalue === '' ? null : $functionvalue,
            ];
        }

        $recordset->close();

        /*
         * Managers, as named on the mapping page and nowhere else.
         *
         * This list is what stops a manager being counted among the people of
         * the unit they run. Holding local/reportlms:view is not on it: that
         * capability says what somebody may look at, and running a department
         * is a fact about the organisation. A site can grant the one without
         * asserting the other, and most do.
         *
         * Role rows are skipped. A role names a set of people that changes on
         * its own, and the wire carries people.
         */
        $managers = [];
        $seen = [];

        foreach (mapping::managers() as $named) {
            if (!$named->userid) {
                continue;
            }

            $namedkey = mapping::nodekey((int) $named->nodeid);

            if ($namedkey === null) {
                continue;
            }

            $key = (int) $named->userid . '@' . $namedkey;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $managers[] = (object) [
                'userid' => (int) $named->userid,
                'nodekey' => $namedkey,
            ];
        }

        ksort($nodes);

        return (object) [
            'nodes' => array_values($nodes),
            'members' => $members,
            'managers' => $managers,
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
