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
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_reportlms\local\access;
use local_reportlms\local\label_map;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

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
     * @param array $codes
     *
     * @return array
     */
    public static function execute(array $codes): array {
        $params = self::validate_parameters(self::execute_parameters(), ['codes' => $codes]);

        $context = context_system::instance();
        self::validate_context($context);
        access::require_view($context);

        $wanted = array_slice(array_values(array_unique($params['codes'])), 0, self::MAX_CODES);

        $labels = [];

        foreach (label_map::resolve($wanted) as $code => $entry) {
            $labels[] = [
                'code' => $code,
                'label' => $entry['label'],
                'kind' => $entry['kind'],
                'parent' => $entry['parent'],
            ];
        }

        return ['labels' => $labels];
    }

    /**
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
                    'kind' => new external_value(PARAM_ALPHA, 'sector, department or function'),
                    'parent' => new external_value(PARAM_ALPHANUMEXT, 'Parent node code, null at the top level', VALUE_OPTIONAL, null, NULL_ALLOWED),
                ])
            ),
        ]);
    }
}
