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
 * Sending the organisation in pages.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

/**
 * Whether Report LMS takes the organisation in pages, and how to cut it.
 *
 * The organisation section is full state: every unit, every membership,
 * every manager, and one row of attributes per person. On a large site that
 * is hundreds of thousands of rows in one request. Report LMS says in its
 * health and connect replies how many member and attribute rows it takes per
 * page; this side keeps that offer, and the push sends a section larger than
 * one page as a set of pages before anything else.
 *
 * A Report LMS that says nothing about pages cannot take them, so the offer
 * is cleared and the section goes whole, exactly as it always did.
 */
class org_pages {
    /** @var int The most member and attribute rows this plugin puts on one page. */
    public const ROWS = 20000;

    /** @var string Plugin config key holding the rows per page Report LMS offered. */
    public const CONFIG = 'orgpagerows';

    /** @var string The contract version a page is sent under. */
    public const CONTRACT = '1.1';

    /**
     * Keep the page offer a health or connect reply carries.
     *
     * Only a positive whole number is an offer. Anything else, a missing key
     * included, is a Report LMS that cannot take pages, and clears what was
     * stored. Written only when it changes, so an unchanged offer costs no
     * write on every run.
     *
     * @param array $reply A decoded health or connect reply.
     * @return void
     */
    public static function store_reply(array $reply): void {
        $offer = $reply['org_page_rows'] ?? null;

        if (!is_int($offer) || $offer < 1) {
            self::clear();

            return;
        }

        if ($offer !== (int) get_config('local_reportlms', self::CONFIG)) {
            set_config(self::CONFIG, $offer, 'local_reportlms');
        }
    }

    /**
     * Rows per page to send, or 0 when the organisation goes whole.
     *
     * The offer, never more than this plugin's own page size.
     *
     * @return int
     */
    public static function rows(): int {
        $offer = (int) get_config('local_reportlms', self::CONFIG);

        return $offer > 0 ? min(self::ROWS, $offer) : 0;
    }

    /**
     * Forget the offer, for a disconnect or a reply without one.
     *
     * @return void
     */
    public static function clear(): void {
        if (get_config('local_reportlms', self::CONFIG) !== false) {
            unset_config(self::CONFIG, 'local_reportlms');
        }
    }

    /**
     * A new coded name for one set of pages.
     *
     * Random, so a set sent again after a refusal is a new set, never the
     * remainder of an old one.
     *
     * @return string
     */
    public static function new_set_id(): string {
        return 's_' . bin2hex(random_bytes(16));
    }

    /**
     * Cut an organisation section into pages.
     *
     * The members and then the attribute rows are one stream, cut every so
     * many rows. The first page also carries every unit and every manager;
     * the later pages send both empty. The state hash and the limit go on
     * every page, so each page says which section it belongs to.
     *
     * Always at least one page. The caller names the set.
     *
     * @param array $section The section {@see batch_builder::org_section_if_changed()} builds.
     * @param int $rows Member and attribute rows per page, at least 1.
     * @return array[] Pages: {page, page_count, org_state_hash, limit_applied, nodes, managers, members, attrs}.
     * @throws \coding_exception For a page size below 1.
     */
    public static function split(array $section, int $rows): array {
        if ($rows < 1) {
            throw new \coding_exception('An organisation page holds at least one row.');
        }

        $members = $section['members'];
        $attrs = $section['attrs'];
        $memberrows = count($members);
        $count = max(1, (int) ceil(($memberrows + count($attrs)) / $rows));

        $pages = [];

        for ($page = 0; $page < $count; $page++) {
            // This page's stretch of the stream: rows start to end, end not included.
            $start = $page * $rows;
            $end = $start + $rows;

            $pages[] = [
                'page' => $page,
                'page_count' => $count,
                'org_state_hash' => $section['org_state_hash'],
                'limit_applied' => $section['limit_applied'],
                'nodes' => $page === 0 ? $section['nodes'] : [],
                'managers' => $page === 0 ? $section['managers'] : [],
                // The part of the stretch that falls in the members...
                'members' => array_slice($members, $start, max(0, min($end, $memberrows) - $start)),
                // ...and the part that falls in the attribute rows after them.
                'attrs' => array_slice($attrs, max(0, $start - $memberrows), max(0, $end - max($start, $memberrows))),
            ];
        }

        return $pages;
    }
}
