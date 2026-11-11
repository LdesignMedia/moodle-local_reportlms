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
 * Resolve coded learner ids to the e-mail addresses and phone numbers this site holds.
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
use local_reportlms\event\learner_contacts_resolved;
use local_reportlms\local\access;
use local_reportlms\local\learner_names;

/**
 * The contact details behind a list of people, for the dashboard's people export.
 *
 * The same path a name takes, on purpose, and the same as {@see resolve_learner_emails}
 * takes for an address. Report LMS holds coded ids and no contact details; the
 * dashboard asks this page, this page asks Moodle, and the answer goes into the
 * file the viewer downloads without passing through Report LMS at all.
 *
 * Its own service rather than a field added to {@see resolve_learner_emails},
 * because a Report LMS that is older than this plugin still calls that one and
 * is owed the answer it was written against. The rule is the one that service
 * applies, with a second part: only viewers Moodle lets see addresses are
 * answered at all ({@see access::can_copy_emails()}), and among those a phone
 * number is handed over only from a field Moodle shows this viewer
 * ({@see access::visible_phone_fields()}).
 *
 * "Not allowed" is an answer, not an error. The dashboard has to tell "you may
 * not export these" apart from "none of these people has an address", and an
 * exception would arrive looking like a fault in the lookup.
 */
class resolve_learner_contacts extends external_api {
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
     * Turn coded learner identifiers into e-mail addresses and phone numbers, for those in scope.
     *
     * Every code inside the viewer's reach comes back, with the address and the
     * phone number or with null for whichever that person has none usable of.
     * A code outside it does not come back at all. The dashboard leaves the
     * first's cells empty and says how many of the second it could not look up
     * in this site, so the two must never look alike.
     *
     * More than learner_names::MAX_CODES distinct codes in one call is refused
     * outright rather than cut short, for the reason {@see resolve_learner_emails}
     * gives: a missing code is reported to the viewer as somebody out of
     * reach, so an answer quietly shortened at the ceiling would report
     * hundreds of people that way for no reason anybody could see. The caller
     * asks in batches, and a batch that is too big should fail where it can be
     * seen.
     *
     * Each allowed answer is logged, as the number of addresses and the number
     * of phone numbers it handed over and nothing more. See
     * {@see \local_reportlms\event\learner_contacts_resolved}.
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

        // Asked before the size check, so a viewer who may not export contact
        // details always hears that, whatever they sent.
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
        $emails = 0;
        $phones = 0;

        foreach (learner_names::contacts($wanted) as $code => $contact) {
            $learners[] = ['code' => $code, 'email' => $contact['email'], 'phone' => $contact['phone']];

            if ($contact['email'] !== null) {
                $emails++;
            }

            if ($contact['phone'] !== null) {
                $phones++;
            }
        }

        learner_contacts_resolved::create([
            'context' => $context,
            'other' => ['emails' => $emails, 'phones' => $phones],
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
                'Whether this viewer may be given contact details at all. False means the list is empty because of that.'
            ),
            // A list rather than a keyed object, like the name service. A code
            // out of the viewer's reach is absent, and the caller counts it as
            // out of reach. A code for somebody in reach with no usable address
            // or phone number is present with null for it, and the caller
            // leaves that cell empty.
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
                    'phone' => new external_value(
                        PARAM_TEXT,
                        'The phone number this site holds and shows this viewer, or null when there is none',
                        VALUE_REQUIRED,
                        null,
                        NULL_ALLOWED
                    ),
                ])
            ),
        ]);
    }
}
