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
        $codes = array_slice(array_values(array_unique($codes)), 0, self::MAX_CODES);

        if (!$codes) {
            return [];
        }

        $candidates = self::candidates();

        if ($candidates === []) {
            return [];
        }

        $wanted = array_flip($codes);
        $names = [];

        foreach (self::users($candidates) as $user) {
            $code = pseudonym::learner((int) $user->id);

            if (isset($wanted[$code])) {
                $names[$code] = fullname($user);
            }
        }

        return $names;
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

        $manager = manager_resolver::managers()[(int) $USER->id] ?? null;

        if (!$manager) {
            return [];
        }

        return array_map('intval', $manager->participants);
    }

    /**
     * The name fields for a set of users, in chunks the database will take.
     *
     * @param array<int, int> $userids
     *
     * @return \Generator<\stdClass>
     */
    protected static function users(array $userids): \Generator {
        global $DB;

        $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields());

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
