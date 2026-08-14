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
 * Uninstall hook for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Undo the one mutation the plugin made to shared site state.
 *
 * The profile fields and their data stay: they hold the customer's
 * organisation records, which are not the plugin's to delete. The lock is
 * different: the plugin set it, and a lock nobody can explain on a field the
 * customer owns is exactly the debris an uninstall exists to prevent.
 */
function xmldb_local_reportlms_uninstall(): bool {
    global $DB;

    $locked = (string) get_config('local_reportlms', 'lockedfield');

    if ($locked !== '') {
        $field = $DB->get_record('user_info_field', ['shortname' => $locked], 'id, locked');
        if ($field && !empty($field->locked)) {
            $DB->set_field('user_info_field', 'locked', 0, ['id' => $field->id]);
        }
    }

    return true;
}
