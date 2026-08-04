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
 * Scheduled tasks for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        // The push itself. Every fifteen minutes by default; the cadence is
        // tuned per site in the scheduled task admin screen.
        'classname' => 'local_reportlms\task\push_batch',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        // Nightly, and at a randomised minute so a fleet of sites does not all
        // sweep at once. This only catches what the observers missed, so it
        // does not need to keep pace with the push.
        'classname' => 'local_reportlms\task\reconcile_userstate',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
