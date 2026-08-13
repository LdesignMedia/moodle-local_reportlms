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
        $members = $this->_customdata['members'] ?? [];

        /*
         * The people in this unit, already selected.
         *
         * It was a single-select for adding one person, which made the form an
         * add box on a page whose whole job is to show what is true. A form that
         * cannot show what is already there is not an editor, and somebody
         * opening a unit reasonably expects to see its people in the control
         * that changes them.
         *
         * So it is the membership: what is selected is who is in the unit.
         * Adding a name puts them in, taking one out takes them out, and saving
         * with nothing touched changes nothing.
         */
        $mform->addElement(
            'autocomplete',
            'userid',
            get_string('manage:person', 'local_reportlms'),
            [],
            [
                'ajax' => 'core_user/form_user_selector',
                'multiple' => true,
                'valuehtmlcallback' => static function ($userid) {
                    // MUST_EXIST would fatal the whole page for one deleted
                    // account still named in a unit, which is exactly when
                    // somebody needs this page to load.
                    $user = \core_user::get_user($userid);

                    return $user ? fullname($user) : (string) $userid;
                },
            ]
        );
        /*
         * Or everybody on some courses, which is the thing an administrator
         * actually knows.
         *
         * Naming people one at a time is honest and unusable for a real
         * department, which is why that list stayed empty on every
         * connected site. "Everyone on these courses" is one sentence and it
         * covers four hundred people.
         *
         * The options are preloaded rather than searched over AJAX. Measured on
         * the connected sites: 70, 7 and 908 courses, and Moodle's autocomplete
         * filters a preloaded list in the browser. A site with tens of
         * thousands of courses would need a search endpoint; none is near that,
         * and building one now would be building for a number nobody has.
         */
        $mform->addElement(
            'autocomplete',
            'courseids',
            get_string('manage:fromcourses', 'local_reportlms'),
            $this->_customdata['courses'] ?? [],
            ['multiple' => true]
        );

        /*
         * A whole branch of the course tree.
         *
         * "Everyone under Sales" is one sentence and covers the courses filed
         * under it as well, which is what somebody means by it. Preloaded:
         * measured at 5 and 175 categories on the connected sites.
         */
        $mform->addElement(
            'autocomplete',
            'categoryids',
            get_string('manage:fromcategories', 'local_reportlms'),
            $this->_customdata['categories'] ?? [],
            ['multiple' => true]
        );

        /*
         * A cohort, where a site already keeps the list this page is trying to
         * rebuild. Offered only when the site has any, because an empty picker
         * is a question with no answers.
         */
        if (!empty($this->_customdata['cohorts'])) {
            $mform->addElement(
                'autocomplete',
                'cohortids',
                get_string('manage:fromcohorts', 'local_reportlms'),
                $this->_customdata['cohorts'],
                ['multiple' => true]
            );
        }

        /*
         * A group: the narrowest thing a site can point at.
         *
         * Many sites already keep a team as a group inside a course that three
         * other teams also take, which is precisely the shape no course or
         * category can express.
         *
         * Offered only when the site has few enough to list. One connected site
         * has 32,024 groups, and a picker holding them would be megabytes of
         * markup and an unusable browser. That site is told to point at the
         * course instead, which is true and actionable, rather than being given
         * a control that hangs.
         */
        if (!empty($this->_customdata['groups'])) {
            $mform->addElement(
                'autocomplete',
                'groupids',
                get_string('manage:fromgroups', 'local_reportlms'),
                $this->_customdata['groups'],
                ['multiple' => true]
            );
        } else if (!empty($this->_customdata['toomanygroups'])) {
            $mform->addElement(
                'static',
                'groupnote',
                get_string('manage:fromgroups', 'local_reportlms'),
                get_string('manage:toomanygroups', 'local_reportlms', $this->_customdata['toomanygroups'])
            );
        }

        /*
         * A profile field value, the one source that needs no course at all.
         *
         * A department does not have to be enrolled in anything to exist, and on
         * a site with good HR data this is the whole tree in one picker.
         */
        if (!empty($this->_customdata['profilevalues'])) {
            $mform->addElement(
                'autocomplete',
                'profilevalues',
                get_string('manage:fromprofile', 'local_reportlms'),
                $this->_customdata['profilevalues'],
                ['multiple' => true]
            );
        }

        /*
         * The unit, when it is not already known.
         *
         * This form is reached from a unit's own page, where asking which unit
         * would be asking something the page already answered. It used to sit at
         * the bottom of the editor with a dropdown, which made choosing people
         * and choosing where they go two answers on one form, three sections
         * away from the unit itself.
         */
        if (!empty($this->_customdata['nodeid'])) {
            $mform->addElement('hidden', 'nodeid', $this->_customdata['nodeid']);
            $mform->setType('nodeid', PARAM_INT);
            $mform->setDefault('userid', $members);
        } else {
            $mform->addElement('select', 'nodeid', get_string('manage:unit', 'local_reportlms'), $units);
        }

        $mform->addElement('hidden', 'action', 'place');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * One of the two, not neither.
     *
     * Person was required until courses existed beside it. Requiring it still
     * would mean naming somebody in order to add four hundred people, and
     * requiring neither would mean a button that silently does nothing.
     *
     * @param array $data
     * @param array $files
     *
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $named = !empty($data['userid'])
            || !empty($data['courseids'])
            || !empty($data['categoryids'])
            || !empty($data['cohortids'])
            || !empty($data['groupids'])
            || !empty($data['profilevalues']);

        if (!$named) {
            $errors['userid'] = get_string('manage:pickoneorother', 'local_reportlms');
        }

        return $errors;
    }
}
