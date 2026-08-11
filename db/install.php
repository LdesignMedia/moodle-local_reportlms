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
 * Install hook for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the plugin's profile fields on a fresh install.
 *
 * Install only, no upgrade step, and that is a product decision: the plugin
 * has never shipped to a customer, so there are no installed sites to
 * retrofit. If that ever changes, profile_fields::ensure() is idempotent by
 * design — wiring it into an upgrade savepoint is one line, not a rework.
 */
function xmldb_local_reportlms_install(): bool {
    \local_reportlms\local\profile_fields::ensure();

    return true;
}
