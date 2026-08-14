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
 * Upgrade steps for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply the upgrade steps.
 *
 * @param int $oldversion Version currently installed.
 * @return bool
 */
function xmldb_local_reportlms_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080300) {
        $table = new xmldb_table('local_reportlms_userstate');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('dirty', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timechanged', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timepushed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);
        $table->add_index('dirty', XMLDB_INDEX_NOTUNIQUE, ['dirty']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080300, 'local', 'reportlms');
    }

    if ($oldversion < 2026080402) {
        // Sites connected before the block was identified by its own contents
        // can carry a copy per uninstall-and-reconnect cycle, with only the
        // last one removable. Clear the extras and re-record what is left.
        \local_reportlms\local\dashboard_block::deduplicate();

        // And bring the survivors up to the current markup, which now carries
        // the product mark. Never creates one: a site that removed the block on
        // purpose stays without it.
        \local_reportlms\local\dashboard_block::refresh();

        upgrade_plugin_savepoint(true, 2026080402, 'local', 'reportlms');
    }

    if ($oldversion < 2026080500) {
        /*
         * This step used to lock the mapped department profile field, so a
         * learner could not edit their own place in the organisation.
         *
         * Emptied rather than deleted, because deleting it would renumber
         * nothing and confuse anybody reading the history. Two reasons it had
         * to stop doing anything. Nothing derives a placement from a profile
         * field any more, so editing one moves nobody. And it set the lock
         * without recording that the plugin had set it, which is precisely the
         * lock nothing can safely undo: the step below releases the locks the
         * plugin wrote down, and it could never have released this one.
         */
        upgrade_plugin_savepoint(true, 2026080500, 'local', 'reportlms');
    }

    if ($oldversion < 2026080505) {
        // The block body gained a settings cog, now a theme icon lifted into
        // the card corner, so bring existing blocks up to it. Never creates
        // one: a site that removed the block on purpose stays without it.
        \local_reportlms\local\dashboard_block::refresh();

        upgrade_plugin_savepoint(true, 2026080505, 'local', 'reportlms');
    }

    if ($oldversion < 2026080510) {
        // The stored block markup carried a t/cog icon, which 404s on themes
        // that do not ship it. Rewrite it with one every theme has.
        \local_reportlms\local\dashboard_block::refresh();

        upgrade_plugin_savepoint(true, 2026080510, 'local', 'reportlms');
    }

    if ($oldversion < 2026080613) {
        /*
         * Take :viewall back off the manager archetype.
         *
         * It shipped as a manager default, which meant every category and
         * course manager could see the whole organisation, and the scoping the
         * rest of the plugin does was decorative for them. Seeing everybody has
         * to be a deliberate grant, not something inherited by being made
         * manager of one course.
         *
         * Changing db/access.php alone does not do this: Moodle applies
         * archetype defaults when a capability is first installed and never
         * revisits them, so every site that already upgraded keeps the grant.
         *
         * Scoped to roles that still carry the manager archetype, and only
         * where the permission is exactly the default this plugin set. A site
         * that deliberately granted it to a custom HR or auditor role is
         * untouched, and so is a manager role somebody explicitly set to
         * prevent or prohibit.
         */
        $systemcontext = \context_system::instance();

        foreach ($DB->get_records('role', ['archetype' => 'manager'], '', 'id') as $role) {
            $existing = $DB->get_record('role_capabilities', [
                'roleid' => $role->id,
                'capability' => 'local/reportlms:viewall',
                'contextid' => $systemcontext->id,
            ]);

            if ($existing && (int) $existing->permission === CAP_ALLOW) {
                // Moodle's own API rather than a delete, so the capability
                // caches are invalidated with it instead of serving the old
                // answer until something else happens to bump them.
                unassign_capability('local/reportlms:viewall', $role->id, $systemcontext->id);
            }
        }

        upgrade_plugin_savepoint(true, 2026080613, 'local', 'reportlms');
    }

    if ($oldversion < 2026081000) {
        /*
         * :view becomes the capability that makes somebody a manager, and
         * :manage becomes the one that edits the mapping.
         *
         * Order is the whole of this step. Grant first, revoke second: a role
         * that currently allows :manage belongs to somebody who is a manager
         * today, and they must still be one when this finishes. Revoking first
         * would take their access away for however long the rest of the upgrade
         * runs, and a failure in between would leave it gone.
         *
         * Then :manage is removed from those same roles. Its old holders were
         * managers, not mapping editors, and letting them silently become
         * mapping editors is exactly the quiet widening this change exists to
         * stop. Anyone who should edit the tree gets :manage granted on
         * purpose, once, by somebody who meant it.
         *
         * Untouched on purpose: a role that already allows :view keeps it, and
         * one that explicitly prevents or prohibits either capability keeps
         * that too. This only ever adds an allow that mirrors an existing one.
         */
        $systemcontext = \context_system::instance();

        $holders = $DB->get_records('role_capabilities', [
            'capability' => 'local/reportlms:manage',
            'permission' => CAP_ALLOW,
        ]);

        foreach ($holders as $row) {
            $existing = $DB->get_record('role_capabilities', [
                'roleid' => $row->roleid,
                'capability' => 'local/reportlms:view',
                'contextid' => $row->contextid,
            ]);

            // Never overwrite a deliberate prevent or prohibit.
            if (!$existing) {
                assign_capability(
                    'local/reportlms:view',
                    CAP_ALLOW,
                    $row->roleid,
                    $row->contextid
                );
            }
        }

        foreach ($holders as $row) {
            unassign_capability('local/reportlms:manage', $row->roleid, $row->contextid);
        }

        if ($holders) {
            mtrace('Report LMS: moved ' . count($holders) . ' role(s) from :manage to :view.');
        }

        unset($systemcontext);

        upgrade_plugin_savepoint(true, 2026081000, 'local', 'reportlms');
    }

    if ($oldversion < 2026081006) {
        /*
         * The hand-built organisation: units, who is in them, who runs them.
         *
         * install.xml only runs on a fresh install, so an upgrading site needs
         * the tables created here as well. Guarded on each one existing rather
         * than on the version alone, because a site that installed at exactly
         * this version already has them.
         */
        $dbman = $DB->get_manager();

        foreach (['local_reportlms_node', 'local_reportlms_member', 'local_reportlms_manager'] as $tablename) {
            $table = new xmldb_table($tablename);

            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml',
                    $tablename
                );
            }
        }

        upgrade_plugin_savepoint(true, 2026081006, 'local', 'reportlms');
    }

    if ($oldversion < 2026081103) {
        // The cog now points at admin.php, which routes each viewer to the
        // admin surface they may open. Stored markup, so a refresh, the same
        // step the two markup changes above needed.
        \local_reportlms\local\dashboard_block::refresh();

        upgrade_plugin_savepoint(true, 2026081103, 'local', 'reportlms');
    }

    if ($oldversion < 2026081401) {
        /*
         * Give back the profile field the plugin locked.
         *
         * It locked whichever field the department mapping pointed at, so a
         * learner could not edit the value their place in the tree was derived
         * from. Nothing is derived from a profile field now, so the lock
         * protects nothing and stops an administrator editing a field that
         * belongs to the customer, not to this plugin.
         *
         * Only the lock the plugin recorded as its own. A lock the customer set
         * themselves is recorded nowhere and is therefore never touched, which
         * is the same rule db/uninstall.php follows.
         */
        $locked = (string) get_config('local_reportlms', 'lockedfield');

        if ($locked !== '') {
            $field = $DB->get_record('user_info_field', ['shortname' => $locked], 'id, locked');

            if ($field && !empty($field->locked)) {
                $DB->set_field('user_info_field', 'locked', 0, ['id' => $field->id]);
            }

            unset_config('lockedfield', 'local_reportlms');
        }

        // The two settings that chose which fields the tree came from. The
        // fields themselves stay: they hold the customer's own records.
        unset_config('orgfield_sector', 'local_reportlms');
        unset_config('orgfield_department', 'local_reportlms');
        unset_config('managerfallback', 'local_reportlms');

        upgrade_plugin_savepoint(true, 2026081401, 'local', 'reportlms');
    }

    if ($oldversion < 2026081402) {
        /*
         * The two fields the organisation is published into.
         *
         * They were retired one version ago, when nothing read them and nothing
         * wrote them. They are back with the opposite job: the tree used to be
         * derived from them, and it is now copied into them. ensure() skips a
         * shortname that already exists, so a site that still carries its
         * originals keeps them exactly as they are, values and all.
         */
        \local_reportlms\local\profile_fields::ensure();

        /*
         * The old single lock record becomes the department's.
         *
         * There was one lock and one field it could point at, and that field
         * was the department. Two levels can each own one now, so a value left
         * over from before belongs to the level it always meant.
         */
        $locked = (string) get_config('local_reportlms', 'lockedfield');

        if ($locked !== '') {
            set_config('lockedfield_department', $locked, 'local_reportlms');
            unset_config('lockedfield', 'local_reportlms');
        }

        upgrade_plugin_savepoint(true, 2026081402, 'local', 'reportlms');
    }

    return true;
}
