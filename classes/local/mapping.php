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
 * Reads and writes the organisation somebody stated.
 *
 * Auto-detect gets a real company approximately right and two shapes exactly
 * wrong: a manager who runs two departments places all their people in one, and
 * a manager with no department on their profile places nobody. This is the
 * answer to both, and to every site whose profile fields do not describe their
 * organisation at all.
 *
 * Two rules hold the design together.
 *
 * **What somebody states here is the answer.** A row somebody
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
     * Every unit, keyed by id.
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
     * Which unit each learner was put in.
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
     * under a sector is "sector/department", so a stated unit and a derived
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
     * The learners placed in a unit.
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
     * The learners are not touched, they simply stop being claimed and
     * simply stop being in it, which is the point of
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
     * Put a learner in a unit.
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
     * Take a learner out of the unit they were placed in.
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
     * Every course under these categories, including the ones below them.
     *
     * "Everyone under Sales" means the whole branch, not the courses somebody
     * happened to file directly in it. Walked with the stored path rather than
     * recursively, so a deep tree is one query.
     *
     * @param array<int, int> $categoryids
     *
     * @return array<int, int> Course ids.
     */
    protected static function courses_under(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_filter(array_map('intval', $categoryids)));

        if (!$categoryids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $paths = $DB->get_fieldset_select('course_categories', 'path', "id {$insql}", $params);

        if (!$paths) {
            return [];
        }

        $where = [];
        $params = [];

        foreach (array_values($paths) as $i => $path) {
            // The category itself, and everything filed beneath it. The
            // trailing slash matters: without it /1/5 would also match /1/50.
            $where[] = 'path = :exact' . $i . ' OR ' . $DB->sql_like('path', ':below' . $i);
            $params['exact' . $i] = $path;
            $params['below' . $i] = $DB->sql_like_escape($path . '/') . '%';
        }

        $ids = $DB->get_fieldset_select('course_categories', 'id', '(' . implode(') OR (', $where) . ')', $params);

        if (!$ids) {
            return [];
        }

        [$catsql, $catparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        return array_map('intval', $DB->get_fieldset_select('course', 'id', "category {$catsql}", $catparams));
    }

    /**
     * The people named by any of these, for placing into a unit.
     *
     * Several ways to say the same kind of thing, because sites describe
     * themselves differently and none of them describe themselves the way this
     * plugin would prefer. A group, a course, a cohort, a whole category, a
     * profile field value: whichever of them an administrator can point at
     * without having to remember four hundred names.
     *
     * Listed there in order of how narrow they are, which is the order a future
     * resolver will use when two units claim the same person. Nothing depends on
     * it yet: this method unions its sources for one unit and the caller places
     * the result.
     *
     * This replaced deriving the list from a manager's own enrolments. That
     * looked equivalent and was not: measured on a real site it offered three
     * people for one unit and 1,467 for another, because that manager is an
     * ordinary participant on an all-staff course. Being enrolled somewhere is
     * not managing it, so the source is chosen rather than guessed.
     *
     * Left out: the guest, site administrators, this unit's own managers, and
     * anybody already placed here, so the number shown is what pressing the
     * button changes.
     *
     * **Managers of other units are not left out.** A manager of one unit is an
     * ordinary learner in another, and the head of Sales still takes the
     * compliance course, so filtering every manager would make it impossible to
     * put them where they actually work.
     *
     * @param array $sources courses, categories and cohorts, each an array of ids.
     * @param int $nodeid The unit they would go into.
     *
     * @return array<int, int> User ids.
     */
    public static function candidates_from(array $sources, int $nodeid): array {
        global $DB;

        $courseids = array_map('intval', $sources['courses'] ?? []);
        $courseids = array_merge($courseids, self::courses_under($sources['categories'] ?? []));
        $courseids = array_values(array_unique(array_filter($courseids)));

        $found = $courseids ? manager_resolver::participants_of($courseids) : [];

        $cohortids = array_values(array_filter(array_map('intval', $sources['cohorts'] ?? [])));

        if ($cohortids) {
            [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED);
            $found = array_merge($found, $DB->get_fieldset_select('cohort_members', 'userid', "cohortid {$insql}", $params));
        }

        // A group is the narrowest thing a site can point at: a named set of
        // people inside one course, which is how many sites already model a
        // team that shares a course with three other teams.
        $groupids = array_values(array_filter(array_map('intval', $sources['groups'] ?? [])));

        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $found = array_merge($found, $DB->get_fieldset_select('groups_members', 'userid', "groupid {$insql}", $params));
        }

        /*
         * A profile field value, which is the one source that needs no course
         * at all.
         *
         * The same values the tree used to be derived from, except that pointing a unit
         * at one is a sentence somebody said rather than a rule nobody chose.
         * It is what makes a site with good HR data and no capabilities work,
         * and it is the reason a department does not have to be enrolled in
         * anything to exist.
         */
        $values = array_values(array_filter(array_map('trim', $sources['profilevalues'] ?? []), 'strlen'));

        if ($values) {
            $shortname = collector\org::fieldmap()['department'];

            [$insql, $params] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED);
            $params['shortname'] = $shortname;

            $found = array_merge($found, $DB->get_fieldset_sql(
                "SELECT d.userid
                   FROM {user_info_data} d
                   JOIN {user_info_field} f ON f.id = d.fieldid
                  WHERE f.shortname = :shortname AND d.data {$insql}",
                $params
            ));
        }

        if (!$found) {
            return [];
        }

        $already = array_flip(self::members_of($nodeid));

        // The people who run this unit, who are therefore not in it. They are on
        // the courses they run, so they arrive in their own list, and placing
        // them would count them among the people they manage.
        $theirs = [];

        foreach (self::managers() as $row) {
            if ((int) $row->nodeid === $nodeid && $row->userid) {
                $theirs[(int) $row->userid] = true;
            }
        }

        $candidates = [];

        foreach ($found as $participantid) {
            $participantid = (int) $participantid;

            // The guest belongs to no department. Cohort membership does not
            // go through the enrolment query that already drops them.
            if ($participantid <= 2 || isset($already[$participantid]) || isset($theirs[$participantid])) {
                continue;
            }

            if (is_siteadmin($participantid)) {
                continue;
            }

            $candidates[$participantid] = $participantid;
        }

        return array_values($candidates);
    }

    /**
     * The people on these courses. Kept for the common case and for its tests.
     *
     * @param array<int, int> $courseids
     * @param int $nodeid
     *
     * @return array<int, int> User ids.
     */
    public static function candidates_from_courses(array $courseids, int $nodeid): array {
        return self::candidates_from(['courses' => $courseids], $nodeid);
    }

    /**
     * Move a naming to a different unit.
     *
     * Separate from removing and re-adding, because the two are not the same
     * act. A correction should not read in the log as somebody losing their
     * managers and somebody else gaining them a second later.
     *
     * @param int $id
     * @param int $nodeid
     *
     * @return void
     */
    public static function move_manager(int $id, int $nodeid): void {
        global $DB, $USER;

        $row = $DB->get_record('local_reportlms_manager', ['id' => $id]);

        if (!$row || (int) $row->nodeid === $nodeid) {
            return;
        }

        // The same person against the same unit twice is not a stronger
        // statement, it is a duplicate row. Moving onto one collapses into it.
        $duplicate = $DB->get_record('local_reportlms_manager', [
            'nodeid' => $nodeid,
            'userid' => $row->userid,
            'roleid' => $row->roleid,
        ]);

        if ($duplicate) {
            $DB->delete_records('local_reportlms_manager', ['id' => $id]);
            self::reset();

            return;
        }

        $DB->update_record('local_reportlms_manager', (object) [
            'id' => $id,
            'nodeid' => $nodeid,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);

        self::reset();
    }

    /**
     * Turn the departments the detected managers run into units, once.
     *
     * Auto-detect already reads those departments off profile fields, so this
     * invents nothing. What it does is make them editable: a unit that exists as
     * a row can be renamed, re-parented and named a manager against, and one
     * that exists only as a profile-field value cannot.
     *
     * Units and namings only, never memberships. Placing people is what
     * a deliberate act, so doing it wholesale here would mean
     * one click quietly froze every learner's department and the next HR upload
     * would never move anybody. Placing somebody stays a deliberate act, one
     * person at a time.
     *
     * Names already used are left alone rather than duplicated, so running this
     * on a site that has typed in some of its tree adds only what is
     * missing.
     *
     * @return int How many units were created.
     */
    public static function import_manager_departments(): int {
        $existing = [];

        foreach (self::nodes() as $node) {
            $existing[\core_text::strtolower(trim($node->name))] = (int) $node->id;
        }

        $created = 0;

        foreach (manager_resolver::managers() as $manager) {
            if (is_siteadmin($manager->userid)) {
                // An administrator holds every capability everywhere, so they
                // are detected as managing whatever they can see. That is not a
                // fact about the organisation and it should not become a unit.
                continue;
            }

            foreach ($manager->departments as $department) {
                $name = trim((string) $department);

                if ($name === '') {
                    continue;
                }

                $key = \core_text::strtolower($name);

                if (!isset($existing[$key])) {
                    $existing[$key] = self::save_node($name, 'department');
                    $created++;
                }

                self::add_manager($existing[$key], (int) $manager->userid, null);
            }
        }

        return $created;
    }

    /**
     * Whether there is anything for {@see import_manager_departments} to do.
     *
     * Drives whether the offer is on screen at all. It stops being true the
     * moment it has been taken, so the link disappears by itself rather than
     * needing a flag to remember that somebody pressed it, and a site that never
     * presses it is never nagged twice about the same thing.
     *
     * @return bool
     */
    public static function has_departments_to_import(): bool {
        $existing = [];

        foreach (self::nodes() as $node) {
            $existing[\core_text::strtolower(trim($node->name))] = true;
        }

        foreach (manager_resolver::managers() as $manager) {
            if (is_siteadmin($manager->userid)) {
                continue;
            }

            foreach ($manager->departments as $department) {
                $name = trim((string) $department);

                if ($name !== '' && !isset($existing[\core_text::strtolower($name)])) {
                    return true;
                }
            }
        }

        return false;
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
