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
 * Hook before standard head html generation
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 **/

namespace local_reportlms\hook;

use local_reportlms\local\block_visibility;

/**
 * Class before_standard_head_html_generation
 *
 * The hooks-API side of the pair in lib.php. Both exist on purpose: this one
 * is what Moodle 4.3+ dispatches (and its presence makes core skip the legacy
 * callback, so nothing runs twice); the lib.php function covers the 3.9-4.2
 * range version.php still claims. Both delegate to block_visibility, which
 * carries its own install/upgrade guard — core guards the legacy path but not
 * this one.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 **/
class before_standard_head_html_generation {
    /**
     * Callback to inject the per-viewer visibility CSS for the dashboard block.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook The hook.
     *
     * @return void
     */
    public static function callback(\core\hook\output\before_standard_head_html_generation $hook): void {
        $hook->add_html(block_visibility::css());
    }
}
