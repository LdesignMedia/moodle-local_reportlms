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
 * Works out who manages whom, from the capability and course enrolment.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\org;

/**
 * Managers, their people, and the department those people land in.
 *
 * A manager is whoever holds local/reportlms:manage. Their people are the
 * participants of the courses they hold it on, which makes them those
 * participants' direct manager: a deliberate role assignment by an
 * administrator rather than a profile field an HR export happens to fill in.
 *
 * The department is the manager's own profile value, and it wins over whatever
 * a participant has set on themselves. That is the point of the model: one
 * person is responsible for a group, and the group inherits their place in the
 * tree instead of every individual being maintained separately.
 *
 * Context inheritance does the rest of the work. Granted on a category the
 * capability covers every course beneath it; granted at system level it reaches
 * every course on the site, which is the whole organisation. That is not capped
 * here, because capping it would contradict how Moodle contexts behave
 * everywhere else.
 *
 * v1.x gives each manager exactly one department. The plumbing carries sets
 * throughout so that supporting several later is a change of rule rather than
 * a change of shape.
 */
class manager_resolver {
    /**
     * Resolved managers for this request.
     *
     * The org collector asks for the membership and the manager list in the
     * same pass, and both walk every assigned context. Held for the life of the
     * request rather than recomputed, and deliberately not cached beyond it:
     * a role assignment or an enrolment changes this, and neither has a marker
     * the cache could key on.
     *
     * @var array<int, \stdClass>|null
     */
    protected static $resolved = null;

    /**
     * Every manager on this site, with what they manage.
     *
     * @return array<int, \stdClass> Keyed by manager user id:
     *         {userid, courseids, departments (values), participants (user ids)}.
     */
    public static function managers(): array {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $managers = [];

        foreach (self::capability_contexts() as $context) {
            $holders = get_users_by_capability($context, access::MANAGE, 'u.id', '', '', '', '', '', false);

            if (!$holders) {
                continue;
            }

            $courseids = self::courses_in($context);

            if (!$courseids) {
                continue;
            }

            foreach ($holders as $holder) {
                $uid = (int) $holder->id;

                if (!isset($managers[$uid])) {
                    $managers[$uid] = (object) [
                        'userid' => $uid,
                        'courseids' => [],
                        'departments' => [],
                        'participants' => [],
                    ];
                }

                foreach ($courseids as $courseid) {
                    $managers[$uid]->courseids[$courseid] = $courseid;
                }
            }
        }

        if (!$managers) {
            return self::$resolved = [];
        }

        $departments = self::departments_of(array_keys($managers));

        foreach ($managers as $uid => $manager) {
            $manager->courseids = array_values($manager->courseids);
            // A set, not a value: v1.x asserts one, later versions may not.
            $manager->departments = $departments[$uid] ?? [];
            $manager->participants = self::participants_of($manager->courseids);
        }

        return self::$resolved = $managers;
    }

    /**
     * Forget the resolved managers.
     *
     * For tests, which change role assignments and enrolments between
     * assertions inside one request.
     *
     * @return void
     */
    public static function reset(): void {
        self::$resolved = null;
    }

    /**
     * Which department each participant inherits, and from whom.
     *
     * @return array<int, \stdClass> Keyed by participant user id:
     *         {department (value), managerid}.
     */
    public static function membership(): array {
        $membership = [];

        foreach (self::managers() as $manager) {
            // A manager with no department of their own cannot place anyone.
            // Counted rather than ignored, see unplaceable() below: a silently
            // empty department is the failure nobody notices.
            if (!$manager->departments) {
                continue;
            }

            $department = reset($manager->departments);

            foreach ($manager->participants as $participantid) {
                // A manager never inherits from themselves, and the first
                // manager wins when two of them share a participant, so the
                // result does not depend on iteration order changing under us.
                if ($participantid === $manager->userid || isset($membership[$participantid])) {
                    continue;
                }

                $membership[$participantid] = (object) [
                    'department' => $department,
                    'managerid' => $manager->userid,
                ];
            }
        }

        return $membership;
    }

    /**
     * Managers who hold the capability but have no department of their own.
     *
     * Their people fall back to their own profile value, or to Unassigned. The
     * count is what turns that from silent into visible.
     *
     * @return array<int, int> Manager user ids.
     */
    public static function unplaceable(): array {
        $ids = [];

        foreach (self::managers() as $manager) {
            if (!$manager->departments) {
                $ids[] = $manager->userid;
            }
        }

        return $ids;
    }

    /**
     * The contexts worth asking about.
     *
     * Not every context the capability could be granted in: only the ones where
     * a role that grants it is actually assigned. On a site with 844 courses
     * the naive version is 844 get_users_by_capability calls per push, and the
     * answer for almost all of them is nobody. Two cheap queries cut that to
     * the handful of contexts a human has really touched.
     *
     * get_users_by_capability still decides who holds it, so overrides and
     * prohibits are resolved by Moodle rather than reimplemented here. This
     * only narrows where the question gets asked.
     *
     * @return array<int, \context>
     */
    protected static function capability_contexts(): array {
        global $DB;

        $roleids = array_keys(get_roles_with_capability(access::MANAGE, CAP_ALLOW));

        if (!$roleids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params['system'] = CONTEXT_SYSTEM;
        $params['coursecat'] = CONTEXT_COURSECAT;
        $params['course'] = CONTEXT_COURSE;

        // Only the three levels that resolve to a set of courses. An assignment
        // in a module or block context grants nothing this model can use.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.roleid {$insql}
                   AND ctx.contextlevel IN (:system, :coursecat, :course)";

        $contexts = [];

        foreach ($DB->get_fieldset_sql($sql, $params) as $contextid) {
            $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);

            if ($context) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * The courses a context covers.
     *
     * @param \context $context
     *
     * @return array<int, int>
     */
    protected static function courses_in(\context $context): array {
        global $DB;

        if ($context instanceof \context_course) {
            return [(int) $context->instanceid];
        }

        if ($context instanceof \context_coursecat) {
            // Subcategories included: a category grant covers everything under
            // it, which is what an administrator assigning it there expects.
            $categoryids = self::category_subtree((int) $context->instanceid);
            [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);

            return array_map('intval', $DB->get_fieldset_select('course', 'id', "category {$insql}", $params));
        }

        // System: every course on the site, which is the whole organisation.
        return array_map('intval', $DB->get_fieldset_select('course', 'id', 'id > 1'));
    }

    /**
     * A category and every category beneath it.
     *
     * @param int $categoryid
     *
     * @return array<int, int>
     */
    protected static function category_subtree(int $categoryid): array {
        global $DB;

        $ids = [$categoryid];
        $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path');

        if ($category) {
            // The path column already holds the ancestry, so descendants are a
            // prefix match rather than a recursive walk.
            foreach ($DB->get_records_select('course_categories', $DB->sql_like('path', ':path'),
                ['path' => $category->path . '/%'], '', 'id') as $child) {
                $ids[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * The enrolled users of a set of courses.
     *
     * @param array<int, int> $courseids
     *
     * @return array<int, int> User ids.
     */
    protected static function participants_of(array $courseids): array {
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
     * The mapped department value for a set of users.
     *
     * @param array<int, int> $userids
     *
     * @return array<int, array<int, string>> User id => department values.
     */
    protected static function departments_of(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        $shortname = org::fieldmap()['department'];
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['shortname'] = $shortname;

        $sql = "SELECT d.id, d.userid, d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE f.shortname = :shortname
                   AND d.userid {$insql}";

        $departments = [];

        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $value = trim((string) $row->data);

            if ($value !== '') {
                $departments[(int) $row->userid][] = $value;
            }
        }

        return $departments;
    }
}
