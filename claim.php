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
 * Send a claim code to Report LMS.
 *
 * Its own page and not a field on the settings page: that page is one big
 * settings form, and a second form cannot sit inside it. The settings page
 * links here with a button while the site is connected.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_reportlms\form\claim_form;
use local_reportlms\local\claim_code;
use local_reportlms\local\connect_manager;

// Logs in, checks moodle/site:config and sets up the admin page.
admin_externalpage_setup('local_reportlms_claim');
// Said again here, because this page acts for the whole site.
require_capability('moodle/site:config', context_system::instance());

$pageurl = new moodle_url('/local/reportlms/claim.php');
$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_reportlms']);

// The form carries the sesskey and checks it on submit.
$form = new claim_form($pageurl);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $result = claim_code::send((string) $data->code);

    // The words come from Report LMS, and a notification prints its message
    // as HTML, so they are escaped here. A refusal comes back to this page,
    // so the administrator can try again straight away.
    redirect(
        $result['success'] ? $returnurl : $pageurl,
        s($result['message']),
        null,
        $result['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('claim:title', 'local_reportlms'));

if (!connect_manager::is_connected()) {
    echo $OUTPUT->notification(
        get_string('claim:notconnected', 'local_reportlms'),
        \core\output\notification::NOTIFY_WARNING
    );
    echo $OUTPUT->continue_button($returnurl);
} else {
    echo html_writer::tag('p', get_string('claim:intro', 'local_reportlms'));
    $form->display();
}

echo $OUTPUT->footer();
