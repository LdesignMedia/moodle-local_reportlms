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
 * Turning coded learner ids back into the people this site knows.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Names for coded learner ids, and only the ones this viewer already manages.
 *
 * A learner pseudonym is an HMAC of the user id against a salt that never
 * leaves this site, so it cannot be reversed by arithmetic. It is reversed by
 * doing the work forwards: hash the candidates and see which one matches.
 *
 * That sounds expensive and is not. Measured on the largest site on file,
 * 45,635 users, building the whole map takes 44 milliseconds and 22 megabytes.
 *
 * The candidate set is the point, not an optimisation. It is built from the
 * people this viewer manages, so a code for anybody else simply is not in the
 * map and no name comes back. Scope is enforced by construction rather than by
 * a check somebody has to remember to write, which is the failure mode the
 * label service shipped with.
 */
class learner_names {
    /**
     * Refuse absurd requests outright.
     *
     * A page of a report names tens of people, and an export names as many as
     * the viewer manages. This is a ceiling on one request, not on an export:
     * the caller asks in batches.
     *
     * @var int
     */
    public const MAX_CODES = 1000;

    /**
     * Names for the codes this viewer is entitled to resolve.
     *
     * @param array<int, string> $codes Coded learner ids.
     *
     * @return array<string, string> Code to full name. Codes outside the
     *         viewer's reach are absent rather than refused.
     */
    public static function resolve(array $codes): array {
        $names = [];

        foreach (self::matching($codes, \core_user\fields::get_name_fields()) as $code => $user) {
            $names[$code] = fullname($user);
        }

        return $names;
    }

    /**
     * E-mail addresses for the codes this viewer is entitled to resolve.
     *
     * The same people {@see resolve()} would name, and not one more: an address
     * travels the path a name does, through the same candidate set, so the two
     * cannot come to different answers about who a viewer may see. What differs
     * is the second door, Moodle's own rule on who may see addresses at all,
     * which a viewer can pass for names and fail for addresses.
     *
     * That door is asked again here rather than trusted to the caller. The
     * service asks it first so it can say "not allowed" instead of "nobody has
     * one", but a list of addresses leaving this method is the thing being
     * guarded, so it is guarded where it leaves.
     *
     * Somebody without an address is absent, as is somebody whose stored
     * address is not one Moodle would accept as an address. The second is rare
     * (an import or an authentication plugin writing what it was given) and is
     * treated as none: copied into a mail client it would only break the
     * recipient line, and the service declares what it returns as an e-mail
     * address, so one malformed value would fail the whole batch.
     *
     * @param array<int, string> $codes Coded learner ids.
     *
     * @return array<string, string> Code to e-mail address. Codes outside the
     *         viewer's reach, or for somebody with no usable address, are absent.
     */
    public static function emails(array $codes): array {
        if (!access::can_copy_emails()) {
            return [];
        }

        $emails = [];

        foreach (self::matching($codes, ['email']) as $code => $user) {
            $email = trim((string) $user->email);

            if ($email !== '' && clean_param($email, PARAM_EMAIL) === $email) {
                $emails[$code] = $email;
            }
        }

        return $emails;
    }

    /**
     * The user records behind the codes this viewer is entitled to resolve.
     *
     * The one place the candidate set meets the codes, shared by names and
     * addresses so there is only ever one answer to "who may this viewer see".
     *
     * @param array<int, string> $codes Coded learner ids. Only the first
     *        MAX_CODES distinct ones are looked at.
     * @param array<int, string> $fields User table fields to load besides id.
     *
     * @return array<string, \stdClass> Code to user record.
     */
    protected static function matching(array $codes, array $fields): array {
        $codes = array_slice(array_values(array_unique($codes)), 0, self::MAX_CODES);

        if (!$codes) {
            return [];
        }

        $candidates = self::candidates();

        if ($candidates === []) {
            return [];
        }

        $wanted = array_flip($codes);
        $matched = [];

        foreach (self::users($candidates, $fields) as $user) {
            $code = pseudonym::learner((int) $user->id);

            if (isset($wanted[$code])) {
                $matched[$code] = $user;
            }
        }

        return $matched;
    }

    /**
     * The user ids this viewer may put a name to.
     *
     * @return array<int, int> Empty for a viewer who manages nobody. Null is
     *         deliberately not used: "everybody" is expressed by returning
     *         every id, so a caller cannot mistake an empty answer for an
     *         unrestricted one.
     */
    protected static function candidates(): array {
        global $DB, $USER;

        if (access::can_view_all()) {
            return $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 2');
        }

        /*
         * The people in the units this viewer was named against.
         *
         * It used to be the participants of every course they held :view on,
         * which is a different set and usually a much larger one: on a
         * connected site one manager's grant reached an all-staff course and
         * with it 1,467 of the site's 1,495 learners, whose names this would
         * then translate.
         */
        $userids = [];

        foreach (mapping::subtree(mapping::managed_nodes((int) $USER->id)) as $nodeid) {
            foreach (mapping::members_of((int) $nodeid) as $userid) {
                $userids[(int) $userid] = (int) $userid;
            }
        }

        return array_values($userids);
    }

    /**
     * The asked-for fields for a set of users, in chunks the database will take.
     *
     * @param array<int, int> $userids
     * @param array<int, string> $fields User table fields to load besides id.
     *
     * @return \Generator<\stdClass>
     */
    protected static function users(array $userids, array $fields): \Generator {
        global $DB;

        $fields = 'id, ' . implode(', ', $fields);

        foreach (array_chunk($userids, 5000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);

            $records = $DB->get_records_select(
                'user',
                "deleted = 0 AND id {$insql}",
                $params,
                '',
                $fields
            );

            foreach ($records as $record) {
                yield $record;
            }
        }
    }
}
