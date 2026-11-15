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
 * Adds or removes the Dashboard block that links to the report.
 *
 * A bare action endpoint: the buttons on the plugin settings page send the
 * admin here and the page they return to shows the new state.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/blocklib.php');

use local_reportlms\local\dashboard_block;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();

$action = required_param('action', PARAM_ALPHA);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reportlms/block.php'));

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_reportlms']);

try {
    if ($action === 'add') {
        dashboard_block::add();
        $message = get_string('block:added', 'local_reportlms');
    } else {
        dashboard_block::remove();
        $message = get_string('block:removed', 'local_reportlms');
    }
} catch (moodle_exception $exception) {
    // A notification prints HTML, and the message is plain text.
    redirect($returnurl, s($exception->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
}

redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
