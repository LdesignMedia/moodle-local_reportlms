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
 * Resolve coded learner ids to the e-mail addresses this site holds.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportlms\event\learner_emails_resolved;
use local_reportlms\local\access;
use local_reportlms\local\learner_names;

/**
 * The addresses behind a list of people, for the dashboard's copy button.
 *
 * The same path a name takes, on purpose. Report LMS holds coded ids and no
 * addresses; the dashboard asks this page, this page asks Moodle, and the answer
 * goes into the viewer's clipboard without passing through Report LMS at all.
 *
 * Its own service rather than a flag on {@see resolve_learners}, for the reason
 * that one is kept apart from the label service: the rule is different. Anybody
 * who may open the report may be told the names of their own people. Only a
 * viewer Moodle itself lets see addresses may be told theirs, and that is
 * decided by {@see access::can_copy_emails()}.
 *
 * "Not allowed" is an answer, not an error. The dashboard has to tell "you may
 * not copy these" apart from "none of these people has an address", and an
 * exception would arrive looking like a fault in the lookup.
 */
class resolve_learner_emails extends external_api {
    /**
     * What this service takes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'codes' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'A coded learner id'),
                'The coded learner ids to look up, at most ' . learner_names::MAX_CODES . ' distinct ones per call',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Turn coded learner identifiers into e-mail addresses, for those in scope.
     *
     * Every code inside the viewer's reach comes back, with the address or
     * with null when that person has none Moodle would accept. A code outside
     * it does not come back at all. The dashboard reports the first as "has
     * none" and the second as "not copied", so the two must never look alike.
     *
     * More than learner_names::MAX_CODES distinct codes in one call is refused
     * outright rather than cut short, which is where this parts company with
     * the name service. A missing name leaves a placeholder on screen and costs
     * nothing. A missing code here is reported to the viewer as somebody whose
     * address was not copied, so an answer quietly shortened at the ceiling
     * would report hundreds of people that way for no reason anybody could see.
     * The caller asks in batches, and a batch that is too big should fail where
     * it can be seen.
     *
     * Each allowed answer is logged, as the number of addresses it handed over
     * and nothing more. See {@see \local_reportlms\event\learner_emails_resolved}.
     *
     * @param array $codes
     *
     * @return array
     */
    public static function execute(array $codes): array {
        $params = self::validate_parameters(self::execute_parameters(), ['codes' => $codes]);

        $context = context_system::instance();
        self::validate_context($context);

        // Opens the door, exactly as for names. Which codes are answered is
        // still decided per code by the candidate set.
        /*
         * Capability check: local/reportlms:view, enforced through the shared
         * access helper so every surface answers the same way. The helper calls
         * require_capability() on the system context for site administrators and
         * on every context where the user holds a role, because the manager
         * role is normally assigned at a course or category rather than at the
         * system. db/services.php declares the same capability for this function.
         */
        access::require_view();

        // Asked before the size check, so a viewer who may not copy addresses
        // always hears that, whatever they sent.
        if (!access::can_copy_emails()) {
            return ['allowed' => false, 'learners' => []];
        }

        $wanted = array_values(array_unique($params['codes']));

        if (count($wanted) > learner_names::MAX_CODES) {
            throw new \invalid_parameter_exception(
                'At most ' . learner_names::MAX_CODES . ' distinct codes per call; ask in batches.'
            );
        }

        $learners = [];
        $addresses = 0;

        foreach (learner_names::emails($wanted) as $code => $email) {
            $learners[] = ['code' => $code, 'email' => $email];

            if ($email !== null) {
                $addresses++;
            }
        }

        learner_emails_resolved::create([
            'context' => $context,
            'other' => ['count' => $addresses],
        ])->trigger();

        return ['allowed' => true, 'learners' => $learners];
    }

    /**
     * The shape of what this service returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'allowed' => new external_value(
                PARAM_BOOL,
                'Whether this viewer may be given e-mail addresses at all. False means the list is empty because of that.'
            ),
            // A list rather than a keyed object, like the name service. A code
            // out of the viewer's reach is absent, and the caller reports it as
            // not copied. A code for somebody in reach with no usable address
            // is present with a null address, and the caller reports it as
            // having none.
            'learners' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_ALPHANUMEXT, 'The coded learner id'),
                    'email' => new external_value(
                        PARAM_EMAIL,
                        'The e-mail address this site holds, or null when this person has none Moodle would accept',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                ])
            ),
        ]);
    }
}
