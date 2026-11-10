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
 * Tests for unit names, unit keys and the size of the place lists.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\mapping;

/**
 * A unit has one key on the wire, and the editor will not store two units under one.
 *
 * @covers \local_reportlms\local\mapping
 */
final class unit_keys_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        mapping::reset();
    }

    public function test_a_name_with_a_slash_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('manage:nameslash', 'local_reportlms'));

        mapping::save_node('Sales/EMEA', 'department');
    }

    public function test_a_unit_that_would_share_a_key_is_refused(): void {
        global $DB;

        // A row from before the slash was refused, whose name reads as a parent and a child.
        $DB->insert_record('local_reportlms_node', (object) [
            'name' => 'Ops/North', 'kind' => 'department', 'parentid' => 0, 'timemodified' => time(), 'usermodified' => 0,
        ]);
        $sector = mapping::save_node('Ops', 'sector');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('manage:namekeyclash', 'local_reportlms', 'North'));

        mapping::save_node('North', 'department', $sector);
    }

    public function test_renaming_a_sector_cannot_give_its_units_a_shared_key(): void {
        global $DB;

        $sector = mapping::save_node('Temporary', 'sector');
        mapping::save_node('North', 'department', $sector);
        // A row from before the slash was refused.
        $DB->insert_record('local_reportlms_node', (object) [
            'name' => 'Ops/North', 'kind' => 'department', 'parentid' => 0, 'timemodified' => time(), 'usermodified' => 0,
        ]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('manage:namekeyclash', 'local_reportlms', 'Ops'));

        mapping::save_node('Ops', 'sector', null, $sector);
    }

    public function test_a_department_under_a_real_parent_keys_with_the_parent(): void {
        $sector = mapping::save_node('Parent', 'sector');
        $child = mapping::save_node('Child', 'department', $sector);

        $this->assertSame('department:Parent/Child', mapping::nodekey($child));
        $this->assertSame('sector:Parent', mapping::nodekey($sector));
    }

    public function test_a_department_with_a_missing_parent_has_no_key(): void {
        global $DB;

        $orphan = $DB->insert_record('local_reportlms_node', (object) [
            'name' => 'Lost', 'kind' => 'department', 'parentid' => 99999, 'timemodified' => time(), 'usermodified' => 0,
        ]);
        mapping::reset();

        $this->assertNull(mapping::nodekey((int) $orphan));
    }

    public function test_deleting_a_parent_cannot_leave_a_child_sharing_a_key(): void {
        global $DB;

        $sector = mapping::save_node('Parent', 'sector');
        $child = mapping::save_node('Same', 'department', $sector);
        $root = mapping::save_node('Same', 'department');

        try {
            mapping::delete_node($sector);
            $this->fail('The delete should have been refused.');
        } catch (\moodle_exception $e) {
            $this->assertTrue($DB->record_exists('local_reportlms_node', ['id' => $sector]));
            $this->assertSame($sector, (int) $DB->get_field('local_reportlms_node', 'parentid', ['id' => $child]));
        }

        $this->assertSame('department:Same', mapping::nodekey($root));
    }

    public function test_deleting_a_parent_moves_a_child_without_a_clash_to_the_top(): void {
        $sector = mapping::save_node('Parent', 'sector');
        $child = mapping::save_node('Alone', 'department', $sector);

        mapping::delete_node($sector);

        $this->assertSame('department:Alone', mapping::nodekey($child));
    }

    public function test_sectors_key_with_their_name(): void {
        $sector = mapping::save_node('Operations', 'sector');

        $this->assertSame('sector:Operations', mapping::nodekey($sector));
    }

    public function test_a_top_level_unit_is_stored_with_parent_zero(): void {
        global $DB;

        $sector = mapping::save_node('Operations', 'sector');
        $root = mapping::save_node('Loose', 'department');

        $this->assertSame(0, (int) $DB->get_field('local_reportlms_node', 'parentid', ['id' => $sector]));
        $this->assertSame(0, (int) $DB->get_field('local_reportlms_node', 'parentid', ['id' => $root]));
        $this->assertNull(mapping::nodes()[$sector]->parentid);
    }

    public function test_the_second_of_two_overlapping_saves_is_told_the_name_is_taken(): void {
        global $DB;

        // Both requests read the tree before either wrote.
        mapping::nodes();
        $DB->insert_record('local_reportlms_node', (object) [
            'name' => 'Sales', 'kind' => 'sector', 'parentid' => 0, 'timemodified' => time(), 'usermodified' => 0,
        ]);

        try {
            mapping::save_node('Sales', 'sector');
            $this->fail('The second save should have been refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('manage:nametaken', $e->errorcode);
        }

        $this->assertSame(1, $DB->count_records('local_reportlms_node', ['name' => 'Sales']));
    }

    public function test_two_sectors_with_one_name_cannot_both_be_stored(): void {
        mapping::save_node('Sales', 'sector');

        $this->expectException(\moodle_exception::class);

        mapping::save_node('Sales', 'sector');
    }

    public function test_the_same_name_under_different_parents_can_both_be_stored(): void {
        $first = mapping::save_node('One', 'sector');
        $second = mapping::save_node('Two', 'sector');

        $here = mapping::save_node('HR', 'department', $first);
        $there = mapping::save_node('HR', 'department', $second);

        $this->assertNotSame($here, $there);
    }

    public function test_the_table_itself_refuses_a_second_row_with_the_same_kind_parent_and_name(): void {
        global $DB;

        $row = (object) [
            'name' => 'Sales', 'kind' => 'sector', 'parentid' => 0, 'timemodified' => time(), 'usermodified' => 0,
        ];
        $DB->insert_record('local_reportlms_node', $row);

        // Last statement of the test: a failed insert can end the transaction on some databases.
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_reportlms_node', $row);
    }

    /**
     * The size of one list, as the form would post it.
     *
     * @param int $size
     *
     * @return array<int, int>
     */
    private function ids(int $size): array {
        return $size > 0 ? range(1, $size) : [];
    }

    public function test_a_list_of_the_cap_is_saved(): void {
        global $DB;

        $node = mapping::save_node('Unit', 'department');

        mapping::save_sources($node, ['categories' => $this->ids(mapping::MAX_LIST)]);

        $this->assertSame(mapping::MAX_LIST, $DB->count_records('local_reportlms_source', ['nodeid' => $node]));
    }

    /**
     * Each of the five source lists, one past the cap.
     *
     * @return array<string, array{0: string}>
     */
    public static function source_lists(): array {
        return [
            'courses' => ['courses'],
            'categories' => ['categories'],
            'cohorts' => ['cohorts'],
            'groups' => ['groups'],
        ];
    }

    /**
     * A list one past the cap saves nothing.
     *
     * @dataProvider source_lists
     * @param string $key
     */
    public function test_a_longer_list_saves_nothing(string $key): void {
        global $DB;

        $node = mapping::save_node('Unit', 'department');
        mapping::save_sources($node, ['courses' => [1]]);

        try {
            mapping::save_sources($node, [$key => $this->ids(mapping::MAX_LIST + 1)]);
            $this->fail('The list should have been refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('manage:toomany', $e->errorcode);
        }

        $this->assertSame(1, $DB->count_records('local_reportlms_source', ['nodeid' => $node]));
    }

    public function test_a_longer_profile_list_saves_nothing(): void {
        global $DB;

        $node = mapping::save_node('Unit', 'department');
        $values = [];

        for ($i = 0; $i <= mapping::MAX_LIST; $i++) {
            $values[] = '1:value' . $i;
        }

        try {
            mapping::save_sources($node, ['profilevalues' => $values]);
            $this->fail('The list should have been refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('manage:toomany', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('local_reportlms_source', ['nodeid' => $node]));
    }

    public function test_the_person_list_is_held_to_the_same_cap(): void {
        mapping::assert_list_limits(['userid' => $this->ids(mapping::MAX_LIST)]);

        $this->expectException(\moodle_exception::class);

        mapping::assert_list_limits(['userid' => $this->ids(mapping::MAX_LIST + 1)]);
    }

    public function test_something_that_is_not_a_list_counts_as_empty(): void {
        mapping::assert_list_limits(['userid' => 'not a list']);

        $this->assertTrue(true);
    }

    public function test_duplicate_ids_still_collapse(): void {
        global $DB;

        $node = mapping::save_node('Unit', 'department');

        mapping::save_sources($node, ['courses' => [5, 5, 5, 6]]);

        $this->assertSame(2, $DB->count_records('local_reportlms_source', ['nodeid' => $node]));
    }
}
