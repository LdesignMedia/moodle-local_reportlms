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
     * @param \context|null $context Defaults to the system context.
     *
     * @return void
     */
    public static function require_view(?\context $context = null): void {
        require_capability(self::VIEW, $context ?? \context_system::instance());
    }

    /**
     * The same question, asked rather than enforced.
     *
     * @param \context|null $context Defaults to the system context.
     *
     * @return bool
     */
    public static function can_view(?\context $context = null): bool {
        return has_capability(self::VIEW, $context ?? \context_system::instance());
    }

    /**
     * Whether this viewer sees the whole organisation rather than a subtree.
     *
     * Site administrators do too, without holding anything: Moodle grants them
     * every capability, so the check below already answers true for them.
     *
     * @param \context|null $context Defaults to the system context.
     *
     * @return bool
     */
    public static function can_view_all(?\context $context = null): bool {
        return has_capability(self::VIEWALL, $context ?? \context_system::instance());
    }
}
