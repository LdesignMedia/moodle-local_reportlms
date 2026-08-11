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
 * Web service that connects this site to Report LMS.
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
 * Connect this site, asked for by the setup steps in the frame.
 *
 * The steps are served by Report LMS and rendered inside a frame on the report
 * page, so the press happens over there. It cannot post here directly: the
 * sesskey that guards a state change is this site's defence against exactly
 * that kind of cross-site request, and sending one to another origin would be
 * handing over the right to act as the administrator.
 *
 * So the frame asks, and this runs. Moodle's own AJAX endpoint mints and checks
 * the sesskey on the way in, from the administrator's session, and nothing
 * secret ever crosses an origin.
 *
 * Failures come back as a message rather than an exception, because the caller
 * is a panel in a frame that has to say what went wrong. A licence that has not
 * been issued yet is the ordinary case, not an error worth a stack trace.
 */
class connect extends external_api {
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
        require_capability('moodle/site:config', $context);

        /*
         * Connecting is the act that carries the reading with it.
         *
         * It used to be a step of its own, recorded here and then reported to
         * Report LMS on a second call that needed an API key. On a site that
         * had not connected yet there was no key, so nothing could be reported,
         * so the step never completed, so the site could never reach the button
         * that would have minted the key. The order the setup steps ask for was
         * the one order that could not work.
         *
         * Recorded before the attempt rather than after it. A connection that
         * fails on a licence still leaves the site holding a true record of who
         * read what, and the next attempt carries it.
         *
         * The capability above is the same one the recording always required:
         * the person who may connect the site is the person whose reading of
         * this is worth anything.
         */
        privacy_statement::record((int) $USER->id);

        try {
            (new connect_manager())->connect();
        } catch (\moodle_exception $exception) {
            return [
                'connected' => false,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'connected' => connect_manager::is_connected(),
            'error' => '',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'connected' => new external_value(PARAM_BOOL, 'Whether the site is now connected'),
            'error' => new external_value(PARAM_TEXT, 'Why it is not, in words the administrator can act on'),
        ]);
    }
}
