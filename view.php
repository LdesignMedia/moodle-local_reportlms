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

// Asked without a context, which means "anywhere on this site". Moodle's
// manager role is normally assigned at a category or a course, so pinning this
// to the system context turned away exactly the people the report is for. The
// same check guards the label resolver, the people list and the export, so the
// four cannot drift apart.
access::require_view();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportlms/view.php'));
// Embedded, so the page reads as a full-screen app: no Moodle nav, blocks or
// footer, only the slim strip below. The page stays load-bearing (token mint,
// name bridge, capability check); it just becomes invisible. AMD still lands:
// embedded renders standard_end_of_body_html.
$PAGE->set_pagelayout('embedded');
$PAGE->add_body_class('local-reportlms-fullpage');
$PAGE->add_body_class('local-reportlms-branded');
$PAGE->set_title(get_string('dashboard:title', 'local_reportlms'));
// No Moodle heading: the page draws its own branded one below, and both would
// say the same thing twice in a row.
$PAGE->set_heading('');

// Back to the page the visitor came from, when that is on this site
// (get_local_referer never returns a foreign host). Never this page itself, or
// the button would go nowhere after a refresh. Built before the branches
// split: with Moodle's navigation gone, this is the only exit either one has.
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

$backbutton = html_writer::link(
    $backurl,
    get_string('dashboard:back', 'local_reportlms'),
    ['class' => 'btn local-reportlms-btn']
);

echo $OUTPUT->header();

// The steps stay up until this site is both connected and has confirmed
// reading what leaves it. Report LMS refuses data from a site that has not, so
// showing the dashboard first would show a report that is never going to fill.
if (!connect_manager::is_connected() || !config::privacy_acknowledged()) {
    // The four steps, in the frame the dashboard would occupy. A notice saying
    // "not connected" told somebody what was wrong and nothing about what to do
    // next, which on a site nobody has set up yet is the only useful thing to
    // say.
    // The branded heading and the way out. No settings cog on purpose: the
    // steps below are the setup, and a settings link would be a shortcut past
    // the statement they came to read.
    // No role="main": the embedded layout's own wrapper is the main landmark.
    echo html_writer::start_div('local-reportlms-page');
    echo html_writer::div(
        html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading'])
        . html_writer::div($backbutton, 'local-reportlms-actions'),
        'local-reportlms-header'
    );

    echo html_writer::tag('iframe', '', [
        'src' => config::start_url(),
        'title' => get_string('dashboard:title', 'local_reportlms'),
        'class' => 'local-reportlms-frame',
        // No top-navigation permission: connecting happens through the
        // bridge below rather than by sending the page somewhere, so the frame
        // has no reason to be able to move the page around it.
        'sandbox' => 'allow-scripts allow-same-origin allow-popups',
        'referrerpolicy' => 'no-referrer',
    ]);
    echo html_writer::end_div();

    // The frame polls until the site is licensed and connected, then asks this
    // page to reload, which is the moment the dashboard appears without anyone
    // being told to press anything.
    $PAGE->requires->js_call_amd('local_reportlms/bridge_loader', 'init', [
        config::origin(),
        '.local-reportlms-frame',
        (int) get_config('local_reportlms', 'version'),
        config::PLATFORM,
    ]);

    echo $OUTPUT->footer();
    exit;
}

$actions = '';

// The connection internals, one click from the report rather than a trip
// through Site administration. Only on the dashboard: the setup steps above
// have no settings link on purpose.
$advanced = access::advanced_settings_url();

if ($advanced !== null) {
    $actions .= html_writer::link(
        $advanced,
        get_string('settings:advancedbutton', 'local_reportlms'),
        ['class' => 'btn local-reportlms-btn']
    );
}

/*
 * The cog goes where the block's cog goes: the mapping editor.
 *
 * One rule, in access::admin_destination(), so the two cogs cannot come to
 * different conclusions about where a person should land. It used to point at
 * the plugin settings for administrators only, which made the organisation
 * editor reachable from the block and not from the report standing in front of
 * it.
 */
$destination = access::admin_destination();

if ($destination !== null) {
    $actions .= html_writer::link(
        $destination,
        // i/settings, not t/cog: not every theme ships the latter, and a
        // missing pix renders as a broken image rather than as nothing.
        $OUTPUT->pix_icon('i/settings', get_string('dashboard:manage', 'local_reportlms')),
        [
            'class' => 'btn local-reportlms-btn local-reportlms-btn-icon',
            'title' => get_string('dashboard:manage', 'local_reportlms'),
        ]
    );
}

$actions .= $backbutton;

// No role="main": the embedded layout's own wrapper is the main landmark.
echo html_writer::start_div('local-reportlms-page');
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
    'loading' => 'lazy',
    // allow-scripts and allow-same-origin together are what let the frame talk
    // to this page at all. That channel is how the report gets its department
    // and function names, since Report LMS only ever holds the codes, so
    // tightening this would leave the dashboard showing hashes.
    'sandbox' => 'allow-scripts allow-same-origin allow-popups allow-downloads',
    /*
     * The copy-addresses button of a Report LMS from before the people export
     * writes to the clipboard from inside the frame, and a browser refuses that
     * to a cross-origin frame unless the page embedding it says so. The export
     * that replaced it only downloads a file, so this can go once no Report LMS
     * that copies is left. Only this frame: the setup steps above have nothing
     * to copy, so they are not given the permission.
     */
    'allow' => 'clipboard-write',
    'referrerpolicy' => 'no-referrer',
]);

echo html_writer::end_div();

// The frame asks this page to name the codes it is drawing, and to send the
// visitor to the people behind a figure they clicked. Both answers are built
// here, from this site's own data.
$PAGE->requires->js_call_amd('local_reportlms/bridge_loader', 'init', [
    config::origin(),
    '.local-reportlms-frame',
    (int) get_config('local_reportlms', 'version'),
    config::PLATFORM,
]);

echo $OUTPUT->footer();
