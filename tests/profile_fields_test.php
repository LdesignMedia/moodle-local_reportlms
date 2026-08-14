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
 * Tests for the plugin-created profile fields and their mappings.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\org;
use local_reportlms\local\profile_fields;

/**
 * The one profile field this plugin still creates, and the two it left behind.
 *
 * @covers \local_reportlms\local\profile_fields
 */
final class profile_fields_test extends \advanced_testcase {
    /** @var string[] The shortnames install creates. */
    private const SHORTNAMES = ['rlms_function'];

    /** @var string[] The two it used to create, and now leaves alone. */
    private const RETIRED = ['rlms_sector', 'rlms_department'];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        // db/install.php already ran when the phpunit baseline was built, so
        // these exist before any test starts. Testing creation against a
        // baseline that contains the result tests nothing: start clean.
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_merge(self::SHORTNAMES, self::RETIRED));
        $DB->delete_records_select('user_info_field', "shortname {$insql}", $params);
        $categoryid = (int) get_config('local_reportlms', 'profilecategoryid');
        if ($categoryid) {
            $DB->delete_records('user_info_category', ['id' => $categoryid]);
        }
        unset_config('profilecategoryid', 'local_reportlms');
    }

    public function test_ensure_creates_the_category_and_a_whole_field(): void {
        global $DB;

        profile_fields::ensure();

        $categoryid = (int) get_config('local_reportlms', 'profilecategoryid');
        $this->assertTrue($DB->record_exists('user_info_category', ['id' => $categoryid]));

        foreach (self::SHORTNAMES as $shortname) {
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname], '*', MUST_EXIST);
            $this->assertSame('text', $field->datatype);
            // Created unlocked. Nothing derives a placement from a profile
            // field any more, so a learner editing one moves nobody, and a lock
            // the plugin cannot explain is debris.
            $this->assertEquals(0, $field->locked, "{$shortname} must not be locked.");
            $this->assertEquals(PROFILE_VISIBLE_NONE, $field->visible);
            $this->assertEquals($categoryid, $field->categoryid);
            // A half-built text field renders the admin edit form with a
            // zero-length input, and that form is the one hand-editing surface
            // a locked field leaves open.
            $this->assertNotEmpty($field->param1);
            $this->assertNotEmpty($field->param2);
        }
    }

    public function test_ensure_twice_creates_nothing_extra(): void {
        global $DB;

        profile_fields::ensure();
        profile_fields::ensure();

        [$insql, $params] = $DB->get_in_or_equal(self::SHORTNAMES);
        $this->assertSame(1, $DB->count_records_select('user_info_field', "shortname {$insql}", $params));
        $this->assertSame(1, $DB->count_records('user_info_category', [
            'id' => (int) get_config('local_reportlms', 'profilecategoryid'),
        ]));
    }

    public function test_no_empty_category_when_every_field_already_exists(): void {
        global $DB;

        profile_fields::ensure();
        $DB->delete_records('user_info_category', ['id' => (int) get_config('local_reportlms', 'profilecategoryid')]);
        unset_config('profilecategoryid', 'local_reportlms');

        // The field exists, so a second ensure() has nothing to create and
        // must not leave an empty category as its only trace.
        profile_fields::ensure();

        $this->assertFalse(get_config('local_reportlms', 'profilecategoryid'));
    }

    public function test_a_preexisting_field_is_left_exactly_as_it_is(): void {
        global $DB;

        // A customer field that happens to carry our shortname: unlocked,
        // their own name, their own type.
        $theirs = (object) [
            'shortname' => 'rlms_function', 'name' => 'Functie (HR)', 'categoryid' => 1,
            'datatype' => 'menu', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'sortorder' => 1, 'required' => 0, 'locked' => 0, 'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0, 'signup' => 0, 'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE,
            'param1' => "A\nB", 'param2' => '',
        ];
        $theirsid = $DB->insert_record('user_info_field', $theirs);

        profile_fields::ensure();

        $after = $DB->get_record('user_info_field', ['id' => $theirsid], '*', MUST_EXIST);
        $this->assertSame('Functie (HR)', $after->name);
        $this->assertSame('menu', $after->datatype);
        $this->assertEquals(0, $after->locked, 'ensure() must never lock a field it did not create.');
        $this->assertSame(1, $DB->count_records('user_info_field', ['shortname' => 'rlms_function']));
    }

    /**
     * The two fields the organisation used to be derived from are not created
     * and not deleted.
     *
     * They hold a customer's own records, written by an HR export this plugin
     * does not own, and deleting a profile field in Moodle deletes every value
     * in it. So a site that has them keeps them, and a new site never gets them.
     */
    public function test_the_retired_fields_are_neither_created_nor_removed(): void {
        global $DB;

        $existingid = $DB->insert_record('user_info_field', (object) [
            'shortname' => 'rlms_department', 'name' => 'Afdeling', 'categoryid' => 1,
            'datatype' => 'text', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'sortorder' => 1, 'required' => 0, 'locked' => 0, 'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0, 'signup' => 0, 'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 30, 'param2' => 2048,
        ]);

        profile_fields::ensure();

        $this->assertTrue($DB->record_exists('user_info_field', ['id' => $existingid]));
        $this->assertFalse($DB->record_exists('user_info_field', ['shortname' => 'rlms_sector']));
    }

    public function test_the_defaults_line_up_with_what_install_creates(): void {
        profile_fields::ensure();

        $this->assertContains(org::function_field(), self::SHORTNAMES);
        $this->assertSame([], profile_fields::missing_mappings());
    }

    public function test_a_remapped_function_changes_what_the_collector_reads(): void {
        global $DB;

        profile_fields::ensure();

        // The customer's own field, mapped in place of ours.
        $categoryid = (int) get_config('local_reportlms', 'profilecategoryid');
        $fieldid = $DB->insert_record('user_info_field', (object) [
            'shortname' => 'functie', 'name' => 'Functie', 'categoryid' => $categoryid,
            'datatype' => 'text', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'sortorder' => 9, 'required' => 0, 'locked' => 0, 'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0, 'signup' => 0, 'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 30, 'param2' => 2048,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id, 'fieldid' => $fieldid, 'data' => 'Magazijnmedewerker', 'dataformat' => 0,
        ]);

        set_config('orgfield_function', 'functie', 'local_reportlms');

        $state = org::collect();
        $functions = array_map(fn($a) => $a->function, $state->attrs);

        $this->assertContains('Magazijnmedewerker', $functions);
    }

    public function test_missing_mappings_reports_a_dead_explicit_mapping(): void {
        profile_fields::ensure();
        set_config('orgfield_function', 'weg_gegooid', 'local_reportlms');

        $this->assertSame(['function' => 'weg_gegooid'], profile_fields::missing_mappings());
    }

    public function test_the_options_can_never_silently_remap(): void {
        profile_fields::ensure();

        // The configured value stays offered even when its field is gone,
        // labelled as missing, otherwise opening the page and pressing Save
        // would submit whatever option happened to come first.
        set_config('orgfield_function', 'weg_gegooid', 'local_reportlms');
        $options = profile_fields::field_options('function');

        $this->assertArrayHasKey('weg_gegooid', $options);
        $this->assertArrayHasKey('', $options);
    }

    public function test_the_plugin_field_is_offered_once_not_twice(): void {
        profile_fields::ensure();

        // The empty option already means the plugin's own field, so listing
        // rlms_function as well offers one field under two labels.
        $options = profile_fields::field_options('function');
        $this->assertArrayNotHasKey('rlms_function', $options);

        // Unless somebody pinned it explicitly, in which case the select must
        // carry the value to be able to show it.
        set_config('orgfield_function', 'rlms_function', 'local_reportlms');
        $this->assertArrayHasKey('rlms_function', profile_fields::field_options('function'));
    }

    public function test_the_options_exclude_types_that_are_not_labels(): void {
        global $DB;

        profile_fields::ensure();
        $DB->insert_record('user_info_field', (object) [
            'shortname' => 'bio', 'name' => 'Bio', 'categoryid' => 1,
            'datatype' => 'textarea', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'sortorder' => 1, 'required' => 0, 'locked' => 0, 'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0, 'signup' => 0, 'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE,
            'param1' => '', 'param2' => '',
        ]);

        $this->assertArrayNotHasKey('bio', profile_fields::field_options('function'));
    }
}
