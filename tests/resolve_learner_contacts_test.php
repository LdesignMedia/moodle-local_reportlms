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
 * Tests for handing the dashboard the contact details of the people on screen.
 *
 * @package    local_reportlms
 * @copyright  2026 LdesignMedia.nl - Luuk Verhoeven
 * @author     Nihaal Shaikh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use core_external\external_api;
use local_reportlms\event\learner_contacts_resolved;
use local_reportlms\external\resolve_learner_contacts;
use local_reportlms\local\access;
use local_reportlms\local\capability_holders;
use local_reportlms\local\learner_names;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;

/**
 * The address service's two doors, and a third for the phone number.
 *
 * The first is the name service's: which people this viewer may see at all.
 * The second is Moodle's own rule on who may see addresses, and it gates the
 * whole answer. The third is per field: a phone number comes only from a field
 * Moodle shows this viewer. Most of this file is about one door being open and
 * another shut, because that is where a leak would come from.
 *
 * @covers \local_reportlms\external\resolve_learner_contacts
 * @covers \local_reportlms\local\learner_names::contacts
 * @covers \local_reportlms\local\access::visible_phone_fields
 * @covers \local_reportlms\event\learner_contacts_resolved
 */
final class resolve_learner_contacts_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        // Moodle's default, stated so the tests do not lean on it. Tests that
        // care about a phone field widen it themselves.
        set_config('showuseridentity', 'email');
        set_config('hiddenuserfields', '');

        capability_holders::reset();
        mapping::reset();
    }

    /**
     * An administrator may export anybody's contact details.
     */
    public function test_an_administrator_is_given_both(): void {
        set_config('showuseridentity', 'email,phone1,phone2');
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '010 1234567',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);
        $result = $this->call([$code]);

        $this->assertTrue($result['allowed']);
        $this->assertSame(
            [['code' => $code, 'email' => 'bob@example.com', 'phone' => '010 1234567']],
            $result['learners']
        );
    }

    /**
     * No e-mail in "Show user identity" and the answer is "not allowed", not "nobody has any".
     *
     * Even with both phone fields shown: the gate is the address rule, and a
     * phone number is never given out to a viewer who would not be given an
     * address.
     */
    public function test_email_missing_from_show_user_identity_is_not_allowed(): void {
        set_config('showuseridentity', 'phone1,phone2');
        $someone = $this->getDataGenerator()->create_user(['email' => 'bob@example.com', 'phone1' => '010 1234567']);

        $this->setAdminUser();

        $this->assertSame(
            ['allowed' => false, 'learners' => []],
            $this->call([pseudonym::learner((int) $someone->id)])
        );
    }

    /**
     * A manager may see their people's names and still not their contact details.
     *
     * Named in the mapping editor, which opens the report, and holding nothing
     * else.
     */
    public function test_a_manager_without_identity_rights_is_not_allowed(): void {
        set_config('showuseridentity', 'email,phone1,phone2');
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');

        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        // The first door is open: the name comes back.
        $this->assertCount(1, learner_names::resolve([$code]));

        $this->assertSame(['allowed' => false, 'learners' => []], $this->call([$code]));
    }

    /**
     * With e-mail the only identity field, a phone number is not handed out.
     *
     * The person has both phone fields filled in. Neither is shown to this
     * viewer by Moodle, so neither comes back, and the address still does.
     */
    public function test_with_email_only_the_phone_is_null_even_when_the_user_has_one(): void {
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '010 1234567',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);

        $this->assertSame(
            [['code' => $code, 'email' => 'bob@example.com', 'phone' => null]],
            $this->call([$code])['learners']
        );
    }

    /**
     * Phone is handed out when it is one of the viewer's identity fields.
     *
     * Trimmed, and preferred to the mobile phone when both are filled in.
     */
    public function test_phone1_in_the_identity_fields_is_given_trimmed(): void {
        set_config('showuseridentity', 'email,phone1');
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '  010 1234567 ',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);

        $this->assertSame(
            [['code' => $code, 'email' => 'bob@example.com', 'phone' => '010 1234567']],
            $this->call([$code])['learners']
        );
    }

    /**
     * When both fields are visible, "Phone" comes before "Mobile phone".
     *
     * Whatever order the site listed them in.
     */
    public function test_phone1_is_preferred_to_phone2_when_both_are_visible(): void {
        set_config('showuseridentity', 'phone2,email,phone1');
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '010 1234567',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);

        $this->assertSame(['phone1', 'phone2'], access::visible_phone_fields());
        $this->assertSame('010 1234567', $this->call([$code])['learners'][0]['phone']);
    }

    /**
     * An empty or blank "Phone" falls back to "Mobile phone" when that is visible.
     */
    public function test_an_empty_phone1_falls_back_to_phone2_when_it_is_visible(): void {
        set_config('showuseridentity', 'email,phone1,phone2');
        $empty = $this->getDataGenerator()->create_user(['email' => 'a@example.com', 'phone1' => '', 'phone2' => ' 06 12345678 ']);
        $blank = $this->getDataGenerator()->create_user(['email' => 'b@example.com', 'phone1' => '   ', 'phone2' => '06 87654321']);

        $this->setAdminUser();

        $result = $this->call([pseudonym::learner((int) $empty->id), pseudonym::learner((int) $blank->id)]);

        $expected = [
            pseudonym::learner((int) $empty->id) => '06 12345678',
            pseudonym::learner((int) $blank->id) => '06 87654321',
        ];
        ksort($expected);

        $this->assertSame($expected, $this->phones_by_code($result['learners']));
    }

    /**
     * "Mobile phone" alone is enough: each field is decided on its own.
     */
    public function test_phone2_alone_in_the_identity_fields_is_given(): void {
        set_config('showuseridentity', 'email,phone2');
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '010 1234567',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $this->assertSame(['phone2'], access::visible_phone_fields());
        $this->assertSame('06 12345678', $this->call([pseudonym::learner((int) $someone->id)])['learners'][0]['phone']);
    }

    /**
     * A hidden "Mobile phone" is not a fallback: with only "Phone" visible, the answer is null.
     */
    public function test_phone2_not_visible_gives_null(): void {
        set_config('showuseridentity', 'email,phone1');
        $someone = $this->getDataGenerator()->create_user([
            'email' => 'bob@example.com',
            'phone1' => '',
            'phone2' => '06 12345678',
        ]);

        $this->setAdminUser();

        $code = pseudonym::learner((int) $someone->id);

        $this->assertSame(
            [['code' => $code, 'email' => 'bob@example.com', 'phone' => null]],
            $this->call([$code])['learners']
        );
    }

    /**
     * A phone number that is not plain text is passed over rather than failing the batch.
     *
     * Stored by an import or an authentication plugin, so it can be anything.
     * The one malformed row must not take the other people's answers with it,
     * and "Mobile phone" gets its turn.
     */
    public function test_a_malformed_phone_is_passed_over_and_does_not_fail_the_batch(): void {
        global $DB;

        set_config('showuseridentity', 'email,phone1,phone2');
        $broken = $this->getDataGenerator()->create_user(['email' => 'a@example.com', 'phone2' => '06 87654321']);
        $alone = $this->getDataGenerator()->create_user(['email' => 'b@example.com']);
        $fine = $this->getDataGenerator()->create_user(['email' => 'c@example.com', 'phone1' => '010 1234567']);

        // Written past Moodle's own user API, which would clean these on the way in.
        $DB->set_field('user', 'phone1', '06 12345 </lang>', ['id' => $broken->id]);
        $DB->set_field('user', 'phone1', '</lang>', ['id' => $alone->id]);

        $this->setAdminUser();

        $result = $this->call([
            pseudonym::learner((int) $broken->id),
            pseudonym::learner((int) $alone->id),
            pseudonym::learner((int) $fine->id),
        ]);

        $expected = [
            pseudonym::learner((int) $broken->id) => '06 87654321',
            pseudonym::learner((int) $alone->id) => null,
            pseudonym::learner((int) $fine->id) => '010 1234567',
        ];
        ksort($expected);

        $this->assertTrue($result['allowed']);
        $this->assertSame($expected, $this->phones_by_code($result['learners']));
    }

    /**
     * Somebody with no address, or one Moodle would not accept, comes back with null for it.
     *
     * Address and phone number are independent: this person keeps the phone.
     */
    public function test_the_address_and_the_phone_are_independent(): void {
        global $DB;

        set_config('showuseridentity', 'email,phone1');
        $nomail = $this->getDataGenerator()->create_user(['phone1' => '010 1234567']);
        $broken = $this->getDataGenerator()->create_user(['phone1' => '010 7654321']);
        $nophone = $this->getDataGenerator()->create_user(['email' => 'anna@example.com', 'phone1' => '']);
        $DB->set_field('user', 'email', '', ['id' => $nomail->id]);
        $DB->set_field('user', 'email', 'not an address', ['id' => $broken->id]);

        $this->setAdminUser();

        $result = $this->call([
            pseudonym::learner((int) $nomail->id),
            pseudonym::learner((int) $broken->id),
            pseudonym::learner((int) $nophone->id),
        ]);

        $expected = [
            pseudonym::learner((int) $nomail->id) => ['email' => null, 'phone' => '010 1234567'],
            pseudonym::learner((int) $broken->id) => ['email' => null, 'phone' => '010 7654321'],
            pseudonym::learner((int) $nophone->id) => ['email' => 'anna@example.com', 'phone' => null],
        ];
        ksort($expected);

        $this->assertTrue($result['allowed']);

        $actual = [];

        foreach ($result['learners'] as $entry) {
            $actual[$entry['code']] = ['email' => $entry['email'], 'phone' => $entry['phone']];
        }

        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Allowed is not unscoped: only the viewer's own people come back.
     *
     * The other learner is real, has an address, a phone number and a valid
     * code. The only thing between the manager and it is the candidate set.
     */
    public function test_a_code_outside_the_viewers_reach_is_absent(): void {
        set_config('showuseridentity', 'email,phone1');
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity']);

        $elsewhere = $this->getDataGenerator()->create_user(['email' => 'bob@example.com', 'phone1' => '06 12345678']);
        mapping::place((int) $elsewhere->id, mapping::save_node('Utrecht', 'department'));

        capability_holders::reset();
        mapping::reset();
        $this->setUser($manager);

        $mycode = pseudonym::learner((int) $mine->id);
        $result = $this->call([$mycode, pseudonym::learner((int) $elsewhere->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([['code' => $mycode, 'email' => 'anna@example.com', 'phone' => '010 1234567']], $result['learners']);
    }

    /**
     * "Has none" and "not yours to see" never look alike.
     *
     * The manager's own person has neither and comes back with nulls. The
     * other person has both, but is outside the manager's units, so their code
     * does not come back at all.
     */
    public function test_in_reach_without_contact_details_is_null_and_out_of_reach_is_absent(): void {
        global $DB;

        set_config('showuseridentity', 'email,phone1');
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');
        $DB->set_field('user', 'email', '', ['id' => $mine->id]);
        $DB->set_field('user', 'phone1', '', ['id' => $mine->id]);
        $this->grant_at_system($manager, ['moodle/site:viewuseridentity']);

        $elsewhere = $this->getDataGenerator()->create_user(['email' => 'bob@example.com', 'phone1' => '06 12345678']);
        mapping::place((int) $elsewhere->id, mapping::save_node('Utrecht', 'department'));

        capability_holders::reset();
        mapping::reset();
        $this->setUser($manager);

        $mycode = pseudonym::learner((int) $mine->id);
        $result = $this->call([$mycode, pseudonym::learner((int) $elsewhere->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([['code' => $mycode, 'email' => null, 'phone' => null]], $result['learners']);
    }

    /**
     * Seeing identity fields inside one course is not seeing them across the site.
     *
     * A teacher sees addresses and phone numbers on their own course's
     * participants page; the report spans the organisation, so a grant held
     * there must hand over nothing here.
     */
    public function test_identity_rights_held_in_one_course_are_not_enough(): void {
        set_config('showuseridentity', 'email,phone1');
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');

        $course = $this->getDataGenerator()->create_course();
        $roleid = $this->getDataGenerator()->create_role();
        $coursecontext = \context_course::instance($course->id);
        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $manager->id, $coursecontext->id);

        $this->setUser($manager);

        $this->assertTrue(has_capability('moodle/site:viewuseridentity', $coursecontext), 'The fixture holds it there.');
        $this->assertSame([], access::visible_phone_fields());
        $this->assertFalse($this->call([pseudonym::learner((int) $mine->id)])['allowed']);
    }

    /**
     * Which phone fields may be seen follows the viewer's rights, not only the site setting.
     */
    public function test_visible_phone_fields_follow_the_viewers_rights(): void {
        set_config('showuseridentity', 'email,phone1,phone2');
        $viewer = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $this->assertSame([], access::visible_phone_fields(), 'Nothing without moodle/site:viewuseridentity.');

        $this->grant_at_system($viewer, ['moodle/site:viewuseridentity']);

        $this->assertSame(['phone1', 'phone2'], access::visible_phone_fields());

        set_config('showuseridentity', 'email');

        $this->assertSame([], access::visible_phone_fields(), 'Nothing when the site shows neither field.');
    }

    /**
     * A thousand is answered; more than a thousand is refused, not cut short.
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
     * A viewer who may not export hears "not allowed" whatever the size of the ask.
     */
    public function test_a_viewer_who_may_not_export_is_not_allowed_past_the_ceiling(): void {
        [$manager] = $this->manager_with_a_participant('anna@example.com', '010 1234567');
        $this->setUser($manager);

        $codes = [];

        for ($i = 0; $i <= learner_names::MAX_CODES; $i++) {
            $codes[] = 'p_' . str_pad((string) $i, 32, '0', STR_PAD_LEFT);
        }

        $this->assertCount(learner_names::MAX_CODES + 1, array_unique($codes));
        $this->assertSame(['allowed' => false, 'learners' => []], $this->call($codes));
    }

    /**
     * The lookup checks the permission itself, not only the service.
     *
     * Called directly, past the service's own check, as a manager who may see
     * their people's names: the names still come back and the contact details
     * do not. This is the second check inside contacts(), pinned.
     */
    public function test_contacts_refuses_a_viewer_without_identity_rights_on_its_own(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');
        $this->setUser($manager);

        $code = pseudonym::learner((int) $mine->id);

        $this->assertSame([$code => fullname($mine)], learner_names::resolve([$code]));
        $this->assertSame([], learner_names::contacts([$code]));
    }

    /**
     * A deleted account gives nothing, even though Moodle left its details stored.
     */
    public function test_a_deleted_user_gives_nothing(): void {
        global $DB;

        set_config('showuseridentity', 'email,phone1');
        $gone = $this->getDataGenerator()->create_user(['email' => 'gone@example.com', 'phone1' => '010 1234567']);
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        $this->setAdminUser();

        $result = $this->call([pseudonym::learner((int) $gone->id)]);

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['learners']);
    }

    /**
     * Handing contact details over is logged, as counts, and never with the details.
     */
    public function test_an_allowed_answer_is_logged_as_counts_only(): void {
        set_config('showuseridentity', 'email,phone1');
        $both = $this->getDataGenerator()->create_user(['email' => 'anna@example.com', 'phone1' => '010 1234567']);
        $mailonly = $this->getDataGenerator()->create_user(['email' => 'bob@example.com', 'phone1' => '']);
        $neither = $this->getDataGenerator()->create_user(['email' => 'carol@example.com', 'phone1' => '']);
        $this->set_email($neither, '');

        $codes = [
            pseudonym::learner((int) $both->id),
            pseudonym::learner((int) $mailonly->id),
            pseudonym::learner((int) $neither->id),
        ];

        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $this->call($codes);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof learner_contacts_resolved
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame(['emails' => 2, 'phones' => 1], $events[0]->other);
        $this->assertSame((int) get_admin()->id, (int) $events[0]->userid);
        $this->assertSame(\context_system::instance()->id, (int) $events[0]->contextid);

        // Nothing that names anybody, anywhere in what the log would keep.
        $logged = json_encode($events[0]->get_data()) . $events[0]->get_description();

        foreach (array_merge(['anna@example.com', 'bob@example.com', '010 1234567'], $codes) as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    /**
     * An event that leaves out one of its counts is a bug in the caller, and says so.
     */
    public function test_the_event_requires_both_counts(): void {
        $this->expectException(\coding_exception::class);

        learner_contacts_resolved::create([
            'context' => \context_system::instance(),
            'other' => ['emails' => 1],
        ]);
    }

    /**
     * A refusal hands nothing over, so it logs nothing.
     */
    public function test_a_refused_answer_is_not_logged(): void {
        [$manager, $mine] = $this->manager_with_a_participant('anna@example.com', '010 1234567');
        $this->setUser($manager);

        $sink = $this->redirectEvents();
        $this->call([pseudonym::learner((int) $mine->id)]);
        $events = array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof learner_contacts_resolved
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
        resolve_learner_contacts::execute([]);
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
            resolve_learner_contacts::execute_returns(),
            resolve_learner_contacts::execute($codes)
        );
    }

    /**
     * The service's learners as code => phone number, whatever order they came in.
     *
     * Built by hand rather than with array_column(), so a null phone stays a
     * null entry instead of depending on how that helper treats one.
     *
     * @param array $learners The service's learners.
     *
     * @return array<string, string|null>
     */
    private function phones_by_code(array $learners): array {
        $map = [];

        foreach ($learners as $entry) {
            $map[$entry['code']] = $entry['phone'];
        }

        ksort($map);

        return $map;
    }

    /**
     * Overwrite a stored address, past the generator's rules about what one looks like.
     *
     * @param \stdClass $user
     * @param string $email
     *
     * @return void
     */
    private function set_email(\stdClass $user, string $email): void {
        global $DB;

        $DB->set_field('user', 'email', $email, ['id' => $user->id]);
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
     * A manager named against a unit, and one person with contact details placed in it.
     *
     * @param string $email
     * @param string $phone
     *
     * @return array{0: \stdClass, 1: \stdClass} The manager and the person.
     */
    private function manager_with_a_participant(string $email, string $phone): array {
        $manager = $this->getDataGenerator()->create_user();
        $learner = $this->getDataGenerator()->create_user(['email' => $email, 'phone1' => $phone]);

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
