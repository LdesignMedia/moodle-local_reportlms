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
 * The question asked of a site administrator when Report LMS has no contact for the site.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\output;

defined('MOODLE_INTERNAL') || die();

use local_reportlms\local\branding;
use renderable;
use renderer_base;
use templatable;

/**
 * One field and one button, shown in place of the report.
 */
class contact_card implements renderable, templatable {
    /** @var string What the field starts with. */
    protected string $email;

    /** @var bool Whether the last attempt to save was refused. */
    protected bool $invalid;

    /** @var \moodle_url Where the way out leads. */
    protected \moodle_url $backurl;

    /**
     * Constructor.
     *
     * @param string $email What the field starts with.
     * @param bool $invalid Whether the last attempt to save was refused.
     * @param \moodle_url $backurl Where the way out leads.
     */
    public function __construct(string $email, bool $invalid, \moodle_url $backurl) {
        $this->email = $email;
        $this->invalid = $invalid;
        $this->backurl = $backurl;
    }

    /**
     * Everything the template needs.
     *
     * @param renderer_base $output
     *
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        return (object) [
            'action' => (new \moodle_url('/local/reportlms/view.php'))->out(false),
            'sesskey' => sesskey(),
            'email' => $this->email,
            'invalid' => $this->invalid,
            'backurl' => $this->backurl->out(false),
            'lockup' => branding::lockup(40),
        ];
    }
}
