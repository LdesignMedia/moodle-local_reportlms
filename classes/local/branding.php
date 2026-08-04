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
 * The Report LMS mark and wordmark.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Renders the product's mark, in one place.
 *
 * The wordmark is HTML text rather than part of the image, so it stays
 * selectable, scales with the page and never turns into a blurry bitmap on a
 * high-density screen. The symbol is the only part that has to be a drawing.
 *
 * Nothing here loads a webfont. A product whose argument is that data stays on
 * the customer's own server should not make every page it appears on call out
 * to a font host.
 */
class branding {
    /**
     * The symbol on its own.
     *
     * @param int $size Rendered edge length in pixels.
     * @param array $attributes Extra attributes for the img tag.
     *
     * @return string
     */
    public static function mark(int $size = 40, array $attributes = []): string {
        global $OUTPUT;

        return \html_writer::empty_tag('img', $attributes + [
            'src' => $OUTPUT->image_url('mark', 'local_reportlms')->out(false),
            // Decorative: the product name is always next to it as real text.
            'alt' => '',
            'width' => $size,
            'height' => $size,
            'class' => 'local-reportlms-mark',
        ]);
    }

    /**
     * Symbol plus wordmark, as a horizontal lockup.
     *
     * @param int $size Symbol edge length in pixels; the wordmark scales with it.
     *
     * @return string
     */
    public static function lockup(int $size = 40): string {
        $wordmark = \html_writer::tag('span', 'Report', ['class' => 'local-reportlms-word'])
            . \html_writer::tag('span', 'LMS', ['class' => 'local-reportlms-suffix']);

        return \html_writer::div(
            self::mark($size) . \html_writer::tag('span', $wordmark, ['class' => 'local-reportlms-wordmark']),
            'local-reportlms-lockup',
            ['style' => '--local-reportlms-mark-size: ' . $size . 'px;']
        );
    }
}
