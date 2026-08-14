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
 * The plugin's own user profile fields.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\org;

/**
 * Creates and watches the rlms_function profile field.
 *
 * There used to be three. `rlms_sector` and `rlms_department` were what the
 * organisation tree was derived from, and the tree is now stated in the mapping
 * tables instead, so nothing reads them. They are not deleted: they hold a
 * customer's own records, written there by an HR export this plugin does not
 * own, and removing a profile field in Moodle removes every value with it. They
 * are simply no longer created, no longer locked and no longer read, and a site
 * that has them keeps them.
 *
 * `rlms_function` stays because function is an attribute of a person rather
 * than a place in the tree: the aggregation slices by it and the organisation
 * does not depend on it.
 *
 * The rlms_ prefix is the whole clash story: a customer site can hold any field
 * names it likes, and this one can never collide with them. An administrator
 * who prefers the site's own field re-maps in the advanced settings.
 *
 * A text field, deliberately: the plugin cannot know a customer's vocabulary,
 * so it cannot offer a menu. The cost is that a typo mints a new function
 * value, and the seed sites use menu fields and will not show that failure, so
 * do not let green QA there stand in for it.
 */
class profile_fields {
    /** @var array<string, string> Shortname to the lang key of its display name. */
    private const FIELDS = [
        'rlms_function' => 'profilefield:function',
    ];

    /**
     * Create the category and the field, idempotently.
     *
     * A field whose shortname already exists is left exactly as it is: not
     * recreated, not retyped, and not locked.
     *
     * Safe from db/install.php under web and CLI alike: pure $DB, no $USER,
     * no events, no cache purge.
     */
    public static function ensure(): void {
        global $CFG, $DB;

        // For PROFILE_VISIBLE_NONE.
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $categoryid = null;
        $sortorder = 0;

        foreach (self::FIELDS as $shortname => $langkey) {
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }

            // Resolved on first need, not up front: on a site where every
            // field already exists, ensure() must not leave an empty
            // category behind as its only trace.
            if ($categoryid === null) {
                $categoryid = self::category_id();
                $sortorder = (int) $DB->get_field_sql(
                    'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_field} WHERE categoryid = ?',
                    [$categoryid]
                );
            }

            /*
             * A full row, never a partial one: Moodle reads param1 and param2
             * for a text field, and a half-built record renders the admin edit
             * form with a zero-length input.
             *
             * Hidden from the learner's own profile (the dashboard is where
             * this data is read), but PROFILE_VISIBLE_NONE does not impede
             * administrators: editability falls through to moodle/user:update.
             *
             * **Created unlocked.** The plugin used to lock whichever field
             * the department mapping pointed at, so a learner could not edit
             * their own place in the organisation. Nothing derives a placement
             * from a profile field any more, so editing one moves nobody, and a
             * lock on a customer's field that the plugin cannot explain is
             * exactly the debris an uninstall exists to prevent.
             */
            $DB->insert_record('user_info_field', (object) [
                'shortname' => $shortname,
                'name' => get_string($langkey, 'local_reportlms'),
                'categoryid' => $categoryid,
                'datatype' => 'text',
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'sortorder' => ++$sortorder,
                'required' => 0,
                'locked' => 0,
                'visible' => PROFILE_VISIBLE_NONE,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_MOODLE,
                'param1' => 30,
                'param2' => 2048,
            ]);
        }
    }

    /**
     * The function mapping, if it names a field this site does not have.
     *
     * Checked on the effective shortname, not only the configured one: an unset
     * mapping falls back to the rlms_ field, and if that is somehow absent the
     * function axis is just as empty as with a bad explicit mapping.
     *
     * @return array<string, string> role => missing shortname.
     */
    public static function missing_mappings(): array {
        global $DB;

        $shortname = org::function_field();

        if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
            return [];
        }

        return ['function' => $shortname];
    }

    /**
     * Options for one mapping select on the settings page.
     *
     * A denylist, not an allowlist: profile field types are pluggable, and
     * the only ones excluded are those whose stored value is not a plain
     * label. Two properties are load-bearing. The configured value always
     * appears, even when its field no longer exists, otherwise opening the
     * page and pressing Save would silently re-map to whatever option came
     * first. And the empty option names the plugin's own field, which is
     * exactly what an empty config falls back to in org::function_field().
     *
     * @param string $role Only 'function' remains.
     *
     * @return array<string, string> value => label.
     */
    public static function field_options(string $role): array {
        global $DB;

        $default = 'rlms_' . $role;

        // The default option is labelled like every other entry, the field's
        // own name, because to an administrator it IS just a field; "Report
        // LMS field" was a mechanism name leaking into a menu. The lang string
        // only steps in when the field is somehow gone.
        $defaultfield = $DB->get_record('user_info_field', ['shortname' => $default], 'id, name');
        $options = [
            '' => $defaultfield
                ? $defaultfield->name . ' (' . $default . ')'
                : get_string('settings:orgfield_plugin', 'local_reportlms', $default),
        ];

        $records = $DB->get_records_select(
            'user_info_field',
            "datatype NOT IN ('textarea', 'checkbox', 'datetime')",
            null,
            'name ASC',
            'id, shortname, name'
        );
        foreach ($records as $record) {
            // The empty option above already means this exact field, and one
            // field offered twice under two labels reads as two fields.
            if ($record->shortname === $default) {
                continue;
            }
            $options[$record->shortname] = $record->name . ' (' . $record->shortname . ')';
        }

        // A configured value must always be an option, or the browser would
        // silently submit whatever came first. Two ways it can be absent from
        // the list: it names the excluded default explicitly, or its field is
        // gone, only the second is worth an alarm label.
        $configured = (string) get_config('local_reportlms', 'orgfield_' . $role);
        if ($configured !== '' && !isset($options[$configured])) {
            $field = $DB->get_record('user_info_field', ['shortname' => $configured], 'id, shortname, name');
            $options[$configured] = $field
                ? $field->name . ' (' . $field->shortname . ')'
                : get_string('settings:orgfield_missing', 'local_reportlms', $configured);
        }

        return $options;
    }

    /**
     * The plugin's profile field category, created on first need.
     *
     * The id is pinned in config rather than looked up by name: names are
     * language-dependent and admin-editable, and a rename must not make a
     * second ensure() create a duplicate category.
     */
    private static function category_id(): int {
        global $DB;

        $pinned = (int) get_config('local_reportlms', 'profilecategoryid');
        if ($pinned && $DB->record_exists('user_info_category', ['id' => $pinned])) {
            return $pinned;
        }

        $categoryid = (int) $DB->insert_record('user_info_category', (object) [
            'name' => get_string('profilefield:category', 'local_reportlms'),
            'sortorder' => (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {user_info_category}'
            ),
        ]);
        set_config('profilecategoryid', $categoryid, 'local_reportlms');

        return $categoryid;
    }
}
