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
 * Web service definitions for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // AJAX only, and deliberately not exposed as a REST service: this exists
    // so the dashboard page can name the nodes it is drawing, not so an
    // integration can pull the org tree's labels out of the site.
    'local_reportlms_resolve_labels' => [
        'classname' => 'local_reportlms\external\resolve_labels',
        'methodname' => 'execute',
        'description' => 'Resolve org node and function codes to the labels this site holds for them.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
];
