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
 * Hidden marker that carries advanced mode through the settings form submit.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\admin;

/**
 * Carries the advanced flag through the settings form submit.
 *
 * The advanced settings are only built when the page URL carries mode=advanced,
 * but the core settings form posts to $PAGE->url, which core builds with the
 * section parameter only. Without this marker the mode is lost on submit, the
 * advanced settings are not in the admin tree during the save request, and
 * admin_write_settings() silently drops the posted values.
 *
 * This setting stores nothing: it renders a hidden input inside the form so the
 * save request can re-detect advanced mode.
 */
class setting_advanced_marker extends \admin_setting_heading {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('local_reportlms/advancedmarker', '', '');
    }

    /**
     * Render the hidden marker input.
     *
     * @param mixed $data Unused, this setting has no stored value.
     * @param string $query Unused search query.
     * @return string
     */
    public function output_html($data, $query = ''): string {
        return \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'reportlmsadv',
            'value' => 1,
        ]);
    }
}
