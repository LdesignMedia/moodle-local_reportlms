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
 * Turns the coded identifiers in a report back into readable labels.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\org;

/**
 * The forward map, rebuilt and read backwards.
 *
 * The SaaS never receives a department or function name: it holds the coded
 * identifier and nothing else, and the dashboard asks this site to translate
 * the codes it is about to render. That is the whole reason names stay here.
 *
 * A code is an HMAC and cannot be inverted, so this does not try. It rebuilds
 * exactly the codes {@see pseudonym} would produce for the values currently in
 * the profile fields, and looks the requested code up in that. A value that no
 * longer exists on this site therefore has no label, which is correct: the
 * report is showing a node whose source data is gone, and inventing a name for
 * it would be worse than showing the code.
 *
 * Only structure is resolvable here. Learner pseudonyms deliberately are not:
 * a page that needs to show people is built in Moodle, against Moodle's own
 * access checks, rather than handed a list of names through an AJAX call.
 */
class label_map {
    /** @var string Cache key for the whole map. */
    protected const CACHE_KEY = 'labelmap';

    /**
     * Every resolvable code on this site.
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     *         Keyed by coded identifier.
     */
    public static function all(): array {
        $cache = \cache::make('local_reportlms', 'labels');
        $map = $cache->get(self::CACHE_KEY);

        if ($map !== false) {
            return $map;
        }

        $map = array_merge(self::nodes(), self::functions(), self::categories());
        $cache->set(self::CACHE_KEY, $map);

        return $map;
    }

    /**
     * Resolve a specific set of codes.
     *
     * Unknown codes are absent from the result rather than an error: the
     * caller renders the raw code for those, and a report holding one stale
     * node must not fail to draw.
     *
     * @param array<int, string> $codes
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     */
    public static function resolve(array $codes): array {
        $map = self::all();

        return array_intersect_key($map, array_flip($codes));
    }

    /**
     * The course a coded course ref stands for.
     *
     * Kept apart from {@see all}: course titles are sent to Report LMS by
     * contract, so the dashboard already shows readable course names and never
     * asks about these. Only a drill-through does, and only for one course at a
     * time, so there is no reason to carry every course in the label map.
     *
     * @param string $ref
     *
     * @return int|null Null when no course on this site produces that ref.
     */
    public static function course_id(string $ref): ?int {
        global $DB;

        $cache = \cache::make('local_reportlms', 'labels');
        $map = $cache->get('coursecodes');

        if ($map === false) {
            $map = [];

            foreach ($DB->get_fieldset_select('course', 'id', 'id > 1') as $courseid) {
                $map[pseudonym::course((int) $courseid)] = (int) $courseid;
            }

            $cache->set('coursecodes', $map);
        }

        return $map[$ref] ?? null;
    }

    /**
     * Drop the cached map.
     *
     * Called when the org state changes under us, so a renamed department does
     * not keep its old label for the rest of the cache window.
     *
     * @return void
     */
    public static function purge(): void {
        \cache::make('local_reportlms', 'labels')->delete_many([self::CACHE_KEY, 'coursecodes']);
    }

    /**
     * The org tree's labels.
     *
     * Read from the same rows {@see org::collect} reads, so a code that reached
     * Report LMS can always be turned back into the name somebody typed. It
     * used to be rebuilt from the distinct values in two profile fields, which
     * matched only by coincidence: a unit created in the editor and named
     * something nobody has in their profile produced a code with no label, and
     * the dashboard rendered its hash.
     *
     * A department's key carries its sector, so "HR" under "Transport" and "HR"
     * under "Office" are two nodes with the same label and different codes.
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     */
    protected static function nodes(): array {
        $map = [];

        // The bucket for people nobody has placed. Always present, exactly as
        // the collector always emits it.
        [$level, $value] = explode(':', org::UNASSIGNED, 2);
        $map[pseudonym::node($level, $value)] = [
            'label' => get_string('label:unassigned', 'local_reportlms'),
            'kind' => 'department',
            'parent' => null,
        ];

        $nodes = mapping::nodes();

        foreach ($nodes as $node) {
            $key = mapping::nodekey((int) $node->id);

            if ($key === null) {
                continue;
            }

            [$kind, $path] = explode(':', $key, 2);

            $parentcode = null;

            if ($node->parentid !== null && isset($nodes[$node->parentid])) {
                $parentkey = mapping::nodekey((int) $node->parentid);

                if ($parentkey !== null) {
                    [$parentkind, $parentpath] = explode(':', $parentkey, 2);
                    $parentcode = pseudonym::node($parentkind, $parentpath);
                }
            }

            $map[pseudonym::node($kind, $path)] = [
                // The name alone. The path is the node's identity, not its
                // label: a picker showing "Transport/HR" under Transport reads
                // as a mistake.
                'label' => $node->name,
                'kind' => $node->kind,
                'parent' => $parentcode,
            ];
        }

        return $map;
    }

    /**
     * The function labels.
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     */
    protected static function functions(): array {
        $map = [];

        foreach (self::field_values(org::function_field()) as $value) {
            $map[pseudonym::functionref($value)] = [
                'label' => $value,
                'kind' => 'function',
                'parent' => null,
            ];
        }

        return $map;
    }

    /**
     * The course category labels.
     *
     * A category tells apart trainings that share a title, and its name can be
     * a person's ("TM - Jane Doe"), so it travels as a code and is named
     * here, like a department. The parent rides along for resolve_labels,
     * which puts the top category in front of a nested one's name, so callers
     * get "2025 / 2026 › Transport" whole and need not build it.
     *
     * Every category rather than only those holding a reported course: a site
     * has tens of them, not thousands, and filtering would mean the collector's
     * course query a second time.
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     */
    protected static function categories(): array {
        global $DB;

        $map = [];

        foreach ($DB->get_records('course_categories', null, '', 'id, name, parent, visible') as $category) {
            $map[pseudonym::category((int) $category->id)] = [
                // Raw, as stored. The map is cached for every viewer, so the
                // multi-language filter runs when a viewer is answered, in
                // their language; see resolve_labels.
                'label' => trim((string) $category->name),
                'kind' => 'category',
                'parent' => (int) $category->parent > 0 ? pseudonym::category((int) $category->parent) : null,
                // For the access check: a hidden category is named only to
                // somebody Moodle would show it to.
                'categoryid' => (int) $category->id,
                'visible' => (bool) $category->visible,
            ];
        }

        return $map;
    }

    /**
     * The distinct values of one profile field.
     *
     * @param string $shortname
     *
     * @return array<int, string>
     */
    protected static function field_values(string $shortname): array {
        global $DB;

        $fieldid = self::field_id($shortname);

        if ($fieldid === null) {
            return [];
        }

        $rows = $DB->get_fieldset_sql(
            'SELECT DISTINCT data FROM {user_info_data} WHERE fieldid = :fieldid',
            ['fieldid' => $fieldid]
        );

        $values = [];

        foreach ($rows as $row) {
            $value = self::clean($row);

            if ($value !== null) {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * The id of one profile field, by shortname.
     *
     * @param string $shortname
     *
     * @return int|null Null when the site has no such field.
     */
    protected static function field_id(string $shortname): ?int {
        global $DB;

        $id = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);

        return $id === false ? null : (int) $id;
    }

    /**
     * Trim a stored value, and treat blank as absent.
     *
     * Same rule the collector applies, so the two agree on which users have a
     * value at all.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    protected static function clean(?string $value): ?string {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
