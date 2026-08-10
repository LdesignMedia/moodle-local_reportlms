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
 * The organisation somebody typed in, as opposed to the one derived from
 * profile fields.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads and writes the hand-built organisation.
 *
 * Auto-detect gets a real company approximately right and two shapes exactly
 * wrong: a manager who runs two departments places all their people in one, and
 * a manager with no department on their profile places nobody. This is the
 * answer to both, and to every site whose profile fields do not describe their
 * organisation at all.
 *
 * Two rules hold the design together.
 *
 * **Manual beats auto-detect beats the learner's own field.** A row somebody
 * typed is a statement of fact about the organisation; a profile field is a
 * guess derived from an HR export. When they disagree, the person wins.
 *
 * **Being a manager is data, not a permission.** Naming somebody here gives
 * them the report for that unit without any role assignment, because a training
 * coordinator should be able to say "Tom runs Sloterdijk Groep E" without
 * knowing what a Moodle capability is. The authority to say it is the
 * capability: local/reportlms:manage.
 */
class mapping {
    /** @var string A row somebody typed. Survives every push. */
    public const MANUAL = 'manual';

    /** @var string A row derived from a profile field. Rebuilt on every push. */
    public const AUTO = 'auto';

    /** @var array<int, \stdClass>|null Memoised per request. */
    protected static $nodes = null;

    /** @var array<int, int>|null Learner id to node id, manual rows only. */
    protected static $members = null;

    /**
     * Forget what was read.
     *
     * resetAfterTest() rolls back the database and leaves static properties
     * alone, and the editor changes rows between assertions inside one request.
     *
     * @return void
     */
    public static function reset(): void {
        self::$nodes = null;
        self::$members = null;
    }

    /**
     * Every hand-built unit, keyed by id.
     *
     * @return array<int, \stdClass> {id, name, parentid, kind}
     */
    public static function nodes(): array {
        global $DB;

        if (self::$nodes === null) {
            self::$nodes = $DB->get_records('local_reportlms_node', null, 'name ASC');
        }

        return self::$nodes;
    }

    /**
     * Which unit each learner was put in by hand.
     *
     * @return array<int, int> Learner user id to node id.
     */
    public static function membership(): array {
        global $DB;

        if (self::$members === null) {
            self::$members = $DB->get_records_menu(
                'local_reportlms_member',
                ['source' => self::MANUAL],
                '',
                'userid, nodeid'
            );
        }

        return self::$members;
    }

    /**
     * The key the org collector and the launch token both build codes from.
     *
     * Deliberately the same shape a profile-derived node produces: a department
     * under a sector is "sector/department", so a hand-built unit and a derived
     * one with the same name are the same node rather than two that look alike.
     *
     * @param int $nodeid
     *
     * @return string|null Null when the node is gone.
     */
    public static function nodekey(int $nodeid): ?string {
        $nodes = self::nodes();

        if (!isset($nodes[$nodeid])) {
            return null;
        }

        $node = $nodes[$nodeid];

        if ($node->kind === 'sector') {
            return 'sector:' . $node->name;
        }

        $parent = $node->parentid !== null && isset($nodes[$node->parentid])
            ? $nodes[$node->parentid]->name
            : null;

        return 'department:' . ($parent !== null ? $parent . '/' : '') . $node->name;
    }

    /**
     * The units this user manages by having been named, directly or by role.
     *
     * A role row means everyone holding that role manages the unit. Checked
     * against role assignments anywhere, because the editor names a role rather
     * than a role in a context: somebody made "Team lead" the manager of
     * Rotterdam, and where they hold that role is Moodle's business.
     *
     * @param int $userid
     *
     * @return array<int, int> Node ids.
     */
    public static function managed_nodes(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT m.nodeid
                  FROM {local_reportlms_manager} m
             LEFT JOIN {role_assignments} ra ON ra.roleid = m.roleid AND ra.userid = :roleuser
                 WHERE m.userid = :userid
                    OR (m.roleid IS NOT NULL AND ra.id IS NOT NULL)";

        return array_map('intval', array_keys($DB->get_records_sql($sql, [
            'userid' => $userid,
            'roleuser' => $userid,
        ])));
    }

    /**
     * Everyone named as a manager, for the register the editor shows.
     *
     * @return array<int, \stdClass> {id, nodeid, userid, roleid}
     */
    public static function managers(): array {
        global $DB;

        return $DB->get_records('local_reportlms_manager', null, 'nodeid ASC');
    }

    /**
     * The learners in a unit, put there by hand.
     *
     * @param int $nodeid
     *
     * @return array<int, int> User ids.
     */
    public static function members_of(int $nodeid): array {
        $ids = [];

        foreach (self::membership() as $userid => $node) {
            if ((int) $node === $nodeid) {
                $ids[] = (int) $userid;
            }
        }

        return $ids;
    }

    /**
     * Add or rename a unit.
     *
     * @param string $name
     * @param string $kind sector or department
     * @param int|null $parentid
     * @param int|null $id Existing node to rename.
     *
     * @return int The node id.
     */
    public static function save_node(string $name, string $kind, ?int $parentid = null, ?int $id = null): int {
        global $DB, $USER;

        $record = (object) [
            'name' => trim($name),
            'kind' => $kind === 'sector' ? 'sector' : 'department',
            // A sector has nothing above it, whatever the form said.
            'parentid' => $kind === 'sector' ? null : $parentid,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ];

        if ($id !== null) {
            $record->id = $id;
            $DB->update_record('local_reportlms_node', $record);
        } else {
            $id = (int) $DB->insert_record('local_reportlms_node', $record);
        }

        self::reset();

        return $id;
    }

    /**
     * Remove a unit, its membership and its managers.
     *
     * The learners are not touched, they simply stop being claimed by hand and
     * fall back to whatever auto-detect makes of them, which is the point of
     * being able to delete one at all.
     *
     * @param int $nodeid
     *
     * @return void
     */
    public static function delete_node(int $nodeid): void {
        global $DB;

        $DB->delete_records('local_reportlms_member', ['nodeid' => $nodeid]);
        $DB->delete_records('local_reportlms_manager', ['nodeid' => $nodeid]);
        $DB->set_field('local_reportlms_node', 'parentid', null, ['parentid' => $nodeid]);
        $DB->delete_records('local_reportlms_node', ['id' => $nodeid]);

        self::reset();
    }

    /**
     * Put a learner in a unit by hand.
     *
     * One unit per learner: placing them somewhere new moves them rather than
     * adding a second claim. Several departments per person is a v2 question
     * and the tree cannot express it yet, so pretending otherwise here would
     * store data nothing can read.
     *
     * @param int $userid
     * @param int $nodeid
     *
     * @return void
     */
    public static function place(int $userid, int $nodeid): void {
        global $DB, $USER;

        $DB->delete_records('local_reportlms_member', ['userid' => $userid, 'source' => self::MANUAL]);

        $DB->insert_record('local_reportlms_member', (object) [
            'nodeid' => $nodeid,
            'userid' => $userid,
            'source' => self::MANUAL,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);

        self::reset();
        userstate::mark_dirty($userid);
    }

    /**
     * Hand a learner back to auto-detect.
     *
     * They land wherever their profile field and their manager put them, which
     * may not be where they were before somebody moved them. That is correct
     * and it will still surprise people, so the editor says where they are
     * about to go.
     *
     * @param int $userid
     *
     * @return void
     */
    public static function release(int $userid): void {
        global $DB;

        $DB->delete_records('local_reportlms_member', ['userid' => $userid, 'source' => self::MANUAL]);

        self::reset();
        userstate::mark_dirty($userid);
    }

    /**
     * Name a manager for a unit, by user or by role.
     *
     * @param int $nodeid
     * @param int|null $userid
     * @param int|null $roleid
     *
     * @return void
     */
    public static function add_manager(int $nodeid, ?int $userid, ?int $roleid): void {
        global $DB, $USER;

        if ($userid === null && $roleid === null) {
            return;
        }

        $existing = $DB->record_exists('local_reportlms_manager', [
            'nodeid' => $nodeid,
            'userid' => $userid,
            'roleid' => $roleid,
        ]);

        if ($existing) {
            return;
        }

        $DB->insert_record('local_reportlms_manager', (object) [
            'nodeid' => $nodeid,
            // Either a user or a role, never both: a row that named both would
            // have two answers to "who does this mean".
            'userid' => $roleid !== null ? null : $userid,
            'roleid' => $roleid,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);

        self::reset();
    }

    /**
     * @param int $id
     *
     * @return void
     */
    public static function remove_manager(int $id): void {
        global $DB;

        $DB->delete_records('local_reportlms_manager', ['id' => $id]);

        self::reset();
    }
}
