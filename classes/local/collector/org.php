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
 * Node identity is the value's path, not the bare value. "HR under
 * Transport" and "HR under Office" are two different departments, so a
 * department node's key includes its sector. A rename therefore produces a
 * new node and the members move to it, which is the behaviour the plan
 * specifies.
 *
 * People without a mapped value land in a visible "Unassigned" node rather
 * than disappearing, so HR can spot data gaps.
 *
 * Managers come from the local/reportlms:manage capability rather than from a
 * profile field: see {@see \local_reportlms\local\manager_resolver}. A managed
 * participant takes their manager's department, overriding whatever they have
 * set on themselves, so one person being responsible for a group is enough to
 * place the whole group in the tree.
 *
 * The state hash covers managers too, because it is computed over everything
 * returned here. A role assignment or an enrolment change therefore moves the
 * hash and gets pushed, which it would not if managers were computed later.
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
     * Lock the mapped department field, so a learner cannot edit their own.
     *
     * Called on every push rather than once at upgrade, because the mapping is
     * a config value an administrator changes long after installing: locking
     * only at upgrade would lock whatever the mapping happened to point at that
     * day, which is usually nothing.
     *
     * Moodle then allows only moodle/user:update holders to change it. The lock
     * affects the edit form alone, so an HR upload or a web service sync keeps
     * writing normally.
     *
     * @return void
     */
    public static function lock_department_field(): void {
        global $DB;

        $shortname = self::fieldmap()['department'];
        $ourlock = (string) get_config('local_reportlms', 'lockedfield');

        // The mapping moved away from a field this plugin locked: undo that
        // lock. Only ours — a lock the customer set themselves is recorded
        // nowhere and is therefore never touched.
        if ($ourlock !== '' && $ourlock !== $shortname) {
            $old = $DB->get_record('user_info_field', ['shortname' => $ourlock], 'id, locked');
            if ($old && !empty($old->locked)) {
                $DB->set_field('user_info_field', 'locked', 0, ['id' => $old->id]);
            }
            unset_config('lockedfield', 'local_reportlms');
        }

        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id, locked');

        if ($field && empty($field->locked)) {
            $DB->set_field('user_info_field', 'locked', 1, ['id' => $field->id]);
            // Recorded so a later re-map (and db/uninstall.php) can undo
            // exactly what was done, and nothing else.
            set_config('lockedfield', $shortname, 'local_reportlms');
        }
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
    /**
     * Declare a hand-built node, and the sector above it if there is one.
     *
     * @param string $key As {@see mapping::nodekey} produced it.
     * @param array<string, \stdClass> $nodes Tree so far, added to by reference.
     *
     * @return \stdClass
     */
    protected static function manual_node(string $key, array &$nodes): \stdClass {
        if (strpos($key, 'sector:') === 0) {
            return (object) ['key' => $key, 'parent' => null, 'kind' => 'sector'];
        }

        $value = substr($key, strlen('department:'));
        $slash = strpos($value, '/');

        if ($slash === false) {
            return (object) ['key' => $key, 'parent' => null, 'kind' => 'department'];
        }

        $sectorkey = 'sector:' . substr($value, 0, $slash);

        if (!isset($nodes[$sectorkey])) {
            $nodes[$sectorkey] = (object) ['key' => $sectorkey, 'parent' => null, 'kind' => 'sector'];
        }

        return (object) ['key' => $key, 'parent' => $sectorkey, 'kind' => 'department'];
    }

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

        // Read once: the loop asks about every user on the site.
        $manual = mapping::membership();

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

        // Who manages whom, from the capability and course enrolment. A managed
        // participant takes their manager's department, whatever they have set
        // on themselves: one person is responsible for the group, and the group
        // inherits their place in the tree.
        $managerids = \local_reportlms\local\manager_resolver::managers();
        $managed = \local_reportlms\local\manager_resolver::membership();

        foreach ($managed as $uid => $inherited) {
            if (isset($users[$uid])) {
                // Both levels, or the key names a department under a sector it
                // does not sit in, and no label ever resolves for it.
                $users[$uid]->sector = $inherited->sector;
                $users[$uid]->department = $inherited->department;
            }
        }

        $nodes = [];
        $members = [];
        $managers = [];
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

            /*
             * Membership, in order of authority.
             *
             * A row somebody typed in the mapping editor beats everything: it
             * is a statement of fact about the organisation, where a profile
             * field is a guess derived from an HR export. Then the manager's
             * department, then the person's own, then Unassigned.
             *
             * The manual node is added to the tree here rather than assumed to
             * exist, because a hand-built unit with one member and no profile
             * field anywhere would otherwise be a membership pointing at a node
             * nobody declared.
             */
            $manualkey = isset($manual[$uid]) ? mapping::nodekey((int) $manual[$uid]) : null;

            if ($manualkey !== null && !isset($nodes[$manualkey])) {
                $nodes[$manualkey] = self::manual_node($manualkey, $nodes);
            }

            $nodekey = $manualkey ?? $deptkey ?? $sectorkey ?? self::UNASSIGNED;
            $members[] = (object) ['userid' => $uid, 'nodekey' => $nodekey];

            // A manager manages the node their own department resolves to, so
            // it is recorded here rather than worked out again: this is the
            // same key their participants were just placed in.
            if (isset($managerids[$uid]) && $deptkey !== null) {
                $managers[] = (object) ['userid' => $uid, 'nodekey' => $deptkey];
            }

            $attrs[] = (object) [
                'userid' => $uid,
                'auth' => $u->auth,
                'active' => $u->suspended == 0,
                'function' => $u->function,
            ];
        }

        ksort($nodes);

        /*
         * Managers stated on the mapping page, not only those a capability
         * betrayed.
         *
         * This list is what stops a manager being counted among their own
         * people on the Report LMS side. It used to be built from the
         * capability alone, which was fine while every manager held one, and
         * became empty the moment a site did the thing the access rework asks
         * of it: name its managers and take the blanket grant off the manager
         * role. A site that had tidied up correctly was the one whose figures
         * still counted its managers.
         *
         * Keyed to the unit they were named against rather than to their own
         * department, because a naming says which unit somebody runs and that
         * is the whole content of it.
         *
         * Role rows are skipped. A role names a set of people that changes on
         * its own, and the wire carries people.
         */
        foreach (mapping::managers() as $named) {
            if (!$named->userid) {
                continue;
            }

            $namedkey = mapping::nodekey((int) $named->nodeid);

            if ($namedkey === null) {
                continue;
            }

            $managers[] = (object) [
                'userid' => (int) $named->userid,
                'nodekey' => $namedkey,
            ];
        }

        // The same person can be both named and detected against the same unit,
        // and saying so twice is not saying it more firmly.
        $seenmanagers = [];
        $managers = array_values(array_filter($managers, static function ($manager) use (&$seenmanagers): bool {
            $key = $manager->userid . '@' . $manager->nodekey;

            if (isset($seenmanagers[$key])) {
                return false;
            }

            $seenmanagers[$key] = true;

            return true;
        }));

        return (object) [
            'nodes' => array_values($nodes),
            'members' => $members,
            'managers' => $managers,
            'attrs' => $attrs,
            /*
             * What this tree is not sure about.
             *
             * Kept on the object for the settings page, and deliberately not
             * sent: every one of these is fixed by changing a profile field or
             * a role assignment on this site, so a copy on the Report LMS side
             * would be a number nobody there could act on.
             */
            'warnings' => [
                'unplaceable_managers' => count(\local_reportlms\local\manager_resolver::unplaceable()),
                'contested_learners' => count(\local_reportlms\local\manager_resolver::contested()),
                'overridden_learners' => count(\local_reportlms\local\manager_resolver::overridden()),
            ],
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
