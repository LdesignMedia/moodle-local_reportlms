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
 * Report LMS connect starter.
 *
 * A bare action endpoint: it does not render a page. Nothing in the plugin
 * links here any more, because connecting is now done from the setup steps in
 * the report frame, through the local_reportlms_connect web service. This stays
 * as the plain, scriptless way to do the same thing.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_reportlms\local\connect_manager;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportlms/connect.php'));

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_reportlms']);

require_sesskey();

try {
    (new connect_manager())->connect();
} catch (moodle_exception $exception) {
    // The message can carry words from Report LMS, and a notification prints HTML.
    redirect($returnurl, s($exception->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
}

// Confirms the contact email with Report LMS straight away. Never fails the connect.
\local_reportlms\local\contact_email::send_now();

redirect(
    $returnurl,
    get_string('connect:success', 'local_reportlms'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
