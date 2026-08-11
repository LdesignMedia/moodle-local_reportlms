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
 * Tests for the label service the dashboard calls to turn codes into names.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\resolve_labels;
use local_reportlms\local\access;
use local_reportlms\local\collector\org;
use local_reportlms\local\label_map;
use local_reportlms\local\manager_resolver;
use local_reportlms\local\pseudonym;

/**
 * The service is where a code becomes a name, so it is where scope has to hold.
 *
 * These go through `execute()` rather than through access::visible_codes(), and
 * that is the entire point of the file. The filter can be correct and simply
 * not called: the tests on the filter itself keep passing when the one line
 * wiring it into this service is deleted, which is a thing that happened.
 *
 * @covers \local_reportlms\external\resolve_labels
 */
final class resolve_labels_test extends \advanced_testcase {
    /** @var \stdClass */
    private $sectorfield;

    /** @var \stdClass */
    private $departmentfield;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');

        // resetAfterTest() rolls back the database and leaves static properties
        // alone, so the resolver has to be told to forget between tests.
        manager_resolver::reset();

        $map = org::fieldmap();
        $this->sectorfield = $this->make_field($map['sector']);
        $this->departmentfield = $this->make_field($map['department']);
    }

    /**
     * A manager of Rotterdam asking about Utrecht is told nothing.
     */
    public function test_a_manager_is_not_told_another_departments_name(): void {
        $theirs = $this->make_populated_department('Utrecht');
        $manager = $this->make_manager_of('Rotterdam');

        // The other department really is nameable, so the only thing standing
        // between this viewer and its name is the scope filter.
        $this->setAdminUser();
        $this->assertContains($theirs, $this->codes_returned([$theirs]));

        $this->setUser($manager);

        $this->assertNotContains($theirs, $this->codes_returned([$theirs]));
    }

    /**
     * The frame asks about whatever is on screen, so an unresolvable code is
     * dropped rather than refused. Failing the request would blank a dashboard
     * over one code the viewer was never going to be shown anyway.
     */
    public function test_an_out_of_scope_code_is_dropped_not_refused(): void {
        $theirs = $this->make_populated_department('Utrecht');
        $manager = $this->make_manager_of('Rotterdam');

        $this->setUser($manager);

        $result = resolve_labels::execute([$theirs]);

        $this->assertSame([], $result['labels']);
    }

    /**
     * An administrator still names everything, which is what the org-wide
     * viewer is for.
     */
    public function test_an_administrator_is_told_everything(): void {
        $this->make_manager_of('Rotterdam');
        $this->setAdminUser();

        $labels = $this->codes_returned([pseudonym::node('department', 'Rotterdam')]);

        $this->assertContains(pseudonym::node('department', 'Rotterdam'), $labels);
    }

    /**
     * The codes the service was willing to name.
     *
     * @param array<int, string> $codes
     *
     * @return array<int, string>
     */
    private function codes_returned(array $codes): array {
        return array_column(resolve_labels::execute($codes)['labels'], 'code');
    }

    /**
     * A manager of one course, with a department of their own.
     *
     * @param string $department
     *
     * @return \stdClass
     */
    private function make_manager_of(string $department): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user();

        $DB->insert_record('user_info_data', (object) [
            'userid' => $manager->id,
            'fieldid' => $this->departmentfield->id,
            'data' => $department,
            'dataformat' => 0,
        ]);

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $manager->id, \context_course::instance($course->id)->id);

        manager_resolver::reset();

        return $manager;
    }

    /**
     * A populated department the caller has nothing to do with.
     *
     * It has to be populated, and that is not incidental. The label map only
     * carries nodes the tree actually has, so a department nobody is in
     * resolves to nothing whether or not the scope filter is there. Testing
     * against an empty one proves the filter works by proving nothing at all,
     * which is how the first version of this file passed with the filter
     * deleted.
     *
     * @param string $department
     *
     * @return string The coded node.
     */
    private function make_populated_department(string $department): string {
        global $DB;

        $learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('user_info_data', (object) [
            'userid' => $learner->id,
            'fieldid' => $this->departmentfield->id,
            'data' => $department,
            'dataformat' => 0,
        ]);

        $this->getDataGenerator()->enrol_user($learner->id, $course->id);

        label_map::purge();
        manager_resolver::reset();

        return pseudonym::node('department', $department);
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

        // db/install.php creates the rlms_* fields in the phpunit baseline, so
        // the field this asks for may already exist. Reuse it: a second row
        // with the same shortname doubles every join that resolves by it.
        $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if ($existing) {
            return $existing;
        }

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
            'param1' => 30,
            'param2' => 2048,
        ]);

        return $DB->get_record('user_info_field', ['id' => $id]);
    }
}
