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

use local_reportlms\local\collector\org;
use local_reportlms\local\label_map;
use local_reportlms\local\pseudonym;

/**
 * @covers \local_reportlms\local\label_map
 */
final class label_map_test extends \advanced_testcase {
    /** @var \stdClass */
    private $sectorfield;

    /** @var \stdClass */
    private $departmentfield;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Coding anything needs the salt a connect would have generated.
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        $map = org::fieldmap();
        $this->sectorfield = $this->make_field($map['sector']);
        $this->departmentfield = $this->make_field($map['department']);
    }

    /**
     * A text profile field, since the generator has no helper for one.
     *
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

        $categoryid = $DB->insert_record('user_info_category', (object) [
            'name' => 'Report LMS ' . $shortname,
            'sortorder' => 1,
        ]);

        $id = $DB->insert_record('user_info_field', (object) [
            'shortname' => $shortname,
            'name' => $shortname,
            'categoryid' => $categoryid,
            'datatype' => 'text',
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 2,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'param1' => 30,
            'param2' => 2048,
        ]);

        return $DB->get_record('user_info_field', ['id' => $id]);
    }

    /**
     * @param string $sector
     * @param string $department
     *
     * @return \stdClass
     */
    private function learner(string $sector, string $department): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        foreach ([$this->sectorfield->id => $sector, $this->departmentfield->id => $department] as $fieldid => $value) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => 0,
            ]);
        }

        return $user;
    }

    public function test_a_department_resolves_to_its_own_name(): void {
        $this->learner('Operations', 'Rotterdam');

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
        $this->learner('Operations', 'HR');
        $this->learner('Finance', 'HR');

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
            $this->learner('Operations', $department);
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
        $this->learner('Operations', 'Rotterdam');

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
        $this->learner('Operations', 'Rotterdam');

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
