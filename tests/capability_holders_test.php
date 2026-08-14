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
 * Who can reach a report without anybody having named them.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\access;
use local_reportlms\local\capability_holders;

/**
 * The register's second list, and the only thing left of the old resolver.
 *
 * It answers one question, "who holds local/reportlms:view somewhere", and it
 * decides nothing. What anybody may see is settled per request by Moodle's own
 * capability checks, and which unit is theirs is settled by the mapping tables.
 *
 * @covers \local_reportlms\local\capability_holders
 */
final class capability_holders_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        capability_holders::reset();
    }

    /**
     * A grant on one course puts somebody on the list.
     */
    public function test_a_grant_at_a_course_is_found(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->grant(\context_course::instance($course->id));

        $this->assertArrayHasKey((int) $user->id, capability_holders::holders());
    }

    /**
     * So does a grant on a category, and one at system level.
     */
    public function test_a_grant_at_a_category_or_the_system_is_found(): void {
        $category = $this->getDataGenerator()->create_category();

        $atcategory = $this->grant(\context_coursecat::instance($category->id));
        $atsystem = $this->grant(\context_system::instance());

        $holders = capability_holders::holders();

        $this->assertArrayHasKey((int) $atcategory->id, $holders);
        $this->assertArrayHasKey((int) $atsystem->id, $holders);
    }

    /**
     * Somebody with no grant anywhere is not on it.
     */
    public function test_an_ordinary_user_is_not_a_holder(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertArrayNotHasKey((int) $user->id, capability_holders::holders());
    }

    /**
     * Administrators are left out.
     *
     * They hold every capability everywhere, so listing them says nothing about
     * this site and buries the people the register is for. On one connected
     * site two administrator accounts appeared as capability holders and were
     * read as managers who needed fixing.
     */
    public function test_an_administrator_is_not_listed(): void {
        global $CFG;

        $admin = $this->getDataGenerator()->create_user();
        $CFG->siteadmins = $CFG->siteadmins . ',' . $admin->id;

        $this->grant(\context_system::instance(), $admin);

        $this->assertArrayNotHasKey((int) $admin->id, capability_holders::holders());
    }

    /**
     * A role that does not grant the capability puts nobody on the list, even
     * when it is assigned in a context that would otherwise be asked about.
     */
    public function test_a_role_without_the_capability_contributes_nobody(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, \context_course::instance($course->id)->id);

        $this->assertArrayNotHasKey((int) $user->id, capability_holders::holders());
    }

    /**
     * A prohibit is honoured, because Moodle resolves the question and this
     * only narrows where it gets asked.
     */
    public function test_a_prohibit_removes_somebody(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $user = $this->grant($context);

        $this->assertArrayHasKey((int) $user->id, capability_holders::holders());

        $prohibited = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_PROHIBIT, $prohibited, $context->id, true);
        role_assign($prohibited, $user->id, $context->id);

        capability_holders::reset();

        $this->assertArrayNotHasKey((int) $user->id, capability_holders::holders());
    }

    /**
     * The list is held for the request and forgotten on demand.
     *
     * resetAfterTest() rolls the database back and leaves static properties
     * alone, so without the reset the next test reads this one's answer.
     */
    public function test_the_answer_is_held_until_it_is_reset(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $this->assertSame([], capability_holders::holders());

        // Granted without the reset the helper does, so the held answer is
        // genuinely stale rather than merely early.
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->assertSame([], capability_holders::holders(), 'Still the held answer.');

        capability_holders::reset();

        $this->assertArrayHasKey((int) $user->id, capability_holders::holders());
    }

    /**
     * Grant the view capability to somebody in one context.
     *
     * @param \context $context
     * @param \stdClass|null $user Created if not given.
     *
     * @return \stdClass
     */
    private function grant(\context $context, ?\stdClass $user = null): \stdClass {
        $user = $user ?? $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::VIEW, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, $context->id);

        capability_holders::reset();

        return $user;
    }
}
