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
 * Name the manager of a unit, by person or by role.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Either a person or a role, and the form says which by leaving the other empty.
 *
 * Naming a role means everyone holding it manages the unit, which is how a site
 * with staff turnover avoids editing the list every time somebody joins. A row
 * naming both would have two answers to "who does this mean", so the role wins
 * and that is decided in mapping::add_manager rather than here.
 */
class name_manager extends \moodleform {
    /**
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $units = $this->_customdata['units'] ?? [];

        $roles = $this->_customdata['roles'] ?? [];

        $mform->addElement('select', 'nodeid', get_string('manage:unit', 'local_reportlms'), $units);

        /*
         * manageruserid, not userid.
         *
         * The person form further down this page has an autocomplete too, and
         * Moodle names the rendered element after the field. Two fields called
         * userid on one page meant two elements with id="id_userid", and
         * enhanceField resolves by id: it found this one twice and enhanced the
         * other never, so the person picker rendered as a bare empty select and
         * looked to everybody like a missing dropdown.
         */
        $mform->addElement(
            'autocomplete',
            'manageruserid',
            get_string('manage:person', 'local_reportlms'),
            [],
            [
                'ajax' => 'core_user/form_user_selector',
                'multiple' => false,
                'valuehtmlcallback' => static function ($userid) {
                    $user = \core_user::get_user($userid, '*', MUST_EXIST);

                    return fullname($user);
                },
            ]
        );

        $mform->addElement('select', 'roleid', get_string('manage:orrole', 'local_reportlms'), $roles);

        $mform->addElement('hidden', 'action', 'addmanager');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('add'));
    }
}
