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
 * Who may look at the report, in one place.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * The single access check behind every surface that shows report data.
 *
 * The dashboard page, the label resolver it calls, the people list it drills
 * into and the export all have to agree on who is allowed in. Written out four
 * times they would eventually disagree, and the one that drifts open is the
 * one nobody notices.
 *
 * Getting in and seeing whose data are two different questions. This class only
 * answers the first. What a viewer may then see is decided by
 * {@see manager_resolver}, carried in the launch token's scope, and enforced by
 * the SaaS on every query.
 *
 * :view answers both halves now: holding it on a course makes you that course's
 * reporting manager and lets you in. :manage answers neither, and opens the
 * editor instead. See db/access.php for why they were split that way.
 */
class access {
    /** @var string Names the holder the reporting manager of their courses' participants, and opens the report. */
    public const VIEW = 'local/reportlms:view';

    /** @var string Opens the mapping editor. Shows the holder nobody's data. */
    public const MANAGE = 'local/reportlms:manage';

    /** @var string Lifts the scope to the whole organisation. */
    public const VIEWALL = 'local/reportlms:viewall';

    /** @var string May decide which dashboard cards this whole site shows. */
    public const CONFIGURE = 'local/reportlms:configure';

    /**
     * Stop unless the current user may see the report.
     *
     * @param \context|null $context A specific context to ask about, or null
     *                               for "anywhere on this site". See holds().
     *
     * @return void
     */
    public static function require_view(?\context $context = null): void {
        if ($context !== null) {
            require_capability(self::VIEW, $context);

            return;
        }

        if (!self::holds(self::VIEW)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                self::VIEW,
                'nopermissions',
                ''
            );
        }
    }

    /**
     * The same question, asked rather than enforced.
     *
     * @param \context|null $context A specific context, or null for anywhere.
     *
     * @return bool
     */
    public static function can_view(?\context $context = null): bool {
        return $context !== null
            ? has_capability(self::VIEW, $context)
            : self::holds(self::VIEW);
    }

    /**
     * Whether this user holds a capability anywhere on the site.
     *
     * The distinction this exists for, and it is not a subtlety: these
     * capabilities default to the manager archetype, and Moodle's manager role
     * is normally assigned at a category or a course, not at the system. Asking
     * for the capability at the system context therefore answers "no" for
     * exactly the people the product was built for. A manager of one course had
     * every capability they needed, was correctly identified as a manager by
     * manager_resolver, and was turned away at the door.
     *
     * Asking about the contexts the user actually has a role in, rather than
     * walking every context on the site, keeps this to one small query plus a
     * handful of ordinary capability checks. Those checks are what honour role
     * overrides and prohibits, so nothing is being second-guessed here.
     *
     * A grant that exists only as an override somewhere the user holds no role
     * at all is not found. That is a shape nobody has, and finding it would
     * mean walking the whole context tree on every page load.
     *
     * @param string $capability
     *
     * @return bool
     */
    protected static function holds(string $capability): bool {
        global $DB, $USER;

        // The common case, and the one that covers site administrators, whom
        // Moodle grants everything without any assignment at all.
        if (has_capability($capability, \context_system::instance())) {
            return true;
        }

        /*
         * Named in the mapping editor, which is the other way in.
         *
         * Being a manager is data rather than a permission: somebody said "Tom
         * runs Sloterdijk Groep E" in the editor, and that statement is what
         * opens the report for Tom. No role assignment, nothing for the
         * customer to wire up, and no capability for a training coordinator to
         * learn about.
         *
         * Only :view. Naming somebody a manager says whose numbers they see,
         * not that they may rewrite the organisation, and :manage is the
         * capability that hands the second one out.
         */
        if ($capability === self::VIEW
                && isloggedin()
                && !isguestuser()
                && mapping::managed_nodes((int) $USER->id) !== []) {
            return true;
        }

        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $contextids = $DB->get_fieldset_sql(
            'SELECT DISTINCT contextid FROM {role_assignments} WHERE userid = ?',
            [(int) $USER->id]
        );

        foreach ($contextids as $contextid) {
            $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);

            if ($context && has_capability($capability, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stop unless the current user may edit the organisation mapping.
     *
     * System context only: the editor edits the whole tree, so there is no
     * smaller place this could sensibly be held.
     *
     * @return void
     */
    public static function require_manage(): void {
        require_capability(self::MANAGE, \context_system::instance());
    }

    /**
     * The same question, asked rather than enforced.
     *
     * @return bool
     */
    public static function can_manage(): bool {
        return has_capability(self::MANAGE, \context_system::instance());
    }

    /**
     * Whether this viewer may configure the site-wide dashboard deck.
     *
     * Site administrators may, without holding anything: Moodle grants them
     * every capability. System context only, like :manage — the deck belongs
     * to the whole site, so authority over it granted "inside one category"
     * would not mean anything.
     *
     * @return bool
     */
    public static function can_configure(): bool {
        return has_capability(self::CONFIGURE, \context_system::instance());
    }

    /**
     * Whether this viewer sees the whole organisation rather than a subtree.
     *
     * Site administrators do too, without holding anything: Moodle grants them
     * every capability, so the check below already answers true for them.
     *
     * @param \context|null $context Defaults to the system context, which is
     *                               the only place this capability means what
     *                               its name says.
     *
     * @return bool
     */
    public static function can_view_all(?\context $context = null): bool {
        // System context, not "anywhere", and the asymmetry with can_view() is
        // deliberate. :view asks whether you may open the page at all, which a
        // manager of one course legitimately may. :viewall means the whole
        // organisation, and holding that inside one category would have to mean
        // "everything, from inside one corner of it". Resolving that ambiguity
        // towards everything is the unsafe direction, so it is only honoured
        // where it unambiguously covers the site.
        return has_capability(self::VIEWALL, $context ?? \context_system::instance());
    }

    /**
     * Narrow a list of coded ids to the ones this viewer may have named.
     *
     * The label service turns codes into department names, and until now it
     * turned any code into any name for anybody who could open the report. A
     * manager of one department could ask it about every other department on
     * the site and be told what they are called. Small, because a department
     * name is not somebody's salary, and real, because the whole promise is
     * that a manager sees their own people and nothing else.
     *
     * It matters more than it did. Once learner names resolve through the same
     * service, "any code" stops meaning "any department" and starts meaning
     * "any employee", so the filter has to exist before that arrives rather
     * than alongside it.
     *
     * What a scoped viewer may name:
     *
     * - the nodes in their own scope
     * - the sector above them, because a department drawn under an unnamed
     *   parent is a picker nobody can use
     * - functions, which are job titles rather than org units, are offered as
     *   a filter to every viewer, and describe nobody in particular
     *
     * @param array<int, string> $codes
     *
     * @return array<int, string> The subset that may be resolved.
     */
    public static function visible_codes(array $codes): array {
        if (self::can_view_all()) {
            return $codes;
        }

        $nameable = launch::viewer_nameable();

        if (!$nameable) {
            return [];
        }

        $allowed = array_flip($nameable);
        $map = label_map::all();

        return array_values(array_filter($codes, static function (string $code) use ($allowed, $map): bool {
            return isset($allowed[$code]) || (($map[$code]['kind'] ?? null) === 'function');
        }));
    }
}
