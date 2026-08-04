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
 * Administrators only, for now. Every launch token currently claims the whole
 * organisation (see {@see launch::viewer}), so widening this before the org
 * tree drives the scope would hand every viewer everyone else's data. When the
 * manager capability from the org and permissions task lands, it lands here.
 */
class access {
    /**
     * Stop unless the current user may see the report.
     *
     * @param \context|null $context Defaults to the system context.
     *
     * @return void
     */
    public static function require_view(?\context $context = null): void {
        require_capability('moodle/site:config', $context ?? \context_system::instance());
    }

    /**
     * The same question, asked rather than enforced.
     *
     * @param \context|null $context Defaults to the system context.
     *
     * @return bool
     */
    public static function can_view(?\context $context = null): bool {
        return has_capability('moodle/site:config', $context ?? \context_system::instance());
    }
}
