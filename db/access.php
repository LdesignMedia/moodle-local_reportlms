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
 * Capabilities for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/*
 * Three capabilities, and the split between them is the whole access model.
 *
 * :view decides whether you may open the report at all. :manage decides whose
 * numbers you see once you are in, by naming you the direct manager of the
 * people in the courses you hold it on. :viewall lifts that scope to the whole
 * organisation.
 *
 * Site administrators are absent on purpose: Moodle short-circuits every
 * capability check for them, so listing them would be decoration.
 *
 * Only the manager archetype is granted by default. editingteacher is left out
 * deliberately rather than forgotten: whether a teacher should see their own
 * course's people is still open, and a site that wants it can grant it from the
 * role editor without waiting for a release.
 */
$capabilities = [

    // Open the report page, the people list and the export. Says nothing about
    // whose data you then see; that is :manage and :viewall below.
    'local/reportlms:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    /*
     * Marks the holder as the direct manager of the participants of the courses
     * this is held on, and nothing else. It opens no page of its own.
     *
     * Context inheritance is the point: granted in a category it covers every
     * course beneath it, and granted at system level it reaches every course on
     * the site and therefore every department, which is the same place
     * :viewall arrives at. That is left alone rather than capped, because
     * capping a system grant would contradict how contexts behave everywhere
     * else in Moodle.
     *
     * RISK_PERSONAL: holding this makes other people's training progress
     * visible, which is exactly the staff-monitoring surface.
     */
    'local/reportlms:manage' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    /*
     * The whole organisation, for HR, auditors and organisation managers.
     *
     * Named for what it does rather than for who holds it: it grants sight of
     * everything and control of nothing, following moodle/grade:viewall.
     */
    'local/reportlms:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
