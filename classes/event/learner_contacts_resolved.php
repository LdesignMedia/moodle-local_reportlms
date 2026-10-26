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
 * Event: somebody was handed a batch of learners' e-mail addresses and phone numbers.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\event;

/**
 * A viewer was given contact details for the dashboard's people export.
 *
 * The sibling of {@see learner_emails_resolved}, for the same reason: a list of
 * contact details leaving the site in one go is the kind of thing an
 * administrator should be able to look back on. Every address and phone number
 * handed over is one the viewer could already read in Moodle itself (that is
 * the rule in access::can_copy_emails() and access::visible_phone_fields()),
 * but reading them one profile at a time and receiving a thousand of them in
 * one answer are different acts.
 *
 * The log holds who asked, when, and how many addresses and how many phone
 * numbers came back. Never the addresses, never the numbers and never the
 * codes: a log entry is read by more people than the report is, and kept for
 * longer, so copying them into it would make the log the larger leak.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int emails: How many e-mail addresses were handed over.
 *      - int phones: How many phone numbers were handed over.
 * }
 */
class learner_contacts_resolved extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The event's name, as the logs show it.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:learnercontactsresolved', 'local_reportlms');
    }

    /**
     * What happened, for the logs.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' was given {$this->other['emails']} e-mail address(es) "
            . "and {$this->other['phones']} phone number(s) for the Report LMS dashboard.";
    }

    /**
     * The two counts are the only things this event must carry.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['emails', 'phones'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Nothing in other points at a database row, so nothing needs mapping on restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'emails' => self::NOT_MAPPED,
            'phones' => self::NOT_MAPPED,
        ];
    }
}
