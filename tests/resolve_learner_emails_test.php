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
 * Tests for handing the dashboard the e-mail addresses of the people on screen.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use core_external\external_api;
use local_reportlms\event\learner_emails_resolved;
use local_reportlms\external\resolve_learner_emails;
use local_reportlms\local\access;
use local_reportlms\local\capability_holders;
use local_reportlms\local\learner_names;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;

/**
 * Two doors, and both have to hold.
 *
 * The first is the name service's: which people this viewer may see at all.
 * The second is Moodle's own rule on who may see addresses, asked in the system
 * context only. Most of this file is about one of them being open and the other
 * shut, because that is where a leak would come from.
 *
 * @covers \local_reportlms\external\resolve_learner_emails
 * @covers \local_reportlms\local\learner_names::emails
 * @covers \local_reportlms\local\access::can_copy_emails
 * @covers \local_reportlms\event\learner_emails_resolved
 */
final class resolve_learner_emails_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        // Moodle's default, stated so the tests do not lean on it.
        set_config('showuseridentity', 'email');
        set_config('hiddenuserfields', '');

        capability_holders::reset();
        mapping::reset();
    }

    /**
     * An administrator may copy anybody's address.
     */
    public function test_an_administrator_is_given_addresses(): void {
        $someone = $this->getDataGenerator()->create_user(['email' => 'bob@example.com']);

        $this->setAdminUser();

        $result = $this->call([pseudonym::learner((int) $someone->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(
            [['code' => pseudonym::learner((int) $someone->id), 'email' => 'bob@example.com']],
            $result['learners']
        );
    }

    /**
     * A manager may see their people's names and still not their addresses.
     *
     * Named in the mapping editor, which opens the report, and holding nothing
     * else. The answer is "not allowed", not an empty list that would read as
     * "nobody has an address".
     */
    public function test_a_manager_without_identity_rights_is_not_allowed(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');

        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        // The first door is open: the name comes back.
        $this->assertCount(1, learner_names::resolve([$code]));

        $this->assertSame(['allowed' => false, 'learners' => []], $this->call([$code]));
    }

    /**
     * The capability alone is not enough when e-mail is not an identity field.
     */
    public function test_email_missing_from_show_user_identity_is_not_allowed(): void {
        $someone = $this->getDataGenerator()->create_user(['email' => 'bob@example.com']);

        set_config('showuseridentity', 'idnumber');
        $this->setAdminUser();

        $this->assertSame(
            ['allowed' => false, 'learners' => []],
            $this->call([pseudonym::learner((int) $someone->id)])
        );
    }

    /**
     * A hidden e-mail field stays hidden from somebody who may not see hidden details.
     */
    public function test_a_hidden_email_field_is_not_allowed_without_viewhiddendetails(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity']);

        set_config('hiddenuserfields', 'email');
        $this->setUser($manager);

        $this->assertFalse($this->call([pseudonym::learner((int) $mine->id)])['allowed']);
    }

    /**
     * The same hidden field, with moodle/user:viewhiddendetails held across the site.
     *
     * The positive control for the case above: same viewer, same setting, one
     * capability more. Without it the refusal above could be coming from
     * anywhere.
     */
    public function test_a_hidden_email_field_is_allowed_with_viewhiddendetails(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity', 'moodle/user:viewhiddendetails']);

        set_config('hiddenuserfields', 'email');
        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        $this->assertSame(
            ['allowed' => true, 'learners' => [['code' => $code, 'email' => 'anna@example.com']]],
            $this->call([$code])
        );
    }

    /**
     * Seeing identity fields inside one course is not seeing them across the site.
     *
     * The case the system-context rule exists for. A teacher sees addresses on
     * their own course's participants page; the report spans the organisation,
     * so a grant held there must not hand over anybody's address here.
     */
    public function test_identity_rights_held_in_one_course_are_not_enough(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');

        $course = $this->getDataGenerator()->create_course();
        $roleid = $this->getDataGenerator()->create_role();
        $coursecontext = \context_course::instance($course->id);
        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $manager->id, $coursecontext->id);

        $this->setUser($manager);

        $this->assertTrue(has_capability('moodle/site:viewuseridentity', $coursecontext), 'The fixture holds it there.');
        $this->assertFalse($this->call([pseudonym::learner((int) $mine->id)])['allowed']);
    }

    /**
     * Allowed is not unscoped: only the viewer's own people come back.
     *
     * The other learner is real, has an address and a valid code. The only thing
     * between the manager and it is the candidate set.
     */
    public function test_only_the_viewers_own_people_are_answered(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity']);

        $elsewhere = $this->getDataGenerator()->create_user(['email' => 'bob@example.com']);
        mapping::place((int) $elsewhere->id, mapping::save_node('Utrecht', 'department'));

        capability_holders::reset();
        mapping::reset();
        $this->setUser($manager);

        $mycode = pseudonym::learner((int) $mine->id);
        $result = $this->call([$mycode, pseudonym::learner((int) $elsewhere->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([['code' => $mycode, 'email' => 'anna@example.com']], $result['learners']);
    }

    /**
     * Somebody with no address, or one Moodle would not accept, comes back as null.
     *
     * Present rather than left out: the dashboard reports an absent code as
     * "not copied" and a null one as "has none", and these people are the
     * second. Null rather than an empty string, which would fail the declared
     * return type.
     */
    public function test_people_without_a_usable_address_are_answered_with_null(): void {
        global $DB;

        $with = $this->getDataGenerator()->create_user(['email' => 'anna@example.com']);
        $without = $this->getDataGenerator()->create_user();
        $broken = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'email', '', ['id' => $without->id]);
        $DB->set_field('user', 'email', 'not an address', ['id' => $broken->id]);

        $this->setAdminUser();

        $result = $this->call([
            pseudonym::learner((int) $with->id),
            pseudonym::learner((int) $without->id),
            pseudonym::learner((int) $broken->id),
        ]);

        $expected = [
            pseudonym::learner((int) $with->id) => 'anna@example.com',
            pseudonym::learner((int) $without->id) => null,
            pseudonym::learner((int) $broken->id) => null,
        ];
        ksort($expected);

        $this->assertTrue($result['allowed']);
        $this->assertSame($expected, $this->by_code($result['learners']));
    }

    /**
     * "Has none" and "not yours to see" never look alike.
     *
     * The manager's own person has no address and comes back with null. The
     * other person has an address and a valid code, but is outside the
     * manager's units, so their code does not come back at all: not with
     * null, which would tell the dashboard they have no address.
     */
    public function test_in_reach_without_an_address_is_null_and_out_of_reach_is_absent(): void {
        global $DB;

        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $DB->set_field('user', 'email', '', ['id' => $mine->id]);
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity']);

        $elsewhere = $this->getDataGenerator()->create_user(['email' => 'bob@example.com']);
        mapping::place((int) $elsewhere->id, mapping::save_node('Utrecht', 'department'));

        capability_holders::reset();
        mapping::reset();
        $this->setUser($manager);

        $mycode = pseudonym::learner((int) $mine->id);
        $result = $this->call([$mycode, pseudonym::learner((int) $elsewhere->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([['code' => $mycode, 'email' => null]], $result['learners']);
    }

    /**
     * A deleted account gives no address, even though Moodle left one stored.
     *
     * Set directly rather than through delete_user(), which rewrites the
     * address: this is the row an old deletion, or one by another route,
     * leaves behind with the address still in it.
     */
    public function test_a_deleted_user_gives_no_address(): void {
        global $DB;

        $gone = $this->getDataGenerator()->create_user(['email' => 'gone@example.com']);
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        $this->setAdminUser();

        $result = $this->call([pseudonym::learner((int) $gone->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['learners']);
    }

    /**
     * A thousand is answered; more than a thousand is refused, not cut short.
     *
     * Cut short, the people past the ceiling would come back as having no
     * address, and the dashboard would say so.
     */
    public function test_more_than_the_ceiling_is_refused(): void {
        $this->setAdminUser();

        $codes = [];

        for ($i = 1; $i <= learner_names::MAX_CODES; $i++) {
            $codes[] = 'p_' . str_pad((string) $i, 32, '0', STR_PAD_LEFT);
        }

        // Duplicates count once, so these stay within the ceiling.
        $this->assertTrue($this->call(array_merge($codes, [$codes[0]]))['allowed']);

        $this->expectException(\invalid_parameter_exception::class);
        $this->call(array_merge($codes, ['p_' . str_repeat('f', 32)]));
    }

    /**
     * A viewer who may not copy hears "not allowed" whatever the size of the ask.
     *
     * The permission is asked before the ceiling, so a batch that is too big
     * from somebody who may not have addresses at all is answered with the
     * reason that matters to them, not with an error about the batch.
     */
    public function test_a_viewer_who_may_not_copy_is_not_allowed_past_the_ceiling(): void {
        [$manager] = $this->manager_with_a_participant('anna@example.com');
        $this->setUser($manager);

        $codes = [];

        for ($i = 0; $i <= learner_names::MAX_CODES; $i++) {
            $codes[] = 'p_' . str_pad((string) $i, 32, '0', STR_PAD_LEFT);
        }

        $this->assertCount(learner_names::MAX_CODES + 1, array_unique($codes));
        $this->assertSame(['allowed' => false, 'learners' => []], $this->call($codes));
    }

    /**
     * The address lookup checks the permission itself, not only the service.
     *
     * Called directly, past the service's own check, as a manager who may see
     * their people's names: the names still come back and the addresses do
     * not. This is the second check inside emails(), pinned.
     */
    public function test_emails_refuses_a_viewer_without_identity_rights_on_its_own(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        $this->assertSame([$code => fullname($mine)], learner_names::resolve([$code]));
        $this->assertSame([], learner_names::emails([$code]));
    }

    /**
     * Handing addresses over is logged, as a count, and never with the addresses.
     */
    public function test_an_allowed_answer_is_logged_as_a_count_only(): void {
        global $DB;

        $with = $this->getDataGenerator()->create_user(['email' => 'anna@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'bob@example.com']);
        $without = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'email', '', ['id' => $without->id]);

        $codes = [
            pseudonym::learner((int) $with->id),
            pseudonym::learner((int) $other->id),
            pseudonym::learner((int) $without->id),
        ];

        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $this->call($codes);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof learner_emails_resolved
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame(['count' => 2], $events[0]->other);
        $this->assertSame((int) get_admin()->id, (int) $events[0]->userid);
        $this->assertSame(\context_system::instance()->id, (int) $events[0]->contextid);

        // Nothing that names anybody, anywhere in what the log would keep.
        $logged = json_encode($events[0]->get_data()) . $events[0]->get_description();

        foreach (array_merge(['anna@example.com', 'bob@example.com'], $codes) as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    /**
     * A refusal hands nothing over, so it logs nothing.
     */
    public function test_a_refused_answer_is_not_logged(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com');
        $this->setUser($manager);

        $sink = $this->redirectEvents();
        $this->call([pseudonym::learner((int) $mine->id)]);
        $events = array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof learner_emails_resolved
        );
        $sink->close();

        $this->assertSame([], $events);
    }

    /**
     * Somebody who may not open the report is stopped at the first door.
     */
    public function test_a_viewer_without_view_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        resolve_learner_emails::execute([]);
    }

    /**
     * The service's answer, checked against the shape it declares.
     *
     * @param array<int, string> $codes
     *
     * @return array
     */
    private function call(array $codes): array {
        return external_api::clean_returnvalue(
            resolve_learner_emails::execute_returns(),
            resolve_learner_emails::execute($codes)
        );
    }

    /**
     * The service's learners as code => address, whatever order they came in.
     *
     * Built by hand rather than with array_column(), so a null address stays a
     * null entry instead of depending on how that helper treats one.
     *
     * @param array $learners The service's learners, each a code and an address or null.
     *
     * @return array<string, string|null>
     */
    private function by_code(array $learners): array {
        $map = [];

        foreach ($learners as $entry) {
            $map[$entry['code']] = $entry['email'];
        }

        ksort($map);

        return $map;
    }

    /**
     * Give a user capabilities at the system context, through a role of their own.
     *
     * @param \stdClass $user
     * @param array<int, string> $capabilities
     *
     * @return void
     */
    private function grant_at_system(\stdClass $user, array $capabilities): void {
        $roleid = $this->getDataGenerator()->create_role();
        $systemid = \context_system::instance()->id;

        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemid, true);
        }

        role_assign($roleid, $user->id, $systemid);
    }

    /**
     * A manager named against a unit, and one person with an address placed in it.
     *
     * @param string $email
     *
     * @return array{0: \stdClass, 1: \stdClass} The manager and the person.
     */
    private function manager_with_a_participant(string $email): array {
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user(['email' => $email]);

        $nodeid = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $learner->id, $nodeid);
        mapping::add_manager($nodeid, (int) $manager->id, null);

        capability_holders::reset();
        mapping::reset();

        // The mapping is what opens the report for them, not a capability.
        $this->assertFalse(has_capability(access::VIEW, \context_system::instance(), $manager));

        return [$manager, $learner];
    }
}
