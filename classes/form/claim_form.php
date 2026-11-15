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
 * The claim code field.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\form;

use local_reportlms\local\claim_code;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * One field and one button: the code from the Report LMS account page.
 */
class claim_form extends \moodleform {
    /**
     * Build the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'code', get_string('claim:code', 'local_reportlms'), [
            'size' => 16,
            'maxlength' => 64,
            'autocomplete' => 'off',
            'spellcheck' => 'false',
            'placeholder' => 'K7QF-29XM',
        ]);
        // PARAM_ALPHANUMEXT keeps letters, digits, dashes and underscores and
        // drops anything else, so a code pasted with a space in it still
        // arrives. claim_code::normalise() then drops the dashes and
        // underscores too.
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addRule('code', get_string('claim:invalid', 'local_reportlms'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('claim:submit', 'local_reportlms'));
    }

    /**
     * A code has to hold at least one letter or digit, and not too many.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $code = claim_code::normalise((string) ($data['code'] ?? ''));

        if ($code === '' || strlen($code) > claim_code::MAX_LENGTH) {
            $errors['code'] = get_string('claim:invalid', 'local_reportlms');
        }

        return $errors;
    }
}
