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
 * Web service that turns coded identifiers into labels for the embedded dashboard.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportlms\local\access;
use local_reportlms\local\label_map;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve org node and function codes to the names this site holds for them.
 *
 * The dashboard renders on the Report LMS origin and has only codes, because
 * that is all this site ever sends it. It asks the Moodle page that frames it
 * to translate the handful of codes on screen, and the answer goes to that
 * page and no further: nothing here is ever posted back to Report LMS.
 *
 * Learner pseudonyms are not resolvable through this. Turning a report into a
 * list of people is a Moodle page with its own access checks, not a lookup
 * anyone holding a code can perform.
 */
class resolve_labels extends external_api {
    /** @var int Refuse absurd requests outright. A dashboard renders tens of codes, not thousands. */
    protected const MAX_CODES = 500;

    /**
     * What this service takes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'codes' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'A coded identifier, as it appears in the report'),
                'The codes currently on screen'
            ),
        ]);
    }

    /**
     * Turn coded node identifiers back into the names this site holds.
     *
     * @param array $codes
     *
     * @return array
     */
    public static function execute(array $codes): array {
        $params = self::validate_parameters(self::execute_parameters(), ['codes' => $codes]);

        // The context is validated at system level because that is where this
        // service is called from, but the capability is asked for anywhere: a
        // manager assigned at a course holds it there, and resolving a label
        // they are already allowed to see is not a system-level act.
        $context = context_system::instance();
        self::validate_context($context);
        /*
         * Capability check: local/reportlms:view, enforced through the shared
         * access helper so every surface answers the same way. The helper calls
         * require_capability() on the system context for site administrators and
         * on every context where the user holds a role, because the manager
         * role is normally assigned at a course or category rather than at the
         * system. db/services.php declares the same capability for this function.
         */
        access::require_view();

        $wanted = array_slice(array_values(array_unique($params['codes'])), 0, self::MAX_CODES);

        // Narrowed to what this viewer may have named. A code outside their
        // scope is dropped rather than refused: the frame asks about whatever
        // is on screen, and one unresolvable code should leave that code
        // showing rather than fail the request and blank the page.
        $wanted = access::visible_codes($wanted);

        $labels = [];

        $map = null;

        foreach (label_map::resolve($wanted) as $code => $entry) {
            if ($entry['kind'] === 'category') {
                $map = $map ?? label_map::all();
            }

            $labels[] = [
                'code' => $code,
                'label' => $entry['kind'] === 'category' ? self::category_name($entry, $map) : $entry['label'],
                'kind' => $entry['kind'],
                'parent' => $entry['parent'],
            ];
        }

        return ['labels' => $labels];
    }

    /**
     * A category's name as this viewer reads it, with its top category in front.
     *
     * The top is what tells an old copy of a training from this year's: a site
     * that files last year's copies under "2025 / 2026" can give them the same
     * category names as the current ones ("Transport" and "Transport"), and
     * "2025 / 2026 › Transport" says which is which. A top-level category is
     * its own name alone. The levels between are left out to keep it short:
     * the top and the category itself are the two that differ.
     *
     * The top is named only when the viewer could be told it on its own: a
     * hidden one only with moodle/category:viewhiddencategories there.
     *
     * Run through the text filters here rather than when the map is built: the
     * map is cached for every viewer, and a multi-language name has to come out
     * in the language of the one being answered. Not escaped, because the
     * dashboard renders it as text.
     *
     * @param array $entry A category entry from the label map.
     * @param array $map The whole label map, for the parents.
     *
     * @return string
     */
    protected static function category_name(array $entry, array $map): string {
        $own = self::formatted($entry);

        $top = null;
        $code = $entry['parent'];
        // Bounded, in case a damaged tree points in a circle.
        for ($depth = 0; $code !== null && isset($map[$code]) && $depth < 20; $depth++) {
            $top = $map[$code];
            $code = $top['parent'];
        }

        if ($top === null || ($top['kind'] ?? null) !== 'category') {
            return $own;
        }

        if (!access::category_shown((int) $top['categoryid'])) {
            return $own;
        }

        return self::formatted($top) . ' › ' . $own;
    }

    /**
     * One category entry's name, filtered for this viewer.
     *
     * @param array $entry
     *
     * @return string
     */
    protected static function formatted(array $entry): string {
        $context = \context_coursecat::instance($entry['categoryid'], IGNORE_MISSING) ?: context_system::instance();

        return trim(format_string($entry['label'], true, ['context' => $context, 'escape' => false]));
    }

    /**
     * The shape of what this service returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            // A list rather than a keyed object: the external API cannot
            // describe a structure whose keys are data. Unresolved codes are
            // simply absent, and the caller keeps showing the raw code.
            'labels' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'The code that was asked about'),
                    'label' => new external_value(PARAM_TEXT, 'The name this site holds for it'),
                    'kind' => new external_value(PARAM_ALPHA, 'sector, department, function or category'),
                    'parent' => new external_value(PARAM_ALPHANUMEXT, 'Parent node code, null at the top level', VALUE_OPTIONAL, null, NULL_ALLOWED),
                ])
            ),
        ]);
    }
}
