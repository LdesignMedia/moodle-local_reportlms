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
 * Events that mean a learner's reported data changed.
 *
 * These are the fast path: a change is noticed the moment it happens and costs
 * one indexed write. They are deliberately not the only path, because plenty of
 * changes never raise an event: course restores, plugin upgrades, enrolment
 * sync tasks, gradebook recalculation and anything written straight to the
 * database. The reconcile task is what catches those.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callback = '\local_reportlms\observer::mark_learner_changed';

$observers = [
    // Profile and account changes: these feed the org tree and the attributes.
    // user_created matters on its own: a brand-new account raises no update,
    // no enrolment and no completion, so without it a new colleague is
    // invisible until the nightly sweep happens to notice them.
    ['eventname' => '\core\event\user_created', 'callback' => $callback],
    ['eventname' => '\core\event\user_updated', 'callback' => $callback],
    ['eventname' => '\core\event\user_deleted', 'callback' => $callback],

    // Enrolment, which is what decides whether a learner is reportable at all.
    ['eventname' => '\core\event\user_enrolment_created', 'callback' => $callback],
    ['eventname' => '\core\event\user_enrolment_updated', 'callback' => $callback],
    ['eventname' => '\core\event\user_enrolment_deleted', 'callback' => $callback],

    // Progress.
    ['eventname' => '\core\event\course_completed', 'callback' => $callback],
    ['eventname' => '\core\event\course_module_completion_updated', 'callback' => $callback],
    ['eventname' => '\core\event\user_graded', 'callback' => $callback],

    // Attempts. Registering an event whose class is absent is harmless: the
    // observer simply never fires.
    ['eventname' => '\mod_quiz\event\attempt_submitted', 'callback' => $callback],
    ['eventname' => '\mod_quiz\event\attempt_deleted', 'callback' => $callback],

    /*
     * Certificates, Moodle Workplace side.
     *
     * tool_certificate is the only certificate plugin either stack ships that
     * raises an event when a learner is issued one, so these are the only ones
     * there are to observe. Regeneration counts: it moves the expiry date, and
     * an expiry date is half of what the dashboard reports.
     *
     * mod_customcert, the Moodle LMS side, raises nothing at all on issue. Its
     * events cover templates and elements, which are authoring actions, not a
     * learner receiving anything. So a customcert issue is picked up by the
     * reconcile task rather than the moment it happens, and that is a property
     * of the plugin rather than something this observer list can fix.
     */
    ['eventname' => '\tool_certificate\event\certificate_issued', 'callback' => $callback],
    ['eventname' => '\tool_certificate\event\certificate_revoked', 'callback' => $callback],
    ['eventname' => '\tool_certificate\event\certificate_regenerated', 'callback' => $callback],
];
