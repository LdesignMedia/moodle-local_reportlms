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
 * Publishing the organisation back into user profile fields.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\org;
use local_reportlms\local\mapping;
use local_reportlms\local\profile_fields;
use local_reportlms\local\writeback;

/**
 * The only place this plugin writes into data a customer can see.
 *
 * So the tests split in two. Most are about the value being right: the name and
 * not the key, both levels, and a blank rather than a stale department for
 * somebody who left. The last few are about the write being quiet, which is the
 * failure that would take a site down rather than merely mislead it.
 *
 * @covers \local_reportlms\local\writeback
 */
final class writeback_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/user/profile/lib.php');

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        profile_fields::ensure();
        mapping::reset();
    }

    /**
     * A department under a sector publishes both levels, and neither of them is
     * the node key.
     */
    public function test_a_department_under_a_sector_publishes_both_levels(): void {
        $learner = $this->getDataGenerator()->create_user();

        $sector = mapping::save_node('Operations', 'sector');
        $department = mapping::save_node('Rotterdam', 'department', $sector);
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        writeback::apply();

        $this->assertSame('Rotterdam', $this->value_of($learner, 'rlms_department'));
        $this->assertSame('Operations', $this->value_of($learner, 'rlms_sector'));
    }

    /**
     * A department at the root publishes the department and leaves the sector
     * empty, rather than inventing one.
     */
    public function test_a_department_at_the_root_publishes_no_sector(): void {
        $learner = $this->getDataGenerator()->create_user();

        $department = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        writeback::apply();

        $this->assertSame('Rotterdam', $this->value_of($learner, 'rlms_department'));
        $this->assertSame('', $this->value_of($learner, 'rlms_sector'));
    }

    /**
     * Somebody placed on a sector directly is in that sector and no department.
     *
     * An unusual shape and a real one: a unit that has people before it has any
     * departments under it.
     */
    public function test_somebody_placed_on_a_sector_publishes_only_the_sector(): void {
        $learner = $this->getDataGenerator()->create_user();

        $sector = mapping::save_node('Operations', 'sector');
        mapping::place((int) $learner->id, $sector);
        mapping::reset();

        writeback::apply();

        $this->assertSame('Operations', $this->value_of($learner, 'rlms_sector'));
        $this->assertSame('', $this->value_of($learner, 'rlms_department'));
    }

    /**
     * Leaving a unit blanks the field rather than leaving it naming the place
     * they left, which is the whole reason the field is worth maintaining.
     */
    public function test_leaving_a_unit_blanks_the_field(): void {
        $learner = $this->getDataGenerator()->create_user();

        $department = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        writeback::apply();
        $this->assertSame('Rotterdam', $this->value_of($learner, 'rlms_department'));

        mapping::release((int) $learner->id);
        mapping::reset();

        writeback::apply();

        $this->assertSame('', $this->value_of($learner, 'rlms_department'));
    }

    /**
     * Somebody nobody has placed gets no row at all.
     *
     * An empty row per unplaced person would be thousands of records saying the
     * same thing as their absence.
     */
    public function test_an_unplaced_learner_gets_no_row(): void {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();

        writeback::apply();

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'rlms_department']);

        $this->assertFalse($DB->record_exists('user_info_data', [
            'userid' => $learner->id,
            'fieldid' => $fieldid,
        ]));
    }

    /**
     * A second run writes nothing, because nothing differs. This is what makes
     * it safe to call on every cron.
     */
    public function test_a_second_run_writes_nothing(): void {
        $learner = $this->getDataGenerator()->create_user();

        $sector = mapping::save_node('Operations', 'sector');
        $department = mapping::save_node('Rotterdam', 'department', $sector);
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        $this->assertGreaterThan(0, writeback::apply());
        $this->assertSame(0, writeback::apply());
    }

    /**
     * Turning one level off leaves the other one working.
     */
    public function test_one_level_can_be_switched_off_alone(): void {
        $learner = $this->getDataGenerator()->create_user();

        $sector = mapping::save_node('Operations', 'sector');
        $department = mapping::save_node('Rotterdam', 'department', $sector);
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        set_config(writeback::SETTING_SECTOR, '', 'local_reportlms');

        writeback::apply();

        $this->assertSame('Rotterdam', $this->value_of($learner, 'rlms_department'));
        $this->assertSame('', $this->value_of($learner, 'rlms_sector'));
    }

    /**
     * An unsaved setting is the default, not "off".
     *
     * Every existing site is in this state the moment this ships. Reading a
     * missing config as off would mean the feature only starts once somebody
     * opens the settings page and presses Save.
     */
    public function test_an_unsaved_setting_means_the_plugin_field(): void {
        $this->assertSame(
            ['sector' => 'rlms_sector', 'department' => 'rlms_department'],
            writeback::targets()
        );
    }

    /**
     * The function field is refused, and its values survive.
     *
     * Publishing a department into it would overwrite everybody's job title,
     * and the aggregation slices by that field, so the damage would surface as
     * a report that looks plausible and is wrong.
     */
    public function test_the_function_field_is_refused(): void {
        $learner = $this->getDataGenerator()->create_user();

        $department = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        $this->set_value($learner, org::function_field(), 'Machinist');
        set_config(writeback::SETTING_DEPARTMENT, org::function_field(), 'local_reportlms');

        writeback::apply();

        $this->assertNull(writeback::targets()['department']);
        $this->assertSame('Machinist', $this->value_of($learner, org::function_field()));
    }

    /**
     * Both levels naming one field is refused, or the two would overwrite each
     * other every run and the value would flicker.
     */
    public function test_both_levels_naming_one_field_is_refused(): void {
        set_config(writeback::SETTING_SECTOR, 'rlms_department', 'local_reportlms');
        set_config(writeback::SETTING_DEPARTMENT, 'rlms_department', 'local_reportlms');

        $targets = writeback::targets();

        $this->assertNull($targets['sector'], 'The department is the level every site has.');
        $this->assertSame('rlms_department', $targets['department']);
    }

    /**
     * A field that no longer exists is refused rather than recreated.
     */
    public function test_a_missing_field_is_refused(): void {
        global $DB;

        set_config(writeback::SETTING_DEPARTMENT, 'weg_gegooid', 'local_reportlms');

        $this->assertNull(writeback::targets()['department']);
        $this->assertFalse($DB->record_exists('user_info_field', ['shortname' => 'weg_gegooid']));
    }

    /**
     * The write raises no user_updated event.
     *
     * The one failure that would take a site down rather than mislead it.
     * db/events.php turns that event into a dirty learner, so publishing
     * through the profile API would mark everybody dirty, force a push,
     * publish again, and never settle.
     */
    public function test_the_write_raises_no_user_updated_event(): void {
        $learner = $this->getDataGenerator()->create_user();

        $department = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        $sink = $this->redirectEvents();

        writeback::apply();

        $raised = [];

        foreach ($sink->get_events() as $event) {
            $raised[] = get_class($event);
        }

        $sink->close();

        $this->assertNotContains(\core\event\user_updated::class, $raised);
        $this->assertSame([], $raised, 'Publishing must be silent.');
    }

    /**
     * And it does not move the org state hash, so a push is not provoked by the
     * thing the push just did.
     */
    public function test_the_write_does_not_move_the_org_hash(): void {
        $learner = $this->getDataGenerator()->create_user();

        $department = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $department);
        mapping::reset();

        $before = org::state_hash(org::collect());

        writeback::apply();
        mapping::reset();

        $this->assertSame($before, org::state_hash(org::collect()));
    }

    /**
     * The plugin's own fields are hidden, so they are never locked.
     */
    public function test_the_plugins_own_fields_are_never_locked(): void {
        global $DB;

        writeback::follow_locks();

        foreach (writeback::OWN_FIELDS as $shortname) {
            $this->assertEquals(0, $DB->get_field('user_info_field', 'locked', ['shortname' => $shortname]));
        }

        $this->assertFalse(get_config('local_reportlms', 'lockedfield_department'));
    }

    /**
     * A field the administrator chose is locked, recorded, and given back when
     * they choose another.
     */
    public function test_a_chosen_field_is_locked_and_released(): void {
        global $DB;

        $theirs = $this->make_field('afdeling');

        set_config(writeback::SETTING_DEPARTMENT, 'afdeling', 'local_reportlms');
        writeback::follow_locks();

        $this->assertEquals(1, $DB->get_field('user_info_field', 'locked', ['id' => $theirs->id]));
        $this->assertSame('afdeling', get_config('local_reportlms', 'lockedfield_department'));

        set_config(writeback::SETTING_DEPARTMENT, 'rlms_department', 'local_reportlms');
        writeback::follow_locks();

        $this->assertEquals(0, $DB->get_field('user_info_field', 'locked', ['id' => $theirs->id]));
        $this->assertFalse(get_config('local_reportlms', 'lockedfield_department'));
    }

    /**
     * A lock somebody else set is neither claimed nor released.
     *
     * We cannot tell theirs from ours, so the only safe rule is to touch only
     * what we locked. Getting this wrong is what left a field locked forever
     * with nothing recording why.
     */
    public function test_a_lock_we_did_not_set_is_left_alone(): void {
        global $DB;

        $theirs = $this->make_field('afdeling');
        $DB->set_field('user_info_field', 'locked', 1, ['id' => $theirs->id]);

        set_config(writeback::SETTING_DEPARTMENT, 'afdeling', 'local_reportlms');
        writeback::follow_locks();

        $this->assertFalse(get_config('local_reportlms', 'lockedfield_department'), 'Not ours to claim.');

        set_config(writeback::SETTING_DEPARTMENT, 'rlms_department', 'local_reportlms');
        writeback::follow_locks();

        $this->assertEquals(1, $DB->get_field('user_info_field', 'locked', ['id' => $theirs->id]));
    }

    /**
     * One user's value in one field, as a string. Absent reads as empty,
     * because absent and empty mean the same thing to a reader.
     *
     * @param \stdClass $user
     * @param string $shortname
     *
     * @return string
     */
    private function value_of(\stdClass $user, string $shortname): string {
        global $DB;

        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE f.shortname = :shortname AND d.userid = :userid";

        return (string) $DB->get_field_sql($sql, [
            'shortname' => $shortname,
            'userid' => $user->id,
        ]);
    }

    /**
     * Give one user a value in one field.
     *
     * @param \stdClass $user
     * @param string $shortname
     * @param string $value
     */
    private function set_value(\stdClass $user, string $shortname, string $value): void {
        global $DB;

        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]),
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * A customer's own visible text field.
     *
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

        $id = $DB->insert_record('user_info_field', (object) [
            'shortname' => $shortname,
            'name' => $shortname,
            'categoryid' => 1,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => PROFILE_VISIBLE_ALL,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 30,
            'param2' => 2048,
        ]);

        return $DB->get_record('user_info_field', ['id' => $id]);
    }
}
