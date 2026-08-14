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
 * A push Report LMS refused for a reason this site can act on.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries the machine-readable reason alongside the message.
 *
 * Most push failures are weather: a timeout, a restart, a bad gateway. Those
 * are retried and nothing else happens. A few are decisions, and a decision has
 * to be told apart from weather or the site retries forever against an answer
 * that is never going to change.
 */
class push_refused_exception extends \moodle_exception {
    /** @var string The reason Report LMS gave, or '' when it gave none. */
    public $reason;

    /**
     * Carry both what Report LMS refused and why, so the task can say so.
     *
     * @param string $reason Machine-readable code from Report LMS.
     * @param string $message What it said, written for the administrator.
     */
    public function __construct(string $reason, string $message) {
        $this->reason = $reason;

        parent::__construct('error:pushrejected', 'local_reportlms', '', $message);
    }
}
