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
 * Who counts as a learner in a course.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

/**
 * The countable (learner, course) pairs: enrolled AND holding a gradable role.
 *
 * A course-role Manager is enrolled like anybody else, so without a role check
 * they are a learner as far as every chart is concerned: a course of 101 where
 * 100 students finished reads 99% because the manager never will. Who should
 * count is not this plugin's question to invent. Moodle answers it with the
 * gradebook roles setting, the same rule its own gradebook and completion
 * reports use, so that is the rule adopted here.
 *
 * A role counts when it is assigned at the course context or any ancestor
 * (category, system), which is how Moodle itself resolves roles. The ancestry
 * check is deliberately done in PHP: the SQL version compares context paths
 * column-to-column, which no index can serve, while the two queries below are
 * both straight indexed lookups and the intersection is a set test per row.
 */
class gradable {
    /**
     * The role ids that make somebody a learner.
     *
     * The gradebook roles setting first; a site that has customised who is
     * gradable gets the same answer from us as from Moodle. If the setting is
     * empty or parses to nothing, the student archetype is the fallback.
     *
     * @return array<int, int> Role ids, never empty.
     */
    public static function roleids(): array {
        global $CFG, $DB;

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($CFG->gradebookroles ?? '')))));

        if (!$ids) {
            if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
                mtrace('local_reportlms: gradebookroles is empty, falling back to the student archetype.');
            } else {
                debugging('local_reportlms: gradebookroles is empty, falling back to the student archetype.');
            }
            $ids = array_map('intval', array_keys($DB->get_records('role', ['archetype' => 'student'], '', 'id')));
        }

        if (!$ids) {
            throw new push_refused_exception(
                'no_gradable_roles',
                'Neither gradebookroles nor the student archetype names any role, so nobody would count as a learner. Refusing to push an empty report.'
            );
        }

        return $ids;
    }

    /**
     * The countable pairs for a batch of learners.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     *
     * @return array<int, array<int, true>> userid => courseid => true.
     */
    public static function pairs(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        $roleids = self::roleids();

        // Every gradable-role assignment these users hold, wherever it sits in
        // the context tree. Both columns of the WHERE are indexed.
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');

        $assignments = [];
        $recordset = $DB->get_recordset_sql(
            "SELECT ra.id, ra.userid, ra.contextid
               FROM {role_assignments} ra
              WHERE ra.userid {$usersql}
                AND ra.roleid {$rolesql}",
            $userparams + $roleparams
        );
        foreach ($recordset as $record) {
            $assignments[(int) $record->userid][(int) $record->contextid] = true;
        }
        $recordset->close();

        // No early return when $assignments is empty: the enrolment query
        // still has to run so the NULL-path guard below can see a broken
        // context tree. Returning here would skip the one check that stops
        // an empty push from clearing everybody's dirty flags.

        // Every enrolment pair with its course context path.
        $pairs = [];
        $nullpaths = 0;
        $recordset = $DB->get_recordset_sql(
            "SELECT DISTINCT ue.userid, e.courseid, ctx.path
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
          LEFT JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :ctxlevel
              WHERE ue.userid {$usersql}",
            $userparams + ['ctxlevel' => CONTEXT_COURSE]
        );
        foreach ($recordset as $record) {
            $userid = (int) $record->userid;

            // A NULL or missing path is a context mid-rebuild (the LEFT JOIN
            // keeps a course with no context row visible here too): no basis
            // for a verdict either way. Counted rather than skipped, because a
            // silent skip would filter the learner out, the push would
            // succeed, and their cleared dirty flag would never bring them
            // back. Refusing below keeps the flags; the next run decides on
            // rebuilt paths.
            if ($record->path === null || $record->path === '') {
                $nullpaths++;
                continue;
            }

            if (!isset($assignments[$userid])) {
                continue;
            }

            // The path holds the course context id and every ancestor's:
            // '/1/3/45/67' is system, category, category, course. A gradable
            // role on any of them makes the enrolment count.
            foreach (explode('/', trim((string) $record->path, '/')) as $contextid) {
                if (isset($assignments[$userid][(int) $contextid])) {
                    $pairs[$userid][(int) $record->courseid] = true;
                    break;
                }
            }
        }
        $recordset->close();

        if ($nullpaths > 0) {
            throw new push_refused_exception(
                'context_paths_unbuilt',
                "$nullpaths enrolment pair(s) sit under a course context with no path (contexts mid-rebuild). "
                . 'Refusing to push rather than miscount; the next run will see rebuilt paths.'
            );
        }

        return $pairs;
    }
}
