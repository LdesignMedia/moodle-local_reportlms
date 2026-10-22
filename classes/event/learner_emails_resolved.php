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
 * Event: somebody was handed a batch of learners' e-mail addresses.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\event;

/**
 * A viewer was given e-mail addresses for the dashboard's copy button.
 *
 * Logged because a list of addresses leaving the site in one go is the kind of
 * thing an administrator should be able to look back on. Every address handed
 * over is one the viewer could already read in Moodle itself (that is the rule
 * in access::can_copy_emails()), but reading them one profile at a time and
 * receiving a thousand of them in one answer are different acts, and only the
 * second one left no trace.
 *
 * The log holds who asked, when, and how many addresses came back. Never the
 * addresses and never the codes: a log entry is read by more people than the
 * report is, and kept for longer, so copying the addresses into it would make
 * the log the larger leak.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int count: How many e-mail addresses were handed over.
 * }
 */
class learner_emails_resolved extends \core\event\base {
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
        return get_string('event:learneremailsresolved', 'local_reportlms');
    }

    /**
     * What happened, for the logs.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' was given {$this->other['count']} e-mail address(es) "
            . "for the Report LMS dashboard.";
    }

    /**
     * The count is the one thing this event must carry.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['count'])) {
            throw new \coding_exception('The \'count\' value must be set in other.');
        }
    }

    /**
     * Nothing in other points at a database row, so nothing needs mapping on restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'count' => self::NOT_MAPPED,
        ];
    }
}
