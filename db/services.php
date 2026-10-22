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
 * Web service definitions for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // AJAX only, and deliberately not exposed as a REST service: this exists
    // so the dashboard page can name the nodes it is drawing, not so an
    // integration can pull the org tree's labels out of the site.
    'local_reportlms_resolve_labels' => [
        'classname' => 'local_reportlms\external\resolve_labels',
        'methodname' => 'execute',
        'description' => 'Resolve org node and function codes to the labels this site holds for them.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reportlms:view',
    ],

    /*
     * Names for the coded learner ids on screen, and only for the people the
     * caller already manages.
     *
     * Guarded by :view like the label service, but the real boundary is not
     * this line. It is the candidate set the resolver builds from the viewer's
     * own participants: a code for anybody else is not in it and no name comes
     * back. See local\learner_names.
     */
    'local_reportlms_resolve_learners' => [
        'classname' => 'local_reportlms\external\resolve_learners',
        'methodname' => 'execute',
        'description' => 'Resolve coded learner ids to the names this site holds for them.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reportlms:view',
    ],

    /*
     * E-mail addresses for the same people, for the dashboard's copy button.
     *
     * Same candidate set as the names above, plus Moodle's own rule on who may
     * see addresses, asked in the system context inside the function. Not
     * listed as a capability here because it is not one: it is "Show user
     * identity" containing e-mail together with moodle/site:viewuseridentity.
     * See access::can_copy_emails().
     */
    'local_reportlms_resolve_learner_emails' => [
        'classname' => 'local_reportlms\external\resolve_learner_emails',
        'methodname' => 'execute',
        'description' => 'Resolve coded learner ids to the e-mail addresses this site holds for them.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reportlms:view',
    ],

    /*
     * There is no separate service for recording the data statement.
     *
     * There was, and it could not work in the order the setup steps ask for:
     * reading was step two, connecting step three, and reporting the reading
     * needed the API key that step three mints. So the reading is recorded by
     * the connect call below, which is the one deliberate act by the one person
     * whose reading of it counts.
     */

    // AJAX only. The setup steps run in a frame served by Report LMS and
    // cannot be handed a sesskey, so they ask this page to do it and Moodle's
    // own AJAX endpoint supplies the token from the administrator's session.
    'local_reportlms_connect' => [
        'classname' => 'local_reportlms\external\connect',
        'methodname' => 'execute',
        'description' => 'Connect this site to Report LMS, recording that the caller read the data statement.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
];
