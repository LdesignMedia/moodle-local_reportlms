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
 * Tests for turning coded identifiers back into names.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\label_map;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;

/**
 * Codes leave this site; names never do, so this is what turns them back.
 *
 * @covers \local_reportlms\local\label_map
 */
final class label_map_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Coding anything needs the salt a connect would have generated.
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        mapping::reset();
    }

    /**
     * A unit in a sector, as the editor would create it.
     *
     * These used to be built by writing profile field values onto a learner,
     * because the labels were rebuilt from the distinct values in two fields. A
     * unit is a row now, and a label is that row's name, so a unit with nobody
     * in it still has one.
     *
     * @param string $sector
     * @param string $department
     *
     * @return int The department node id.
     */
    private function unit(string $sector, string $department): int {
        $sectors = [];

        foreach (mapping::nodes() as $node) {
            if ($node->kind === 'sector') {
                $sectors[$node->name] = (int) $node->id;
            }
        }

        $sectorid = $sectors[$sector] ?? mapping::save_node($sector, 'sector');
        $nodeid = mapping::save_node($department, 'department', $sectorid);

        mapping::reset();

        return $nodeid;
    }

    public function test_a_department_resolves_to_its_own_name(): void {
        $this->unit('Operations', 'Rotterdam');

        $code = pseudonym::node('department', 'Operations/Rotterdam');
        $labels = label_map::resolve([$code]);

        $this->assertArrayHasKey($code, $labels);
        $this->assertSame('Rotterdam', $labels[$code]['label']);
        $this->assertSame('department', $labels[$code]['kind']);
    }

    /**
     * The same department name under two sectors stays two nodes.
     *
     * This is the bug that once left 291 nodes sharing 13 labels: the query
     * was keyed on the sector, so every department under one sector but the
     * last was silently dropped.
     */
    public function test_departments_sharing_a_name_across_sectors_stay_distinct(): void {
        $this->unit('Operations', 'HR');
        $this->unit('Finance', 'HR');

        $operations = pseudonym::node('department', 'Operations/HR');
        $finance = pseudonym::node('department', 'Finance/HR');

        $this->assertNotSame($operations, $finance);

        $labels = label_map::resolve([$operations, $finance]);

        $this->assertCount(2, $labels);
        $this->assertSame('HR', $labels[$operations]['label']);
        $this->assertSame('HR', $labels[$finance]['label']);
        $this->assertNotSame($labels[$operations]['parent'], $labels[$finance]['parent']);
    }

    /**
     * Every department under one sector gets a label, not just one of them.
     */
    public function test_every_department_under_one_sector_is_named(): void {
        foreach (['Rotterdam', 'Utrecht', 'Den Haag', 'Groningen'] as $department) {
            $this->unit('Operations', $department);
        }

        $codes = array_map(
            fn (string $department) => pseudonym::node('department', 'Operations/' . $department),
            ['Rotterdam', 'Utrecht', 'Den Haag', 'Groningen']
        );

        $this->assertCount(4, label_map::resolve($codes));
    }

    /**
     * An unknown code is absent rather than an error.
     *
     * A report holding one stale node must still draw; the caller shows the
     * raw code for anything it cannot name.
     */
    public function test_an_unknown_code_is_simply_absent(): void {
        $this->unit('Operations', 'Rotterdam');

        $labels = label_map::resolve(['n_notacodethissitehasever']);

        $this->assertSame([], $labels);
    }

    public function test_a_course_ref_resolves_to_its_course(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(
            (int) $course->id,
            label_map::course_id(pseudonym::course((int) $course->id))
        );
    }

    public function test_a_course_ref_from_another_site_resolves_to_nothing(): void {
        $this->getDataGenerator()->create_course();

        $this->assertNull(label_map::course_id('c_00000000000000000000000000000000'));
    }

    /**
     * A renamed department must not keep its old label for the cache window.
     */
    public function test_purging_forgets_both_maps(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->unit('Operations', 'Rotterdam');

        $code = pseudonym::node('department', 'Operations/Rotterdam');
        $this->assertNotEmpty(label_map::resolve([$code]));
        $this->assertNotNull(label_map::course_id(pseudonym::course((int) $course->id)));

        label_map::purge();

        // Rebuilt from the database rather than served from a stale cache, so
        // the answers are the same but they are answers, not memories.
        $this->assertSame('Rotterdam', label_map::resolve([$code])[$code]['label']);
        $this->assertSame((int) $course->id, label_map::course_id(pseudonym::course((int) $course->id)));
    }
}
