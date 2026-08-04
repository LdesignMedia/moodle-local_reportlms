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
 * Cache definitions for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // The coded identifier to label map the embedded dashboard resolves
    // against. Site wide rather than per user: it is derived from the profile
    // fields, not from who is asking, and what a given viewer may see is
    // decided before the lookup rather than by holding a smaller map.
    //
    // The TTL exists because there is no cheap change marker to key on. The
    // org state hash would be exact, but computing it costs more than
    // rebuilding this map, so a short window plus an explicit purge on push
    // is the better trade.
    'labels' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simpledata' => false,
        'staticacceleration' => true,
        'ttl' => 1800,
    ],
];
