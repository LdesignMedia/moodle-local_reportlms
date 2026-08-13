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
 * The block cog's target: whatever admin surface this viewer may open.
 *
 * A site administrator lands on the plugin settings; a :manage holder, who
 * cannot open admin settings, lands on the mapping editor, their surface.
 * Nobody is shown a door that refuses them; anyone else gets the standard
 * no-permission page. Redirect-only GET, so no sesskey.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login(null, false);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/reportlms/admin.php'));
$PAGE->set_title(get_string('pluginname', 'local_reportlms'));

$destination = \local_reportlms\local\access::admin_destination();

if ($destination !== null) {
    redirect($destination);
}

require_capability(\local_reportlms\local\access::MANAGE, context_system::instance());
