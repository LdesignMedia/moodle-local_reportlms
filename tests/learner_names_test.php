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
 * Tests for putting names to coded learner ids.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\resolve_learners;
use local_reportlms\local\access;
use local_reportlms\local\learner_names;
use local_reportlms\local\capability_holders;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;

/**
 * This is the service that turns a report into a list of people, so the whole
 * of it is about who is refused.
 *
 * @covers \local_reportlms\local\learner_names
 * @covers \local_reportlms\external\resolve_learners
 */
final class learner_names_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        capability_holders::reset();
        mapping::reset();
    }

    /**
     * A manager names the people in the course they manage.
     */
    public function test_a_manager_names_their_own_participants(): void {
        [$manager, $mine] = $this->manager_with_a_participant('Anna', 'Jansen');

        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        $this->assertSame(['Anna Jansen'], array_values(learner_names::resolve([$code])));
    }

    /**
     * And nobody else's, which is the entire point of the class.
     *
     * The learner here is real, enrolled, and has a valid code. The only thing
     * standing between the manager and their name is the candidate set.
     */
    public function test_a_manager_cannot_name_somebody_elses_learner(): void {
        [$manager] = $this->manager_with_a_participant('Anna', 'Jansen');

        $elsewhere = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Elders']);
        $other = mapping::save_node('Utrecht', 'department');
        mapping::place((int) $elsewhere->id, $other);

        capability_holders::reset();
        mapping::reset();
        $this->setUser($manager);

        $code = pseudonym::learner((int) $elsewhere->id);

        $this->assertSame([], learner_names::resolve([$code]));
    }

    /**
     * A person in two units is named for the manager of either.
     *
     * Being in a second unit must not hide them from the manager of the first,
     * which is what a one-unit lookup would do.
     */
    public function test_a_person_in_two_units_is_named_for_either_manager(): void {
        [$manager, $mine] = $this->manager_with_a_participant('Anna', 'Jansen');

        $other = mapping::save_node('Utrecht', 'department');
        mapping::place((int) $mine->id, $other);

        $othermanager = $this->getDataGenerator()->create_user();
        mapping::add_manager($other, (int) $othermanager->id, null);

        capability_holders::reset();
        mapping::reset();

        $code = pseudonym::learner((int) $mine->id);

        $this->setUser($manager);
        $this->assertSame(['Anna Jansen'], array_values(learner_names::resolve([$code])));

        $this->setUser($othermanager);
        $this->assertSame(['Anna Jansen'], array_values(learner_names::resolve([$code])));
    }

    /**
     * An administrator names anybody, which is what the unscoped view means.
     */
    public function test_an_administrator_names_anybody(): void {
        $someone = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Elders']);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);

        $this->assertSame(['Bob Elders'], array_values(learner_names::resolve([$code])));
    }

    /**
     * A viewer who manages nobody names nobody, rather than everybody.
     */
    public function test_a_viewer_who_manages_nobody_names_nobody(): void {
        $user = $this->getDataGenerator()->create_user();
        $someone = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);

        capability_holders::reset();
        mapping::reset();
        $this->setUser($user);

        $this->assertSame([], learner_names::resolve([pseudonym::learner((int) $someone->id)]));
    }

    /**
     * A code that matches nobody on this site resolves to nothing, rather than
     * to whoever happens to sort first.
     */
    public function test_an_unknown_code_resolves_to_nothing(): void {
        [$manager] = $this->manager_with_a_participant('Anna', 'Jansen');

        $this->setUser($manager);

        $this->assertSame([], learner_names::resolve(['p_' . str_repeat('f', 32)]));
    }

    /**
     * Through the service, because the filter can be right and not called.
     */
    public function test_the_service_refuses_an_out_of_scope_code(): void {
        [$manager] = $this->manager_with_a_participant('Anna', 'Jansen');

        $elsewhere = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Elders']);
        $other = mapping::save_node('Utrecht', 'department');
        mapping::place((int) $elsewhere->id, $other);

        capability_holders::reset();
        mapping::reset();

        $code = pseudonym::learner((int) $elsewhere->id);

        // Nameable by somebody, so the refusal below is the scope and not a
        // broken fixture.
        $this->setAdminUser();
        $this->assertCount(1, resolve_learners::execute([$code])['learners']);

        $this->setUser($manager);
        $this->assertSame([], resolve_learners::execute([$code])['learners']);
    }

    /**
     * A manager named against a unit, and one person placed in it.
     *
     * It used to be a manager granted the capability on a course, and whoever
     * was enrolled on it. That is why this file changed: a grant on a course is
     * not a statement about who works for somebody, and on a connected site one
     * such grant reached an all-staff course and made 1,467 learners nameable
     * by one person.
     *
     * @param string $firstname
     * @param string $lastname
     *
     * @return array{0: \stdClass, 1: \stdClass} The manager and the person.
     */
    private function manager_with_a_participant(string $firstname, string $lastname): array {
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => $lastname,
        ]);

        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $nodeid);
        mapping::add_manager($nodeid, (int) $manager->id, null);

        capability_holders::reset();
        mapping::reset();

        return [$manager, $learner];
    }

    /**
     * A manager of a sector names the people in the departments under it.
     *
     * Report LMS expands a scope node to its subtree before it serves a figure,
     * so this side has to expand it too. A sector manager served numbers for
     * people whose names this site then refused to translate would read as the
     * report being broken.
     */
    public function test_a_manager_of_a_sector_names_people_in_its_departments(): void {
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user([
            'firstname' => 'Sanne',
            'lastname' => 'de Vries',
        ]);

        $sectorid = mapping::save_node('Operations', 'sector');
        $departmentid = mapping::save_node('Rotterdam', 'department', $sectorid);
        mapping::place((int) $learner->id, $departmentid);
        mapping::add_manager($sectorid, (int) $manager->id, null);
        mapping::reset();

        $this->setUser($manager);

        $code = pseudonym::learner((int) $learner->id);

        $this->assertSame(['Sanne de Vries'], array_values(learner_names::resolve([$code])));
    }
}
