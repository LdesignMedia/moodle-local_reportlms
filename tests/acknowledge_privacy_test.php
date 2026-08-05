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
 * Tests for the data statement acknowledgement.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\acknowledge_privacy;

/**
 * @covers \local_reportlms\external\acknowledge_privacy
 */
final class acknowledge_privacy_test extends \advanced_testcase {
    /**
     * An administrator's reading is recorded against this site, with who and when.
     */
    public function test_it_records_who_read_it_and_when(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $before = time();
        $result = acknowledge_privacy::execute();

        $this->assertTrue($result['acknowledged']);
        $this->assertGreaterThanOrEqual($before, $result['timestamp']);

        global $USER;
        $this->assertEquals($USER->id, get_config('local_reportlms', 'privacyackuserid'));
    }

    /**
     * The first reading stands.
     *
     * Re-recording on every visit would turn a dated record into a timestamp of
     * the last time somebody opened the page, which answers a different
     * question and is no use to whoever has to account for the decision.
     */
    public function test_a_second_visit_does_not_overwrite_the_first_record(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('privacyackdate', 1000, 'local_reportlms');
        set_config('privacyackuserid', 42, 'local_reportlms');

        $result = acknowledge_privacy::execute();

        $this->assertSame(1000, $result['timestamp']);
        $this->assertEquals(42, get_config('local_reportlms', 'privacyackuserid'));
    }

    /**
     * Only somebody who could connect the site can confirm they read what it sends.
     */
    public function test_a_teacher_cannot_acknowledge_on_the_administrations_behalf(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        acknowledge_privacy::execute();
    }

    /**
     * Nothing is recorded until somebody actually confirms.
     */
    public function test_a_fresh_site_holds_no_record(): void {
        $this->resetAfterTest();

        $this->assertEmpty(get_config('local_reportlms', 'privacyackdate'));
    }
}
