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

        $map = array_merge(self::nodes(), self::functions());
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
     * Drop the cached map.
     *
     * Called when the org state changes under us, so a renamed department does
     * not keep its old label for the rest of the cache window.
     *
     * @return void
     */
    public static function purge(): void {
        \cache::make('local_reportlms', 'labels')->delete(self::CACHE_KEY);
    }

    /**
     * The org tree's labels.
     *
     * Node identity has to be derived exactly the way {@see org::collect}
     * derives it, including the rule that a department's key carries its
     * sector: "HR under Transport" and "HR under Office" are two nodes, and a
     * label map built on the bare department name would resolve one code and
     * miss the other.
     *
     * @return array<string, array{label: string, kind: string, parent: string|null}>
     */
    protected static function nodes(): array {
        $map = [];

        // The bucket for people with no mapped value. Always present, exactly
        // as the collector always emits it.
        [$level, $value] = explode(':', org::UNASSIGNED, 2);
        $map[pseudonym::node($level, $value)] = [
            'label' => get_string('label:unassigned', 'local_reportlms'),
            'kind' => 'department',
            'parent' => null,
        ];

        foreach (self::sector_department_pairs() as $pair) {
            $sectorcode = null;

            if ($pair->sector !== null) {
                $sectorcode = pseudonym::node('sector', $pair->sector);
                $map[$sectorcode] = [
                    'label' => $pair->sector,
                    'kind' => 'sector',
                    'parent' => null,
                ];
            }

            if ($pair->department === null) {
                continue;
            }

            $departmentvalue = ($pair->sector !== null ? $pair->sector . '/' : '') . $pair->department;
            $map[pseudonym::node('department', $departmentvalue)] = [
                'label' => $pair->department,
                'kind' => 'department',
                'parent' => $sectorcode,
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

        foreach (self::field_values(org::fieldmap()['function']) as $value) {
            $map[pseudonym::functionref($value)] = [
                'label' => $value,
                'kind' => 'function',
                'parent' => null,
            ];
        }

        return $map;
    }

    /**
     * The distinct sector and department combinations in use.
     *
     * Distinct pairs rather than the full user list: the collector walks every
     * user because it has to emit a membership row per person, this only needs
     * the shapes those rows can take.
     *
     * @return array<int, \stdClass> {sector, department}, either may be null.
     */
    protected static function sector_department_pairs(): array {
        global $DB;

        $map = org::fieldmap();
        $sectorid = self::field_id($map['sector']);
        $departmentid = self::field_id($map['department']);

        if ($sectorid === null && $departmentid === null) {
            return [];
        }

        // Left joins on both, so a user with only one of the two still
        // contributes the node they do have.
        $sql = "SELECT DISTINCT s.data AS sector, d.data AS department
                  FROM {user} u
             LEFT JOIN {user_info_data} s ON s.userid = u.id AND s.fieldid = :sectorid
             LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :departmentid
                 WHERE u.deleted = 0 AND u.id > 2";

        $rows = $DB->get_records_sql($sql, [
            // 0 never matches a real field id, which is how an unconfigured
            // level degrades to "nobody has one" instead of a broken query.
            'sectorid' => $sectorid ?? 0,
            'departmentid' => $departmentid ?? 0,
        ]);

        $pairs = [];

        foreach ($rows as $row) {
            $sector = self::clean($row->sector);
            $department = self::clean($row->department);

            if ($sector === null && $department === null) {
                continue;
            }

            $pairs[$sector . "\0" . $department] = (object) [
                'sector' => $sector,
                'department' => $department,
            ];
        }

        return array_values($pairs);
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
