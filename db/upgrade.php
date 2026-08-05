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
        // Lock the mapped department field, so a learner cannot edit their own.
        // Moodle then allows only moodle/user:update holders to change it.
        //
        // Belt and braces on purpose: a managed learner already takes their
        // manager's department whatever their own value says, so this is what
        // protects the learners nobody manages. It affects the edit form only,
        // so an HR upload or a web service sync keeps writing normally.
        $shortname = \local_reportlms\local\collector\org::fieldmap()['department'];
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);

        if ($field && empty($field->locked)) {
            $DB->set_field('user_info_field', 'locked', 1, ['id' => $field->id]);
        }

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

    return true;
}
