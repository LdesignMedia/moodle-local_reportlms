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
 * Who is behind a figure on the dashboard.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportlms\local\access;
use local_reportlms\local\branding;
use local_reportlms\local\filter;
use local_reportlms\local\people;

require_login();
$context = context_system::instance();

// Same check as the dashboard page and the label resolver, in one place, so
// the three cannot drift apart.
access::require_view($context);

// Coded, exactly as the dashboard held them. This page is the only thing on
// either side that can turn them back into people.
$node = optional_param('node', '', PARAM_ALPHANUMEXT);
$course = optional_param('course', '', PARAM_ALPHANUMEXT);
$fn = optional_param('fn', '', PARAM_ALPHANUMEXT);

$pageparams = array_filter(['node' => $node, 'course' => $course, 'fn' => $fn]);
$pageurl = new moodle_url('/local/reportlms/people.php', $pageparams);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('people:title', 'local_reportlms'));
// Same reason as the dashboard page: the branded heading below is the heading.
$PAGE->set_heading('');

$filter = filter::from_codes($node, $course, $fn);
$rows = people::not_completed($filter);

echo $OUTPUT->header();

echo html_writer::div(
    html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading'])
    . html_writer::link(
        new moodle_url('/local/reportlms/view.php'),
        get_string('people:backtoreport', 'local_reportlms'),
        ['class' => 'btn btn-secondary']
    ),
    'local-reportlms-header'
);

echo html_writer::tag('h3', get_string('people:notcompleted', 'local_reportlms'));
echo html_writer::tag('p', get_string('people:scope', 'local_reportlms', $filter->describe()), ['class' => 'text-muted']);

if ($filter->unresolved) {
    // A code this site cannot place means the dashboard is showing something
    // built from data that has since changed here. Saying so is better than
    // quietly listing a wider group than the number that was clicked.
    echo $OUTPUT->notification(
        get_string('people:unresolved', 'local_reportlms'),
        \core\output\notification::NOTIFY_WARNING
    );
}

if (!$rows) {
    echo $OUTPUT->notification(
        get_string('people:none', 'local_reportlms'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

$exporturl = new moodle_url('/local/reportlms/export.php', $pageparams + ['sesskey' => sesskey()]);
echo html_writer::div(
    html_writer::link($exporturl, get_string('people:export', 'local_reportlms'), ['class' => 'btn btn-primary']),
    'mb-3'
);

if (count($rows) >= people::MAX_ROWS) {
    // Never silently truncate a compliance list.
    echo $OUTPUT->notification(
        get_string('people:truncated', 'local_reportlms', people::MAX_ROWS),
        \core\output\notification::NOTIFY_WARNING
    );
}

$table = new html_table();
$table->head = [
    get_string('people:col:name', 'local_reportlms'),
    get_string('people:col:email', 'local_reportlms'),
    get_string('people:col:course', 'local_reportlms'),
    get_string('people:col:enrolled', 'local_reportlms'),
    get_string('people:col:status', 'local_reportlms'),
];
$table->attributes['class'] = 'generaltable';

foreach ($rows as $row) {
    $table->data[] = [
        html_writer::link(new moodle_url('/user/profile.php', ['id' => $row->userid]), $row->fullname),
        $row->email,
        html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), $row->coursename),
        $row->enrolled_at ? userdate($row->enrolled_at, get_string('strftimedate', 'langconfig')) : '',
        get_string('people:status:' . $row->status, 'local_reportlms'),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
