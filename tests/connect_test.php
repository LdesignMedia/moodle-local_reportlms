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
 * Tests for the connect web service.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\connect;

/**
 * Connecting a site is the one moment its salt and its key are minted.
 *
 * @covers \local_reportlms\external\connect
 */
final class connect_test extends \advanced_testcase {
    /**
     * Connecting is a change to this site, so only somebody who may change it can ask.
     *
     * The request arrives from a frame served by Report LMS, so this check is
     * the whole of the protection: without it, any page that could get an
     * administrator's browser to post here could connect their site.
     */
    public function test_a_teacher_cannot_connect_the_site(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        connect::execute();
    }

    /**
     * A site that cannot connect gets a sentence, not an exception.
     *
     * The caller is a panel inside a frame that has to say what went wrong, and
     * a licence that has not been issued yet is the ordinary case rather than a
     * fault. Anything that escapes as an exception reaches the frame as a bare
     * failure with nothing to act on.
     */
    public function test_a_failure_comes_back_as_words_rather_than_an_exception(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // No licence and nothing listening, so this cannot succeed.
        set_config('baseurl', 'https://reportlms.invalid', 'local_reportlms');

        $result = connect::execute();

        $this->assertFalse($result['connected']);
        $this->assertNotSame('', $result['error']);
        $this->assertFalse(\local_reportlms\local\connect_manager::is_connected());
    }
}
