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
 * The Report LMS dashboard, embedded in this site.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportlms\local\access;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\launch;

require_login();
$context = context_system::instance();

// Administrators only, for now. The same check guards the label resolver and
// the people list this page drills into, so the four cannot drift apart.
access::require_view($context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportlms/view.php'));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('dashboard:title', 'local_reportlms'));
$PAGE->set_heading(get_string('dashboard:title', 'local_reportlms'));

echo $OUTPUT->header();

if (!connect_manager::is_connected()) {
    echo $OUTPUT->notification(
        get_string('dashboard:notconnected', 'local_reportlms'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->single_button(
        new moodle_url('/admin/settings.php', ['section' => 'local_reportlms']),
        get_string('dashboard:gotosettings', 'local_reportlms'),
        'get'
    );
    echo $OUTPUT->footer();
    exit;
}

// Back to the page the visitor actually came from, when that is a page on
// this site (get_local_referer never returns a foreign host, so this cannot
// redirect off-site). Never this page itself, or the button would go nowhere
// after a refresh. Bookmarks and direct links have no referer and fall back
// to wherever this site sends people.
$referer = get_local_referer(false);
if ($referer && strpos($referer, '/local/reportlms/view.php') === false) {
    $backurl = new moodle_url($referer);
} else {
    $home = get_home_page();
    if ($home == HOMEPAGE_MY) {
        $backurl = new moodle_url('/my/');
    } else if (defined('HOMEPAGE_MYCOURSES') && $home == HOMEPAGE_MYCOURSES) {
        $backurl = new moodle_url('/my/courses.php');
    } else {
        $backurl = new moodle_url('/');
    }
}

echo html_writer::div(
    html_writer::link(
        $backurl,
        get_string('dashboard:back', 'local_reportlms'),
        ['class' => 'btn btn-secondary']
    ),
    'mb-3'
);

// The token is minted per page load and is good for a few minutes only, so it
// is built here rather than cached anywhere.
echo html_writer::tag('iframe', '', [
    'src' => launch::embed_url(),
    'title' => get_string('dashboard:title', 'local_reportlms'),
    'class' => 'local-reportlms-frame',
    'style' => 'width:100%;min-height:80vh;border:0;border-radius:12px;',
    'loading' => 'lazy',
    // allow-scripts and allow-same-origin together are what let the frame talk
    // to this page at all. That channel is how the report gets its department
    // and function names, since Report LMS only ever holds the codes, so
    // tightening this would leave the dashboard showing hashes.
    'sandbox' => 'allow-scripts allow-same-origin allow-popups allow-downloads',
    'referrerpolicy' => 'no-referrer',
]);

// The frame asks this page to name the codes it is drawing, and to send the
// visitor to the people behind a figure they clicked. Both answers are built
// here, from this site's own data.
$PAGE->requires->js_call_amd('local_reportlms/label_bridge', 'init', [
    config::origin(),
    '.local-reportlms-frame',
    (new moodle_url('/local/reportlms/people.php'))->out(false),
]);

echo $OUTPUT->footer();
