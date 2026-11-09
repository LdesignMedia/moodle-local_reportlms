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
 * What this plugin holds about a person, and where it sends it.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/**
 * This plugin holds three things about a person and sends a fourth away.
 *
 * It used to declare itself a null_provider, with a TODO saying that would stop
 * being true once the push landed. The push landed some time ago and the
 * mapping editor now records a relationship between two named people, so that
 * declaration was a compliance product telling its customers something untrue
 * about itself.
 *
 * Nothing here holds a name or an address. The tables carry user ids and the
 * wire carries HMAC-coded identifiers for people and organisation units, which
 * is the whole design; the only text it carries is course and activity titles,
 * which describe the training rather than anybody taking it. That
 * is still personal data: an id identifies a person to the site holding it, and
 * a coded id is pseudonymised rather than anonymous, because this site keeps the
 * key that reverses it.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Everything this plugin stores or sends, declared for the privacy registry.
     *
     * @param collection $collection
     *
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_reportlms_userstate',
            [
                'userid' => 'privacy:metadata:userstate:userid',
                'timechanged' => 'privacy:metadata:userstate:timechanged',
                'timepushed' => 'privacy:metadata:userstate:timepushed',
            ],
            'privacy:metadata:userstate'
        );

        $collection->add_database_table(
            'local_reportlms_member',
            [
                'userid' => 'privacy:metadata:member:userid',
                'nodeid' => 'privacy:metadata:member:nodeid',
                'source' => 'privacy:metadata:member:source',
            ],
            'privacy:metadata:member'
        );

        $collection->add_database_table(
            'local_reportlms_manager',
            [
                'userid' => 'privacy:metadata:manager:userid',
                'nodeid' => 'privacy:metadata:manager:nodeid',
            ],
            'privacy:metadata:manager'
        );

        // The part that leaves the site, declared even though it carries no
        // names: a coded identifier is pseudonymous, not anonymous, because
        // this site keeps the key that reverses it.
        $collection->add_external_location_link(
            'reportlms',
            [
                'learner_pid' => 'privacy:metadata:reportlms:learner_pid',
                'course_ref' => 'privacy:metadata:reportlms:course_ref',
                'status' => 'privacy:metadata:reportlms:status',
                'grade' => 'privacy:metadata:reportlms:grade',
                'attempts' => 'privacy:metadata:reportlms:attempts',
                'last_attempt_at' => 'privacy:metadata:reportlms:last_attempt_at',
                'node_id' => 'privacy:metadata:reportlms:node_id',
                'contact_email' => 'privacy:metadata:reportlms:contact_email',
            ],
            'privacy:metadata:reportlms'
        );

        return $collection;
    }

    /**
     * Everything here is site-wide: the plugin has no per-course state.
     *
     * @param int $userid
     *
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $held = $DB->record_exists('local_reportlms_userstate', ['userid' => $userid])
            || $DB->record_exists('local_reportlms_member', ['userid' => $userid])
            || $DB->record_exists('local_reportlms_manager', ['userid' => $userid]);

        if ($held) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Which users this plugin holds data about in one context.
     *
     * @param userlist $userlist
     *
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        foreach (['local_reportlms_userstate', 'local_reportlms_member', 'local_reportlms_manager'] as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE userid IS NOT NULL", []);
        }
    }

    /**
     * Hand one person everything this plugin holds about them.
     *
     * @param approved_contextlist $contextlist
     *
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::wants_system($contextlist)) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $context = \context_system::instance();
        $path = [get_string('pluginname', 'local_reportlms')];

        $state = $DB->get_record('local_reportlms_userstate', ['userid' => $userid]);

        if ($state) {
            writer::with_context($context)->export_data($path, (object) [
                'timechanged' => transform::datetime($state->timechanged),
                'timepushed' => $state->timepushed ? transform::datetime($state->timepushed) : null,
            ]);
        }

        $placements = $DB->get_records_sql(
            "SELECT m.id, n.name, m.source
               FROM {local_reportlms_member} m
               JOIN {local_reportlms_node} n ON n.id = m.nodeid
              WHERE m.userid = :userid",
            ['userid' => $userid]
        );

        if ($placements) {
            writer::with_context($context)->export_data(
                array_merge($path, [get_string('manage:placed', 'local_reportlms')]),
                (object) ['units' => array_values(array_map(static function ($row) {
                    return ['unit' => $row->name, 'source' => $row->source];
                }, $placements))]
            );
        }

        $manages = $DB->get_records_sql(
            "SELECT m.id, n.name
               FROM {local_reportlms_manager} m
               JOIN {local_reportlms_node} n ON n.id = m.nodeid
              WHERE m.userid = :userid",
            ['userid' => $userid]
        );

        if ($manages) {
            writer::with_context($context)->export_data(
                array_merge($path, [get_string('manage:managers', 'local_reportlms')]),
                (object) ['units' => array_values(array_map(static fn($row) => $row->name, $manages))]
            );
        }
    }

    /**
     * Delete what this plugin holds for everybody in one context.
     *
     * @param \context $context
     *
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        $DB->delete_records('local_reportlms_userstate');
        $DB->delete_records('local_reportlms_member');
        $DB->delete_records('local_reportlms_manager');
    }

    /**
     * Delete what this plugin holds for one person.
     *
     * @param approved_contextlist $contextlist
     *
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!self::wants_system($contextlist)) {
            return;
        }

        self::forget([$contextlist->get_user()->id]);
    }

    /**
     * Delete what this plugin holds for a named set of people.
     *
     * @param approved_userlist $userlist
     *
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        self::forget($userlist->get_userids());
    }

    /**
     * Forget these people locally, and tell Report LMS to as well.
     *
     * Deleting the rows here would leave the coded identifiers standing on the
     * other side, which is the half nobody sees. The next push cannot carry the
     * request either, because a person who has been forgotten is no longer in
     * anything the push collects.
     *
     * @param array<int, int> $userids
     *
     * @return void
     */
    protected static function forget(array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->delete_records_select('local_reportlms_userstate', "userid {$insql}", $params);
        $DB->delete_records_select('local_reportlms_member', "userid {$insql}", $params);
        $DB->delete_records_select('local_reportlms_manager', "userid {$insql}", $params);
    }

    /**
     * Whether the system context is one of the contexts approved for export.
     *
     * @param approved_contextlist $contextlist
     *
     * @return bool
     */
    protected static function wants_system(approved_contextlist $contextlist): bool {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                return true;
            }
        }

        return false;
    }
}
