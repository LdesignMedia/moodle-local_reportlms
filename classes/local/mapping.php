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
 * **What somebody states here is the answer.** A unit holds everybody its
 * sources include and everybody named into it, and a person is in every unit
 * that says so. Nothing stated here is overruled by anything else stated here:
 * a course chosen for two departments puts its people in both.
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

    /**
     * A placement the migration wrote, rather than one a person typed.
     *
     * The weakest claim there is, weaker than any source. The migration
     * reproduced a tree that had been worked out from profile fields, one row
     * per learner, and stored it the only way there was at the time: as though
     * somebody had named all 3,262 of them by hand.
     *
     * Left as `manual` it outranked every source, so on a migrated site a
     * course could never draw anybody: they were all already spoken for. It is
     * a mechanical reproduction of an old guess, and it yields to anything
     * somebody has since said deliberately.
     *
     * @var string
     */
    public const ADOPTED = 'adopted';

    /** @var string A row derived from a profile field. Rebuilt on every push. */
    public const AUTO = 'auto';

    /** @var array<int, \stdClass>|null Memoised per request. */
    protected static $nodes = null;

    /** @var array<int, array<int, int>>|null Learner id to node ids, manual rows only. */
    protected static $members = null;

    /** @var array<int, array<int, int>>|null Learner id to node ids, sources and rows together. */
    protected static $resolved = null;

    /** @var array<int, array<int, int>>|null Learner id to node ids, what the migration left. */
    protected static $adopted = null;

    /** @var array<int, array<int, int>>|null Node id to learner ids, the resolved map turned round. */
    protected static $byunit = null;

    /**
     * The kinds of source a unit can draw people from.
     *
     * No order and no winner. A unit holds everybody its sources include, so
     * a course chosen for two departments puts its people in both. Picking one
     * unit per person used to drop people silently: the narrower claim took
     * them away from the other unit, and two equal claims left them in
     * neither, while the editor showed the course chosen in both.
     *
     * @var string[]
     */
    public const SOURCE_KINDS = ['group', 'course', 'cohort', 'category', 'profilefield'];

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
        self::$resolved = null;
        self::$adopted = null;
        self::$byunit = null;
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
     * Which units each learner was named into by hand.
     *
     * @return array<int, array<int, int>> Learner user id to node ids.
     */
    public static function membership(): array {
        if (self::$members === null) {
            self::$members = self::rows_by_user(self::MANUAL);
        }

        return self::$members;
    }

    /**
     * Member rows of one kind, as learner id to node ids.
     *
     * A recordset rather than get_records_menu(): a menu keeps one value per
     * key, so a learner with rows in two units would come back in one of them.
     *
     * @param string $source {@see MANUAL} or {@see ADOPTED}.
     *
     * @return array<int, array<int, int>>
     */
    protected static function rows_by_user(string $source): array {
        global $DB;

        $found = [];
        $rows = $DB->get_recordset('local_reportlms_member', ['source' => $source], 'userid, nodeid', 'id, userid, nodeid');

        foreach ($rows as $row) {
            $found[(int) $row->userid][(int) $row->nodeid] = (int) $row->nodeid;
        }

        $rows->close();

        return $found;
    }

    /**
     * Who is in which unit: everybody a source draws and everybody with a row.
     *
     * The whole site in one pass, because the editor asks it once per unit
     * while drawing a table and the collector asks it for every learner. Five
     * queries, one per source kind, each covering every unit at once.
     *
     * Nothing here is stored. A unit holds the sentence "everybody on the
     * induction course", not the list of people that sentence produced last
     * time, so somebody enrolled tomorrow is in the unit tomorrow without
     * anybody touching the editor.
     *
     * A learner is in every unit that claims them, and nothing outranks
     * anything. A course chosen for two departments puts its people in both; a
     * name typed into a unit adds that unit; a placement the migration left
     * adds its unit too, because it is a name in that unit's Person box and a
     * name there that did nothing would be a lie on the screen.
     *
     * @return array<int, array<int, int>> Learner user id to node ids, in node id order.
     */
    public static function resolved_membership(): array {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $resolved = self::source_claims();

        foreach ([self::adopted_membership(), self::membership()] as $rows) {
            foreach ($rows as $userid => $nodeids) {
                foreach ($nodeids as $nodeid) {
                    $resolved[(int) $userid][(int) $nodeid] = (int) $nodeid;
                }
            }
        }

        // A stable order, so the org state hash is the same on every run.
        foreach ($resolved as &$nodeids) {
            ksort($nodeids);
        }
        unset($nodeids);

        ksort($resolved);

        return self::$resolved = $resolved;
    }

    /**
     * Every claim a source makes, as user id => [node ids].
     *
     * @return array<int, array<int, int>>
     */
    protected static function source_claims(): array {
        global $DB;

        $rows = $DB->get_records('local_reportlms_source', null, 'itemtype, itemid');

        if (!$rows) {
            return [];
        }

        $bytype = [];

        foreach ($rows as $row) {
            $bytype[$row->itemtype][] = $row;
        }

        $claims = [];

        foreach ($bytype as $itemtype => $typerows) {
            foreach (self::people_for($itemtype, $typerows) as $userid => $nodeids) {
                foreach ($nodeids as $nodeid) {
                    $claims[(int) $userid][(int) $nodeid] = (int) $nodeid;
                }
            }
        }

        return $claims;
    }

    /**
     * The people one kind of source draws, keyed by user id to node ids.
     *
     * One query per kind rather than one per source row: a site with 286 units
     * would otherwise ask the database three hundred times to draw one table.
     *
     * @param string $itemtype
     * @param array<int, \stdClass> $rows
     *
     * @return array<int, array<int, int>>
     */
    protected static function people_for(string $itemtype, array $rows): array {
        global $DB;

        $nodesfor = [];

        foreach ($rows as $row) {
            $nodesfor[(int) $row->itemid][] = (int) $row->nodeid;
        }

        $found = [];

        if ($itemtype === 'category') {
            // A category means the whole branch under it, so the courses are
            // resolved first and then treated exactly like a course source.
            foreach ($nodesfor as $categoryid => $nodeids) {
                foreach (self::courses_under([$categoryid]) as $courseid) {
                    foreach (self::participants_of([$courseid]) as $userid) {
                        foreach ($nodeids as $nodeid) {
                            $found[(int) $userid][$nodeid] = $nodeid;
                        }
                    }
                }
            }

            return $found;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($nodesfor), SQL_PARAMS_NAMED);

        switch ($itemtype) {
            case 'course':
                $sql = "SELECT ue.id, ue.userid, e.courseid AS item
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {user} u ON u.id = ue.userid
                         WHERE e.courseid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            case 'group':
                $sql = "SELECT gm.id, gm.userid, gm.groupid AS item
                          FROM {groups_members} gm
                          JOIN {user} u ON u.id = gm.userid
                         WHERE gm.groupid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            case 'cohort':
                $sql = "SELECT cm.id, cm.userid, cm.cohortid AS item
                          FROM {cohort_members} cm
                          JOIN {user} u ON u.id = cm.userid
                         WHERE cm.cohortid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            case 'profilefield':
                return self::people_for_profile_values($rows);

            default:
                return [];
        }

        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            foreach ($nodesfor[(int) $row->item] ?? [] as $nodeid) {
                $found[(int) $row->userid][$nodeid] = $nodeid;
            }
        }

        return $found;
    }

    /**
     * The people a profile-value source draws.
     *
     * Its own method because the match is a field and a value rather than an
     * id, so it cannot share the shape of the other four.
     *
     * @param array<int, \stdClass> $rows
     *
     * @return array<int, array<int, int>>
     */
    protected static function people_for_profile_values(array $rows): array {
        global $DB;

        $found = [];

        foreach ($rows as $row) {
            $sql = "SELECT d.id, d.userid
                      FROM {user_info_data} d
                      JOIN {user} u ON u.id = d.userid
                     WHERE d.fieldid = :fieldid AND " . $DB->sql_compare_text('d.data', 255) . " = :value
                       AND u.deleted = 0 AND u.id > 2";

            foreach ($DB->get_records_sql($sql, ['fieldid' => (int) $row->itemid, 'value' => $row->itemvalue]) as $hit) {
                $found[(int) $hit->userid][(int) $row->nodeid] = (int) $row->nodeid;
            }
        }

        return $found;
    }

    /**
     * The placements the migration wrote.
     *
     * @return array<int, array<int, int>> Learner user id to node ids.
     */
    public static function adopted_membership(): array {
        if (self::$adopted === null) {
            self::$adopted = self::rows_by_user(self::ADOPTED);
        }

        return self::$adopted;
    }

    /**
     * Everybody this unit holds by a row of their own, however it got there.
     *
     * What the Person box holds, and what saving the form compares against.
     * A name somebody typed and a placement the migration carried over are both
     * statements about one person, both visible in the people list, and both
     * removable, so the box that lists individuals lists both.
     *
     * Only the people a source drew are absent, because they are not
     * individuals to this unit: they are a consequence of a sentence, and
     * taking one out of the box would not remove them.
     *
     * Kept apart from {@see named_members_of}, which is manual rows only and
     * answers the different question the people list asks: not "is this person
     * placed" but "why".
     *
     * @param int $nodeid
     *
     * @return array<int, int> User ids.
     */
    public static function placed_members_of(int $nodeid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'local_reportlms_member',
            'userid',
            'nodeid = :nodeid',
            ['nodeid' => $nodeid]
        ));
    }

    /**
     * How many people each unit holds, in one pass.
     *
     * The units table asks this for every row it draws, and members_of() walks
     * the whole site's resolved membership each time it is called: fifty rows
     * against thirteen thousand learners is 650,000 comparisons to render one
     * table. This walks it once.
     *
     * @return array<int, int> Node id to headcount. A unit with nobody is absent.
     */
    public static function member_counts(): array {
        return array_map('count', self::by_unit());
    }

    /**
     * How many distinct people each unit holds, its own and everything below.
     *
     * Not the sum of the children: a person in two departments of one sector
     * is one person in that sector, which is how Report LMS counts it too.
     *
     * @return array<int, int> Node id to headcount. A unit with nobody is absent.
     */
    public static function rolled_up_counts(): array {
        $nodes = self::nodes();
        $counts = [];

        foreach (self::resolved_membership() as $nodeids) {
            $reached = [];

            foreach ($nodeids as $nodeid) {
                // Up the tree from each unit, once per unit reached, and never
                // round a loop if a parent has been pointed at a descendant.
                while ($nodeid !== null && isset($nodes[$nodeid]) && !isset($reached[$nodeid])) {
                    $reached[$nodeid] = true;
                    $parentid = $nodes[$nodeid]->parentid;
                    $nodeid = $parentid === null ? null : (int) $parentid;
                }
            }

            foreach (array_keys($reached) as $nodeid) {
                $counts[$nodeid] = ($counts[$nodeid] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The resolved membership turned round: node id to the learners in it.
     *
     * Built once per request. members_of() used to walk the whole site's
     * membership for every unit it was asked about.
     *
     * @return array<int, array<int, int>> Node id to user ids. A unit with nobody is absent.
     */
    protected static function by_unit(): array {
        if (self::$byunit === null) {
            self::$byunit = [];

            foreach (self::resolved_membership() as $userid => $nodeids) {
                foreach ($nodeids as $nodeid) {
                    self::$byunit[(int) $nodeid][] = (int) $userid;
                }
            }
        }

        return self::$byunit;
    }

    /**
     * How many people are on each course.
     *
     * Counted the same way {@see participants_of} draws them, deliberately: the
     * number beside a course in the picker is a promise about what choosing it
     * will do, and a promise counted differently from the thing it describes is
     * worse than no number at all.
     *
     * @return array<int, int> Course id to headcount.
     */
    public static function participant_counts(): array {
        global $DB;

        $sql = "SELECT e.courseid, COUNT(DISTINCT ue.userid) headcount
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE u.deleted = 0 AND u.id > 2
              GROUP BY e.courseid";

        $counts = [];

        foreach ($DB->get_records_sql($sql) as $row) {
            $counts[(int) $row->courseid] = (int) $row->headcount;
        }

        return $counts;
    }

    /**
     * The people named into a unit by hand, and nobody a source drew.
     *
     * What the Person box holds. Opening it with the whole membership made a
     * name and a sentence look the same: removing somebody a course had drawn
     * put them back on the next read, and there was no way to say anything the
     * sources did not already say.
     *
     * @param int $nodeid
     *
     * @return array<int, int> User ids.
     */
    public static function named_members_of(int $nodeid): array {
        $ids = [];

        foreach (self::membership() as $userid => $nodeids) {
            if (isset($nodeids[$nodeid])) {
                $ids[] = (int) $userid;
            }
        }

        return $ids;
    }

    /**
     * Which of a unit's sources drew each person, named.
     *
     * The editor's people list used to say "drawn by a source" on every such
     * row, which is our word for the thing rather than an answer: somebody who
     * cannot remove a row needs to know what to go and change, and one unit can
     * draw from five courses, three cohorts and a profile value at once. So each
     * row names the ones that actually produced it.
     *
     * Names come from the site rather than from the source row, because a course
     * that has been renamed since somebody chose it should read as it reads
     * everywhere else. An id whose thing has been deleted is skipped: the row it
     * produced cannot exist any more either, so saying so would be describing a
     * state that is already gone.
     *
     * @param int $nodeid
     *
     * @return array<int, array<int, array{kind: string, name: string}>> User id to sources.
     */
    public static function drawn_by(int $nodeid): array {
        global $DB;

        $rows = self::sources_of($nodeid);

        if (!$rows) {
            return [];
        }

        $bytype = [];

        foreach ($rows as $row) {
            $bytype[$row->itemtype][] = $row;
        }

        $drawn = [];

        foreach ($bytype as $itemtype => $typerows) {
            $names = self::source_names($itemtype, $typerows);

            foreach (self::people_by_source($itemtype, $typerows) as $userid => $sourceids) {
                foreach ($sourceids as $sourceid) {
                    if (!isset($names[$sourceid])) {
                        // Its course, cohort or group is gone. So is the row it
                        // drew, on the next read.
                        continue;
                    }

                    $drawn[(int) $userid][] = ['kind' => $itemtype, 'name' => $names[$sourceid]];
                }
            }
        }

        return $drawn;
    }

    /**
     * The people each individual source row draws, keyed by source row id.
     *
     * {@see people_for()} answers the question resolution asks, which unit
     * claims this person, and throws away which of the unit's sources did it.
     * This keeps that, because the editor has to name it on the row.
     *
     * @param string $itemtype
     * @param array<int, \stdClass> $rows Source rows of one kind, one unit.
     *
     * @return array<int, array<int, int>> User id to source row ids.
     */
    protected static function people_by_source(string $itemtype, array $rows): array {
        global $DB;

        $found = [];

        if ($itemtype === 'profilefield') {
            foreach ($rows as $row) {
                $sql = "SELECT d.id, d.userid
                          FROM {user_info_data} d
                          JOIN {user} u ON u.id = d.userid
                         WHERE d.fieldid = :fieldid AND " . $DB->sql_compare_text('d.data', 255) . " = :value
                           AND u.deleted = 0 AND u.id > 2";

                foreach ($DB->get_records_sql($sql, ['fieldid' => (int) $row->itemid, 'value' => $row->itemvalue]) as $hit) {
                    $found[(int) $hit->userid][] = (int) $row->id;
                }
            }

            return $found;
        }

        // Which source rows point at each item. Two rows of one unit naming the
        // same course would be one source twice, but the read is written to
        // survive it rather than assume it cannot happen.
        $rowsfor = [];

        foreach ($rows as $row) {
            $rowsfor[(int) $row->itemid][] = (int) $row->id;
        }

        if ($itemtype === 'category') {
            foreach ($rowsfor as $categoryid => $sourceids) {
                foreach (self::courses_under([$categoryid]) as $courseid) {
                    foreach (self::participants_of([$courseid]) as $userid) {
                        foreach ($sourceids as $sourceid) {
                            $found[(int) $userid][] = $sourceid;
                        }
                    }
                }
            }

            return $found;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($rowsfor), SQL_PARAMS_NAMED);

        switch ($itemtype) {
            case 'course':
                $sql = "SELECT ue.id, ue.userid, e.courseid AS item
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {user} u ON u.id = ue.userid
                         WHERE e.courseid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            case 'group':
                $sql = "SELECT gm.id, gm.userid, gm.groupid AS item
                          FROM {groups_members} gm
                          JOIN {user} u ON u.id = gm.userid
                         WHERE gm.groupid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            case 'cohort':
                $sql = "SELECT cm.id, cm.userid, cm.cohortid AS item
                          FROM {cohort_members} cm
                          JOIN {user} u ON u.id = cm.userid
                         WHERE cm.cohortid {$insql} AND u.deleted = 0 AND u.id > 2";
                break;

            default:
                return [];
        }

        foreach ($DB->get_recordset_sql($sql, $params) as $row) {
            foreach ($rowsfor[(int) $row->item] ?? [] as $sourceid) {
                $found[(int) $row->userid][] = $sourceid;
            }
        }

        return $found;
    }

    /**
     * The display name behind each source row, keyed by source row id.
     *
     * Names come off the site rather than out of the source row, so a course
     * renamed since somebody chose it reads here as it reads everywhere else.
     *
     * @param string $itemtype
     * @param array<int, \stdClass> $rows Source rows of one kind.
     *
     * @return array<int, string> Source row id to name.
     */
    protected static function source_names(string $itemtype, array $rows): array {
        global $DB;

        if ($itemtype === 'profilefield') {
            // The value is the interesting half: a unit drawing "Transport" out
            // of a department field is described by "Transport", not by the name
            // of the field it came from.
            $names = [];

            foreach ($rows as $row) {
                $names[(int) $row->id] = (string) $row->itemvalue;
            }

            return $names;
        }

        $tables = [
            'course' => ['course', 'fullname'],
            'category' => ['course_categories', 'name'],
            'cohort' => ['cohort', 'name'],
            'group' => ['groups', 'name'],
        ];

        if (!isset($tables[$itemtype])) {
            return [];
        }

        [$tablename, $field] = $tables[$itemtype];

        $ids = [];

        foreach ($rows as $row) {
            $ids[(int) $row->itemid] = (int) $row->itemid;
        }

        $bynames = [];

        foreach ($DB->get_records_list($tablename, 'id', $ids, '', "id, {$field}") as $record) {
            $bynames[(int) $record->id] = format_string($record->{$field});
        }

        $names = [];

        foreach ($rows as $row) {
            if (isset($bynames[(int) $row->itemid])) {
                $names[(int) $row->id] = $bynames[(int) $row->itemid];
            }
        }

        return $names;
    }

    /**
     * Where a unit draws its people from.
     *
     * @param int $nodeid
     *
     * @return array<int, \stdClass> {id, itemtype, itemid, itemvalue}
     */
    public static function sources_of(int $nodeid): array {
        global $DB;

        return $DB->get_records('local_reportlms_source', ['nodeid' => $nodeid], 'itemtype, itemid');
    }

    /**
     * Replace what a unit draws from.
     *
     * Whole-list, because the editor shows the whole list: what comes back from
     * the form is what the unit draws from, and a source that is gone from the
     * form is one somebody removed.
     *
     * @param int $nodeid
     * @param array $sources Keyed courses|categories|cohorts|groups|profilevalues.
     *
     * @return void
     */
    public static function save_sources(int $nodeid, array $sources): void {
        global $DB, $USER;

        $wanted = [];

        foreach (['courses' => 'course', 'categories' => 'category', 'cohorts' => 'cohort', 'groups' => 'group'] as $key => $itemtype) {
            foreach (array_filter(array_map('intval', $sources[$key] ?? [])) as $itemid) {
                $wanted[$itemtype . ':' . $itemid . ':'] = [$itemtype, $itemid, ''];
            }
        }

        foreach (self::profile_values($sources['profilevalues'] ?? []) as $fieldid => $values) {
            foreach ($values as $value) {
                $wanted['profilefield:' . $fieldid . ':' . $value] = ['profilefield', $fieldid, $value];
            }
        }

        $existing = [];

        foreach (self::sources_of($nodeid) as $row) {
            $existing[$row->itemtype . ':' . $row->itemid . ':' . $row->itemvalue] = $row;
        }

        foreach (array_diff_key($existing, $wanted) as $row) {
            $DB->delete_records('local_reportlms_source', ['id' => $row->id]);
        }

        foreach (array_diff_key($wanted, $existing) as [$itemtype, $itemid, $itemvalue]) {
            $DB->insert_record('local_reportlms_source', (object) [
                'nodeid' => $nodeid,
                'itemtype' => $itemtype,
                'itemid' => $itemid,
                'itemvalue' => $itemvalue,
                'timemodified' => time(),
                'usermodified' => (int) $USER->id,
            ]);
        }

        self::reset();
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
     * How many people nobody has put in a unit.
     *
     * The standing chore of a hand-built tree, and the one number that says
     * whether it is being kept up. They are not lost: they land in Unassigned
     * and stay in the site's totals, so nothing silently drops out of a
     * compliance figure. But nobody will notice them unless somebody counts.
     *
     * @return int
     */
    public static function unplaced_count(): int {
        global $DB;

        /*
         * Resolved, not counted in SQL.
         *
         * Most people in a unit are there because of a source, and a source is
         * a sentence rather than a row per person, so a query against the
         * member table would report a site of thirteen thousand as thirteen
         * thousand unplaced.
         */
        $placed = self::resolved_membership();

        $count = 0;

        foreach ($DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 2') as $userid) {
            if (empty($placed[(int) $userid])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * These units and everything under them.
     *
     * Naming somebody the manager of a sector has to mean the departments in
     * it. Report LMS already expands a scope node to its subtree before it
     * serves a figure, so anything on this side that answers the same question
     * about the same people has to expand it too, or a sector manager is served
     * numbers for people whose names this site refuses to translate.
     *
     * The tree is small and already loaded, so this walks it in memory rather
     * than asking the database for a recursive query it may not have.
     *
     * @param array<int, int> $nodeids
     *
     * @return array<int, int> The given units and their descendants.
     */
    public static function subtree(array $nodeids): array {
        $children = [];

        foreach (self::nodes() as $node) {
            if ($node->parentid !== null) {
                $children[(int) $node->parentid][] = (int) $node->id;
            }
        }

        $found = [];
        $queue = array_map('intval', $nodeids);

        while ($queue) {
            $nodeid = array_pop($queue);

            if (isset($found[$nodeid])) {
                continue;
            }

            $found[$nodeid] = $nodeid;

            foreach ($children[$nodeid] ?? [] as $childid) {
                $queue[] = $childid;
            }
        }

        return array_values($found);
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
        return self::by_unit()[$nodeid] ?? [];
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

        /*
         * One name per parent, and this is not a matter of tidiness.
         *
         * A unit's key is its name and its parent's: two departments called
         * Flexpool A under one sector produce the identical key
         * `department:Operatie en Techniek/Flexpool A`. Report LMS holds one
         * node for that key, so their people silently merge into whichever the
         * payload mentioned last, and the two rows in the editor disagree with
         * the one row on the dashboard with nothing to explain the difference.
         *
         * Refused rather than merged, because the editor cannot know which of
         * the two the person meant, and quietly folding one into the other is
         * how somebody loses a department they were halfway through building.
         */
        if (self::name_taken($record->name, $record->kind, $record->parentid, $id)) {
            throw new \moodle_exception('manage:nametaken', 'local_reportlms', '', $record->name);
        }

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
     * Whether a unit of this kind and name already sits under this parent.
     *
     * Case-insensitive, because "Sales" and "sales" are one department to
     * everybody except a database, and they key the same way once the name is
     * read back out.
     *
     * @param string $name
     * @param string $kind
     * @param int|null $parentid
     * @param int|null $exceptid The unit being renamed, which may keep its own name.
     *
     * @return bool
     */
    public static function name_taken(string $name, string $kind, ?int $parentid, ?int $exceptid = null): bool {
        $wanted = \core_text::strtolower(trim($name));

        foreach (self::nodes() as $node) {
            if ($exceptid !== null && (int) $node->id === $exceptid) {
                continue;
            }

            if ($node->kind !== $kind) {
                continue;
            }

            $nodeparent = $node->parentid === null ? null : (int) $node->parentid;

            if ($nodeparent !== ($parentid === null ? null : (int) $parentid)) {
                continue;
            }

            if (\core_text::strtolower(trim($node->name)) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unit names used more than once anywhere in the tree.
     *
     * Not an error, and worth saying out loud. `HR` under Transport and `HR`
     * under Office are two real groups of people with two different keys, so
     * the tree allows them. They are also two identical-looking rows in a
     * table, and somebody reading it has no way to tell which is which without
     * opening both.
     *
     * @return array<int, string> The repeated names, as first written.
     */
    public static function repeated_names(): array {
        $seen = [];
        $repeated = [];

        foreach (self::nodes() as $node) {
            $key = $node->kind . ':' . \core_text::strtolower(trim($node->name));

            if (isset($seen[$key])) {
                $repeated[$key] = $seen[$key];

                continue;
            }

            $seen[$key] = $node->name;
        }

        return array_values($repeated);
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
        // And what it drew from, or the sources would keep resolving people
        // into a unit nobody can see.
        $DB->delete_records('local_reportlms_source', ['nodeid' => $nodeid]);
        $DB->set_field('local_reportlms_node', 'parentid', null, ['parentid' => $nodeid]);
        $DB->delete_records('local_reportlms_node', ['id' => $nodeid]);

        self::reset();
    }

    /**
     * Put a learner in a unit, on top of any unit they are already in.
     *
     * A person can be in several units, so naming somebody here adds this one
     * and leaves the others alone. It used to move them, which is how typing a
     * name into one department took it out of another without anybody saying so.
     *
     * A person already holding a row in this unit keeps it as it is, typed or
     * carried over by the migration: either way they are in it already.
     *
     * @param int $userid
     * @param int $nodeid
     *
     * @return void
     */
    public static function place(int $userid, int $nodeid): void {
        global $DB, $USER;

        if ($DB->record_exists('local_reportlms_member', ['userid' => $userid, 'nodeid' => $nodeid])) {
            return;
        }

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
     * Take a learner out of one unit.
     *
     * Only this unit. It used to delete the person's rows everywhere, which was
     * the same thing while a person could be in one unit only, and is not now:
     * removing somebody from Sales must not take them out of Support.
     *
     * Only rows go. Somebody a source of this unit draws is still in it, which
     * is why the editor offers Remove on rows and never on drawn people.
     *
     * @param int $userid
     * @param int $nodeid
     *
     * @return void
     */
    public static function release(int $userid, int $nodeid): void {
        global $DB;

        // Both kinds of stored row: a name somebody typed and a placement the
        // migration left behind are both things Remove is expected to remove.
        $DB->delete_records('local_reportlms_member', ['userid' => $userid, 'nodeid' => $nodeid]);

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

        $found = self::participants_of($courseids);

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
         * Pointing a unit at a profile value is a sentence somebody said, where
         * deriving the tree from that same field was a rule nobody chose. It is
         * what makes a site with good HR data and no capabilities work, and it
         * is the reason a department does not have to be enrolled in anything
         * to exist.
         *
         * Any profile field, not one nominated in the settings. The field used
         * to be a site-wide mapping because the tree came from it and there
         * could only be one answer; a source is chosen per unit, so "which
         * field" belongs next to the value rather than in a setting somebody
         * has to change first.
         */
        foreach (self::profile_values($sources['profilevalues'] ?? []) as $fieldid => $values) {
            [$insql, $params] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED);
            $params['fieldid'] = $fieldid;

            $found = array_merge($found, $DB->get_fieldset_sql(
                "SELECT d.userid
                   FROM {user_info_data} d
                  WHERE d.fieldid = :fieldid AND d.data {$insql}",
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
     * The enrolled users of a set of courses.
     *
     * One query defines "participant", because the unit sources and anything
     * that later reacts to an enrolment have to agree about who is on a course.
     *
     * @param array<int, int> $courseids
     *
     * @return array<int, int> User ids.
     */
    public static function participants_of(array $courseids): array {
        global $DB;

        if (!$courseids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        $sql = "SELECT DISTINCT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid {$insql}
                   AND u.deleted = 0
                   AND u.id > 2";

        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Chosen profile values, grouped by the field they belong to.
     *
     * The picker offers `fieldid:value` because the same value means different
     * things in different fields, and a site holding "Rotterdam" in both a
     * department field and an office field would otherwise draw both.
     *
     * @param array $selected Raw `fieldid:value` strings from the form.
     *
     * @return array<int, array<int, string>> Values keyed by field id.
     */
    protected static function profile_values(array $selected): array {
        $byfield = [];

        foreach ($selected as $raw) {
            $raw = (string) $raw;
            $split = strpos($raw, ':');

            if ($split === false) {
                continue;
            }

            $fieldid = (int) substr($raw, 0, $split);
            $value = trim(substr($raw, $split + 1));

            if ($fieldid <= 0 || $value === '') {
                continue;
            }

            $byfield[$fieldid][] = $value;
        }

        return $byfield;
    }

    /**
     * Take a naming back out of the register.
     *
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
