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
 * Tells Report LMS that people are to be forgotten.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * The remote half of a privacy deletion.
 *
 * Sends the departures block of the push for an explicit list of people. It is
 * not the deleted-account sweep: a living account whose data is being erased is
 * named here by the caller, and nothing depends on the user row being flagged.
 */
class erasure {
    /** @var api_client|null Set by tests; a real client is built otherwise. */
    protected static $client = null;

    /**
     * Hand the class a client, for tests.
     *
     * @param api_client|null $client Null goes back to a real one.
     *
     * @return void
     */
    public static function set_client(?api_client $client): void {
        self::$client = $client;
    }

    /**
     * Tell Report LMS to drop these people, before anything is deleted here.
     *
     * Nothing is sent for an empty list. A site that is not connected has no
     * key to send with, so there is nothing to tell and the local deletion may
     * go ahead.
     *
     * @param array<int, int|string> $userids
     *
     * @return void
     * @throws \moodle_exception When Report LMS does not accept the request, so the caller keeps its rows.
     */
    public static function send(array $userids): void {
        $userids = array_map('intval', $userids);
        $userids = array_values(array_unique(array_filter($userids, static fn(int $id): bool => $id > 0)));

        if (!$userids || !connect_manager::is_connected()) {
            return;
        }

        sort($userids);

        $client = self::$client ?? new api_client(config::baseurl());
        $client->push_payload(batch_builder::erasure_payload($userids, time()));
    }
}
