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
 * Tests for turning a drill-through's codes back into a filter.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\collector\org;
use local_reportlms\local\filter;
use local_reportlms\local\label_map;
use local_reportlms\local\pseudonym;

/**
 * @covers \local_reportlms\local\filter
 */
final class filter_test extends \advanced_testcase {
    /** @var \stdClass */
    private $sectorfield;

    /** @var \stdClass */
    private $departmentfield;

    /** @var \stdClass */
    private $functionfield;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        label_map::purge();

        $map = org::fieldmap();
        $this->sectorfield = $this->make_field($map['sector']);
        $this->departmentfield = $this->make_field($map['department']);
        $this->functionfield = $this->make_field($map['function']);
    }

    /**
     * @param string $shortname
     *
     * @return \stdClass
     */
    private function make_field(string $shortname): \stdClass {
        global $DB;

        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => 'Report LMS test']);

        if (!$categoryid) {
            $categoryid = $DB->insert_record('user_info_category', (object) [
                'name' => 'Report LMS test',
                'sortorder' => 1,
            ]);
        }

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
     * @param array<int, string> $values fieldid => value
     */
    private function learner(array $values): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();

        foreach ($values as $fieldid => $value) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => 0,
            ]);
        }

        return $user;
    }

    /**
     * A department code constrains its sector too.
     *
     * A department is only identified together with its sector, so selecting
     * one has to constrain both. Without it, "HR" would match HR in every
     * sector on the site, and the drill-through would list people the reader
     * never asked to see.
     */
    public function test_a_department_also_constrains_its_sector(): void {
        $this->learner([
            $this->sectorfield->id => 'Operations',
            $this->departmentfield->id => 'HR',
        ]);
        $this->learner([
            $this->sectorfield->id => 'Finance',
            $this->departmentfield->id => 'HR',
        ]);

        $code = pseudonym::node('department', 'Operations/HR');
        $filter = filter::from_codes($code, '', '');

        $this->assertSame('HR', $filter->department);
        $this->assertSame('Operations', $filter->sector);
        $this->assertSame([], $filter->unresolved);
    }

    public function test_a_sector_code_leaves_the_department_open(): void {
        $this->learner([
            $this->sectorfield->id => 'Operations',
            $this->departmentfield->id => 'Rotterdam',
        ]);

        $filter = filter::from_codes(pseudonym::node('sector', 'Operations'), '', '');

        $this->assertSame('Operations', $filter->sector);
        $this->assertNull($filter->department);
    }

    /**
     * A code this site cannot place is dropped, never guessed at.
     *
     * Widening the result would show people the selection never covered, and
     * that is worse than quietly covering more than it says: it would be wrong
     * in the direction of showing somebody data they did not ask for.
     */
    public function test_an_unknown_node_is_recorded_and_not_applied(): void {
        $this->learner([
            $this->sectorfield->id => 'Operations',
            $this->departmentfield->id => 'Rotterdam',
        ]);

        $filter = filter::from_codes('n_notacodethissitehasever', '', '');

        $this->assertNull($filter->sector);
        $this->assertNull($filter->department);
        $this->assertSame(['n_notacodethissitehasever'], $filter->unresolved);
    }

    public function test_a_course_code_resolves_to_its_course(): void {
        $course = $this->getDataGenerator()->create_course();

        $filter = filter::from_codes('', pseudonym::course((int) $course->id), '');

        $this->assertSame((int) $course->id, $filter->courseid);
        $this->assertSame([], $filter->unresolved);
    }

    public function test_an_unknown_course_is_recorded_and_not_applied(): void {
        $this->getDataGenerator()->create_course();

        $filter = filter::from_codes('', 'c_00000000000000000000000000000000', '');

        $this->assertNull($filter->courseid);
        $this->assertSame(['c_00000000000000000000000000000000'], $filter->unresolved);
    }

    public function test_a_function_code_resolves_to_its_value(): void {
        $this->learner([
            $this->sectorfield->id => 'Operations',
            $this->departmentfield->id => 'Rotterdam',
            $this->functionfield->id => 'Driver',
        ]);

        $filter = filter::from_codes('', '', pseudonym::functionref('Driver'));

        $this->assertSame('Driver', $filter->function);
        $this->assertSame([], $filter->unresolved);
    }

    /**
     * A node code passed as a function is not silently accepted.
     *
     * Both are org codes and both start with the same prefix, so only the kind
     * tells them apart. Without that check a department would set the function
     * filter and the drill-through would match nobody, for no visible reason.
     */
    public function test_a_department_code_is_not_taken_as_a_function(): void {
        $this->learner([
            $this->sectorfield->id => 'Operations',
            $this->departmentfield->id => 'Rotterdam',
        ]);

        $code = pseudonym::node('department', 'Operations/Rotterdam');
        $filter = filter::from_codes('', '', $code);

        $this->assertNull($filter->function);
        $this->assertSame([$code], $filter->unresolved);
    }

    public function test_empty_codes_filter_nothing(): void {
        $filter = filter::from_codes('', '', '');

        $this->assertNull($filter->sector);
        $this->assertNull($filter->department);
        $this->assertNull($filter->function);
        $this->assertNull($filter->courseid);
        $this->assertSame([], $filter->unresolved);
    }
}
