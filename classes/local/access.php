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
 */
class access {
    /** @var string Opens the report at all. */
    public const VIEW = 'local/reportlms:view';

    /** @var string Names the holder the direct manager of their courses' participants. */
    public const MANAGE = 'local/reportlms:manage';

    /** @var string Lifts the scope to the whole organisation. */
    public const VIEWALL = 'local/reportlms:viewall';

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
     * Stop unless the current user's scope covers this selection.
     *
     * The dashboard's filters are already narrowed and the SaaS refuses a cell
     * outside the scope, but the drill-through arrives here as a plain URL. A
     * manager who edits the node in the address bar would otherwise read
     * another department's people straight out of Moodle, which is the one
     * place the names actually live.
     *
     * @param string $nodecode Coded org node, empty for no node filter.
     *
     * @return void
     */
    public static function require_scope(string $nodecode): void {
        if (self::can_view_all()) {
            return;
        }

        $viewer = launch::viewer_scope();

        // No scope at all: a viewer who manages nothing has nothing to drill
        // into, whatever the URL says.
        if (!$viewer) {
            throw new \moodle_exception('error:outsidescope', 'local_reportlms');
        }

        // An unfiltered list would be every department this site has.
        if ($nodecode === '' || !in_array($nodecode, $viewer, true)) {
            throw new \moodle_exception('error:outsidescope', 'local_reportlms');
        }
    }
}
