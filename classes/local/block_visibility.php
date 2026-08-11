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
 * Per-viewer visibility for the stored dashboard block.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\org;

/**
 * The block is a stored core HTML block: identical markup for every viewer,
 * so it cannot decide per viewer what to show. This is the per-request lever
 * it does not have — CSS in the page head, computed from who is looking and
 * whether the site is connected.
 *
 * One rule underneath: you see the block only if clicking its button leads
 * somewhere that will let you in. Configurers always (it is their setup entry
 * before the connection exists); report viewers once the site is connected;
 * everybody else never. The cog follows the ability to open an admin surface.
 *
 * Hiding is cosmetic on purpose. The report page, the settings page and the
 * mapping editor all enforce server-side; this only stops the plugin showing
 * people a door that is locked for them.
 */
class block_visibility {

    /**
     * The <style> block for the current page and viewer, or ''.
     *
     * Runs on every page render, so cost is watched from the first line:
     * the pagetype gate exits before any capability or config work on every
     * page the block cannot exist on, and the remaining ladder is ordered
     * cheapest first — the expensive can_view() walk only ever runs for a
     * logged-in non-admin on a connected site.
     *
     * !important on both rules: this style lands in the head before the
     * theme stylesheet, and at equal specificity the later sheet would win.
     */
    public static function css(): string {
        global $CFG, $PAGE;

        try {
            // The legacy callback path is guarded by core against running
            // during install and upgrade; the hooks path is not, and half an
            // upgrade in, our tables and capabilities may not exist yet.
            if (during_initial_install() || !empty($CFG->upgraderunning)) {
                return '';
            }

            if (!in_array($PAGE->pagetype, dashboard_block::PAGE_TYPES, true)) {
                return '';
            }

            $hidecog = '<style>.local-reportlms-cog { display: none !important; }</style>';
            // Same selector styles.css already uses for this block, so the
            // two files cannot drift onto different elements.
            $hideall = '<style>'
                . '.block:has(.local-reportlms-block) { display: none !important; } '
                . '.local-reportlms-cog { display: none !important; }'
                . '</style>';

            if (!isloggedin() || isguestuser()) {
                return $hideall;
            }

            // Anyone with an admin surface to land on sees everything —
            // the same test admin.php routes by, so the cog is only ever
            // shown to somebody its target will admit.
            if (access::admin_destination() !== null) {
                return '';
            }

            if (connect_manager::is_connected() && access::can_view()) {
                return $hidecog;
            }

            return $hideall;
        } catch (\Throwable $e) {
            // A cosmetic feature must never take a page down with it.
            return '';
        }
    }
}
