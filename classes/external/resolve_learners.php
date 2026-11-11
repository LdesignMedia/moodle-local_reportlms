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
 * Resolve coded learner ids to the names this site holds.
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
use local_reportlms\local\learner_names;

defined('MOODLE_INTERNAL') || die();

/**
 * The people behind the numbers, named here and nowhere else.
 *
 * Report LMS holds coded learner ids and no names, by design and by contract.
 * A list of who has not finished is therefore a list of codes until this site
 * says otherwise, and this is where it says otherwise.
 *
 * Kept apart from {@see resolve_labels} on purpose. That one names departments,
 * which describe groups; this one names people. The scoping rules are different
 * and so are the consequences of getting them wrong, and one service doing both
 * would eventually be given one rule.
 */
class resolve_learners extends external_api {
    /**
     * What this service takes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'codes' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'A coded learner id'),
                'The coded learner ids on screen',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Turn coded learner identifiers back into names, for those in scope.
     *
     * @param array $codes
     *
     * @return array
     */
    public static function execute(array $codes): array {
        $params = self::validate_parameters(self::execute_parameters(), ['codes' => $codes]);

        $context = context_system::instance();
        self::validate_context($context);

        // Opens the door. Which of the codes are answered is decided per code
        // by the candidate set, not by this check.
        /*
         * Capability check: local/reportlms:view, enforced through the shared
         * access helper so every surface answers the same way. The helper calls
         * require_capability() on the system context for site administrators and
         * on every context where the user holds a role, because the manager
         * role is normally assigned at a course or category rather than at the
         * system. db/services.php declares the same capability for this function.
         */
        access::require_view();

        $learners = [];

        foreach (learner_names::resolve($params['codes']) as $code => $name) {
            $learners[] = ['code' => $code, 'name' => $name];
        }

        return ['learners' => $learners];
    }

    /**
     * The shape of what this service returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            // A list rather than a keyed object: the external API cannot
            // describe a structure whose keys are data. A code the viewer may
            // not resolve is simply absent, and the caller keeps showing
            // whatever placeholder it already had.
            'learners' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'The coded learner id'),
                    'name' => new external_value(PARAM_NOTAGS, 'The name this site holds'),
                ])
            ),
        ]);
    }
}
