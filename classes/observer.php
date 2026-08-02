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
 * Event observers for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\userstate;

/**
 * Marks the learner behind an event as changed.
 */
class observer {
    /**
     * Note that this event's learner needs sending again.
     *
     * One callback for every observed event, because the only thing that
     * differs between them is where the learner id sits.
     *
     * Never throws: a reporting plugin must not be able to break an enrolment
     * or a quiz submission. A miss here is picked up by the reconcile task.
     *
     * @param \core\event\base $event
     */
    public static function mark_learner_changed(\core\event\base $event): void {
        try {
            $userid = self::affected_user($event);

            if ($userid > 0) {
                userstate::mark_dirty($userid);
            }
        } catch (\Throwable $e) {
            debugging('local_reportlms could not record a change: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Which learner an event is about.
     *
     * relateduserid is the subject on enrolment, completion, grade and attempt
     * events, where userid is whoever caused it: a manager enrolling someone
     * must mark the learner, not the manager. On user_updated and user_deleted
     * there is no related user and the subject is objectid.
     *
     * @param \core\event\base $event
     * @return int
     */
    protected static function affected_user(\core\event\base $event): int {
        return (int) ($event->relateduserid ?: $event->objectid);
    }
}
