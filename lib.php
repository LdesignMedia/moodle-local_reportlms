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
 * Library callbacks for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * An org field mapping changed: make the change actually travel.
 *
 * The state hash alone is not enough. The org section only ships inside a
 * push, and a push with an empty dirty queue returns before building
 * anything, so on a quiet site a re-map would propagate nothing at all.
 * Marking everyone dirty forces the next push to carry the tree rebuilt from
 * the newly mapped field; the label cache would otherwise serve the old
 * names for up to its TTL; and the department lock has to follow the mapping
 * the moment it moves, not whenever a push next happens to run.
 */
function local_reportlms_orgfield_updated(): void {
    \local_reportlms\local\userstate::mark_all_dirty();
    \local_reportlms\local\label_map::purge();
    \local_reportlms\local\collector\org::lock_department_field();
}

/**
 * Per-viewer CSS for the stored dashboard block.
 *
 * The legacy half of the pair in classes/hook/: this one covers
 * Moodle 3.9-4.2; on 4.3+ the registered hook runs instead and core skips
 * this function, so nothing ever runs twice.
 *
 * @return string
 */
function local_reportlms_before_standard_html_head(): string {
    return \local_reportlms\local\block_visibility::css();
}
