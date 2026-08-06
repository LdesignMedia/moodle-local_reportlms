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
use local_reportlms\local\branding;
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
// No Moodle heading: the page draws its own branded one below, and both would
// say the same thing twice in a row.
$PAGE->set_heading('');

echo $OUTPUT->header();

// The steps stay up until this site is both connected and has confirmed
// reading what leaves it. Report LMS refuses data from a site that has not, so
// showing the dashboard first would show a report that is never going to fill.
if (!connect_manager::is_connected() || !config::privacy_acknowledged()) {
    // The four steps, in the frame the dashboard would occupy. A notice saying
    // "not connected" told somebody what was wrong and nothing about what to do
    // next, which on a site nobody has set up yet is the only useful thing to
    // say.
    // Just the branded heading. There was a button here to the settings page,
    // which was the wrong way round: the steps below are the setup, and sending
    // somebody to settings first offered them a shortcut past the very thing
    // they had come to read.
    echo html_writer::div(
        html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading']),
        'local-reportlms-header'
    );

    echo html_writer::tag('iframe', '', [
        'src' => config::start_url(),
        'title' => get_string('dashboard:title', 'local_reportlms'),
        'class' => 'local-reportlms-frame',
        'style' => 'width:100%;min-height:70vh;border:0;border-radius:12px;',
        // No top-navigation permission: connecting happens through the
        // bridge below rather than by sending the page somewhere, so the frame
        // has no reason to be able to move the page around it.
        'sandbox' => 'allow-scripts allow-same-origin allow-popups',
        'referrerpolicy' => 'no-referrer',
    ]);

    // The frame polls until the site is licensed and connected, then asks this
    // page to reload, which is the moment the dashboard appears without anyone
    // being told to press anything.
    $PAGE->requires->js_call_amd('local_reportlms/label_bridge', 'init', [
        config::origin(),
        '.local-reportlms-frame',
        (new moodle_url('/local/reportlms/people.php'))->out(false),
    ]);

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

$actions = '';

// Administrators only. The settings page is where a site is connected and
// disconnected, which is not a manager's business even though the report is.
if (has_capability('moodle/site:config', $context)) {
    $actions .= html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_reportlms']),
        // i/settings, not t/cog: not every theme ships the latter, and a
        // missing pix renders as a broken image rather than as nothing.
        $OUTPUT->pix_icon('i/settings', get_string('dashboard:settings', 'local_reportlms')),
        ['class' => 'btn btn-secondary', 'title' => get_string('dashboard:settings', 'local_reportlms')]
    );
}

$actions .= html_writer::link(
    $backurl,
    get_string('dashboard:back', 'local_reportlms'),
    ['class' => 'btn btn-secondary']
);

echo html_writer::div(
    // The lockup is the page heading, so it is marked up as one rather than
    // sitting next to an empty h1.
    html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading'])
    . html_writer::div($actions, 'local-reportlms-actions'),
    'local-reportlms-header'
);

/*
 * A stale report explains itself.
 *
 * Above the dashboard rather than instead of it: the figures are real, they are
 * just old, and a manager who can see "as at 14 June" is better served than one
 * who can see nothing while somebody fixes a server. Hiding it outright would
 * reproduce the original failure with extra steps.
 */
$cronproblem = \local_reportlms\local\cron_health::message();

if ($cronproblem !== null) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('cron:stalereport', 'local_reportlms'))
        . ' ' . $cronproblem,
        'local-reportlms-stale alert alert-warning'
    );
}

// The token is minted per page load and is good for a few minutes only, so it
// is built here rather than cached anywhere.
echo html_writer::tag('iframe', '', [
    // Set by the reload that follows a connect, and by nothing else.
    'src' => launch::embed_url(optional_param('welcome', 0, PARAM_BOOL)),
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
