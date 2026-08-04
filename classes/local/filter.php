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
 * A dashboard selection, translated back into this site's own terms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * The codes a drill-through arrives with, resolved to real values.
 *
 * The dashboard filters on coded identifiers because that is all it has. When
 * someone clicks through to the people behind a number, those codes come back
 * here and have to become a course id and a set of profile field values again.
 *
 * A code that does not resolve is dropped rather than guessed at. Widening the
 * result would show people the selection never covered, and that is worse than
 * a page that quietly covers more than it says: it would be wrong in the
 * direction of showing someone data they did not ask to see.
 */
class filter {
    /** @var int|null Course id, null for every course. */
    public $courseid = null;

    /** @var string|null Sector value from the profile field. */
    public $sector = null;

    /** @var string|null Department value from the profile field. */
    public $department = null;

    /** @var string|null Function value from the profile field. */
    public $function = null;

    /** @var array<int, string> Codes this site could not place. */
    public $unresolved = [];

    /**
     * Build from the coded parameters a drill-through carries.
     *
     * @param string $nodecode Sector or department node code, may be empty.
     * @param string $coursecode Course code, may be empty.
     * @param string $functioncode Function code, may be empty.
     *
     * @return self
     */
    public static function from_codes(string $nodecode, string $coursecode, string $functioncode): self {
        $filter = new self();
        $map = label_map::all();

        if ($nodecode !== '') {
            $filter->apply_node($nodecode, $map);
        }

        if ($functioncode !== '') {
            if (isset($map[$functioncode]) && $map[$functioncode]['kind'] === 'function') {
                $filter->function = $map[$functioncode]['label'];
            } else {
                $filter->unresolved[] = $functioncode;
            }
        }

        if ($coursecode !== '') {
            $courseid = label_map::course_id($coursecode);

            if ($courseid !== null) {
                $filter->courseid = $courseid;
            } else {
                $filter->unresolved[] = $coursecode;
            }
        }

        return $filter;
    }

    /**
     * Resolve an org node, and its sector when the node is a department.
     *
     * A department is only identified together with its sector, so selecting
     * one has to constrain both. Without that, "HR" would match HR in every
     * sector on the site.
     *
     * @param string $code
     * @param array<string, array{label: string, kind: string, parent: string|null}> $map
     *
     * @return void
     */
    protected function apply_node(string $code, array $map): void {
        if (!isset($map[$code])) {
            $this->unresolved[] = $code;

            return;
        }

        $node = $map[$code];

        if ($node['kind'] === 'sector') {
            $this->sector = $node['label'];

            return;
        }

        $this->department = $node['label'];

        if ($node['parent'] !== null && isset($map[$node['parent']])) {
            $this->sector = $map[$node['parent']]['label'];
        }
    }

    /**
     * A one-line description of what is being shown, for the page heading.
     *
     * @return string
     */
    public function describe(): string {
        $parts = array_filter([$this->sector, $this->department, $this->function]);

        return $parts ? implode(' / ', $parts) : get_string('people:everyone', 'local_reportlms');
    }
}
