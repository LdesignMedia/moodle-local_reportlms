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
 * Who can reach a report without anybody having said so on the mapping page.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Everybody granted local/reportlms:view somewhere on this site.
 *
 * What is left of the old manager resolver, which read this capability as a
 * statement about the organisation: whoever held it on a course was taken to
 * manage its participants, and those participants inherited their department.
 * That inference is gone. Being allowed to look at a report is a permission,
 * and running a department is a fact, and the plugin no longer guesses the
 * second from the first.
 *
 * The list is still worth having, for one screen. The register on the mapping
 * page exists to answer "who can see staff training data", and somebody granted
 * the capability through a role belongs on that list even though nobody named
 * them. They show up marked as holding it, with a Fix that names them properly.
 *
 * Nothing here decides what anybody sees. {@see access} does that, per request,
 * through Moodle's own capability checks.
 */
class capability_holders {
    /** @var array<int, int>|null Held for the life of the request. */
    protected static $holders = null;

    /**
     * User ids holding the view capability in any context that could matter.
     *
     * Site administrators are left out. They hold every capability everywhere,
     * so including them says nothing about this site and buries the people the
     * register is for.
     *
     * @return array<int, int> Keyed by user id, for isset().
     */
    public static function holders(): array {
        if (self::$holders !== null) {
            return self::$holders;
        }

        $holders = [];

        foreach (self::granting_contexts() as $context) {
            foreach (get_users_by_capability($context, access::VIEW, 'u.id', '', '', '', '', '', false) as $holder) {
                $uid = (int) $holder->id;

                if (is_siteadmin($uid)) {
                    continue;
                }

                $holders[$uid] = $uid;
            }
        }

        return self::$holders = $holders;
    }

    /**
     * Forget the resolved list.
     *
     * For tests, which change role assignments between assertions inside one
     * request.
     *
     * @return void
     */
    public static function reset(): void {
        self::$holders = null;
    }

    /**
     * The contexts worth asking about.
     *
     * Not every context the capability could be granted in: only the ones where
     * a role that grants it is actually assigned. On a site with 844 courses the
     * naive version is 844 get_users_by_capability calls, and the answer for
     * almost all of them is nobody. One cheap query cuts that to the handful of
     * contexts a person has really touched.
     *
     * get_users_by_capability still decides who holds it, so overrides and
     * prohibits are resolved by Moodle rather than reimplemented here. This only
     * narrows where the question gets asked.
     *
     * @return array<int, \context>
     */
    protected static function granting_contexts(): array {
        global $DB;

        $roleids = array_keys(get_roles_with_capability(access::VIEW, CAP_ALLOW));

        if (!$roleids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        $params['system'] = CONTEXT_SYSTEM;
        $params['coursecat'] = CONTEXT_COURSECAT;
        $params['course'] = CONTEXT_COURSE;

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
}
