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
 * The not-completed list, as a file.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use local_reportlms\local\access;
use local_reportlms\local\filter;
use local_reportlms\local\people;

require_login();
$context = context_system::instance();
access::require_view($context);

// A download of everyone's name and address in a scope is worth a token: it
// stops a link in an email from pulling the list on an admin's behalf.
require_sesskey();

$node = optional_param('node', '', PARAM_ALPHANUMEXT);
$course = optional_param('course', '', PARAM_ALPHANUMEXT);
$fn = optional_param('fn', '', PARAM_ALPHANUMEXT);

// The dashboard's filters are narrowed and the SaaS refuses a cell outside the
// scope, but this page arrives as a plain URL, and it is where the names live.
access::require_scope($node);

$filter = filter::from_codes($node, $course, $fn);
$rows = people::not_completed($filter);

// Built here and downloaded from here. The file never passes through Report
// LMS, which is the only reason it can carry names at all.
$export = new csv_export_writer();
$export->set_filename('reportlms-not-completed');

$export->add_data([
    get_string('people:col:name', 'local_reportlms'),
    get_string('people:col:email', 'local_reportlms'),
    get_string('people:col:course', 'local_reportlms'),
    get_string('people:col:enrolled', 'local_reportlms'),
    get_string('people:col:status', 'local_reportlms'),
]);

foreach ($rows as $row) {
    $export->add_data([
        $row->fullname,
        $row->email,
        $row->coursename,
        $row->enrolled_at ? userdate($row->enrolled_at, get_string('strftimedate', 'langconfig')) : '',
        get_string('people:status:' . $row->status, 'local_reportlms'),
    ]);
}

$export->download_file();
