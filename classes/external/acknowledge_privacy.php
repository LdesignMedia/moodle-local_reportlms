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
 * Web service that records who read the data statement, on this site.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportlms\local\connect_manager;
use local_reportlms\local\privacy_statement;

defined('MOODLE_INTERNAL') || die();

/**
 * Record that an administrator has read what leaves this site.
 *
 * The setup steps are served by Report LMS, inside a frame on this page, so the
 * tick happens over there. The record is kept here, and that is the point: it
 * names a person and a moment, and sending that to Report LMS would be sending
 * exactly the kind of identifiable data the statement being acknowledged
 * promises never leaves. So the frame asks, and this site remembers.
 *
 * It is a note of due diligence, not consent. The site is the controller of its
 * own learners' data; it does not consent to its own processing. What this
 * answers is the later question of who signed the organisation up to it.
 */
class acknowledge_privacy extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);

        // The person who can connect the site is the person whose reading of
        // this matters. A capability that merely opens the report would let
        // anyone tick it on the administration's behalf.
        require_capability('moodle/site:config', $context);

        return [
            'acknowledged' => true,
            'timestamp' => privacy_statement::record((int) $USER->id),
            // Told to the caller so it knows whether reading this was the last
            // thing standing between the site and its report. A site connected
            // before the statement existed is held on the steps until now, and
            // the moment it is recorded the page should simply be the report.
            'connected' => connect_manager::is_connected(),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'acknowledged' => new external_value(PARAM_BOOL, 'Whether this site holds a record'),
            'timestamp' => new external_value(PARAM_INT, 'When it was first recorded'),
            'connected' => new external_value(PARAM_BOOL, 'Whether the site is already connected'),
        ]);
    }
}
