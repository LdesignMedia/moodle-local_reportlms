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
 * Three capabilities, and each one now does exactly one job.
 *
 * :view    you are the reporting manager of the participants of these courses,
 *          and you see their report.
 * :manage  you may define who manages whom, in the mapping editor. It shows you
 *          nobody's data by itself.
 * :viewall you see the whole organisation and manage nobody. HR, auditors.
 *
 * :view used to mean only "may open the page", with :manage deciding whose
 * numbers you saw. Two capabilities that had to be granted together were not
 * two capabilities, they were one with a trap: granting :view alone put a
 * manager in front of an empty dashboard with nothing on screen to explain why.
 *
 * Site administrators are absent on purpose: Moodle short-circuits every
 * capability check for them, so listing them would be decoration.
 *
 * NO ARCHETYPE DEFAULTS, on any of the three, and that is the important line in
 * this file. They used to default to the manager archetype, which quietly told
 * every customer that the way to make somebody a Report LMS manager was to hand
 * them Moodle's manager role. That role carries 656 capabilities, including
 * moodle/user:delete and moodle/course:delete. Somebody whose job is "did my
 * team finish their training" could delete the course and the people in it.
 *
 * Nothing is created to replace it either. There are two ways in: an
 * administrator grants :view to a role of their own making, or somebody is
 * named as a manager in the editor, which grants the same sight without any
 * role assignment at all. See docs/plan-access-and-mapping.md.
 */
$capabilities = [

    /*
     * Names the holder the reporting manager of the participants of the courses
     * this is held on, and opens the report scoped to them.
     *
     * CONTEXT_COURSE rather than CONTEXT_SYSTEM, so it is assignable at a
     * course, a category or the system and Moodle's own inheritance does the
     * rest. A category grant covers every course beneath it, which is the shape
     * a real department manager actually has. Declared at system level it could
     * only be granted site-wide, which is the one shape almost nobody wants.
     *
     * RISK_PERSONAL: holding this makes other people's training progress
     * visible, which is exactly the staff-monitoring surface.
     */
    'local/reportlms:view' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [],
    ],

    /*
     * Opens the mapping editor, and nothing else.
     *
     * This is the authority to say who manages whom. It shows the holder no
     * learner's numbers: a person who may state a fact about the organisation
     * is not thereby entitled to read the consequences of it, and keeping the
     * two apart is what lets a training coordinator maintain the tree without
     * being given sight of everybody's progress.
     *
     * CONTEXT_SYSTEM, because the editor edits the whole tree.
     *
     * RISK_PERSONAL: naming somebody a manager grants them sight of other
     * people's progress, so this is the capability that hands that out.
     */
    'local/reportlms:manage' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    /*
     * The whole organisation, for HR, auditors and organisation managers.
     *
     * Named for what it does rather than for who holds it: it grants sight of
     * everything and control of nothing, following moodle/grade:viewall.
     *
     * No archetype default, and that is the important line in this file.
     * Granting it to the manager archetype gave every category and course
     * manager the whole organisation, which makes :manage and the entire
     * scoping model beside it decorative. Seeing everybody is a deliberate
     * grant somebody makes in the role editor, not something a person inherits
     * by being made manager of one course.
     *
     * Only meaningful at system level, which is also how it is read: see
     * access::can_view_all(). Held at a category it would have to mean "the
     * whole organisation, from inside one corner of it", and resolving that
     * ambiguity towards "everything" is the unsafe direction.
     */
    'local/reportlms:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
        ],
    ],

    /*
     * May decide which dashboard cards this site shows, to everybody.
     *
     * The deck is site configuration, not a personal preference, so this is a
     * write capability at system context. Like :manage, a grant made at a
     * category is deliberately not honoured: authority over the whole site's
     * dashboard held "inside one corner of it" would be the same ambiguity
     * :viewall's comment resolves, in the same unsafe direction.
     *
     * No riskbitmask, and that absence is deliberate: this exposes nobody's
     * data (RISK_PERSONAL would be false) and changes no Moodle configuration
     * (RISK_CONFIG would claim a risk it does not carry). Site administrators
     * hold it implicitly, as everywhere.
     */
    'local/reportlms:configure' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];
