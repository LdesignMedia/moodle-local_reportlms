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
 * Put a person in a unit.
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
 * A searchable person picker, rather than a box to type a user id into.
 *
 * The id box was how somebody put the wrong person in a department and never
 * found out. A plain dropdown is no better past a few hundred people: the
 * smallest real site here has 13,134, and a select carrying all of them is
 * half a megabyte of markup and unusable with a mouse.
 *
 * Moodle's own autocomplete asks the server as you type, so it costs one
 * request per search and works the same at fifty people or fifty thousand.
 */
class place_person extends \moodleform {
    /**
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $units = $this->_customdata['units'] ?? [];

        $mform->addElement(
            'autocomplete',
            'userid',
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
        $mform->addRule('userid', null, 'required', null, 'client');

        $mform->addElement('select', 'nodeid', get_string('manage:unit', 'local_reportlms'), $units);

        $mform->addElement('hidden', 'action', 'place');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('add'));
    }
}
