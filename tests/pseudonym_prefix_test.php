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
 * Tests for the coded identifier prefixes.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\pseudonym;

/**
 * Every coded identifier says what kind of thing it is before it says which.
 *
 * @covers \local_reportlms\local\pseudonym
 */
final class pseudonym_prefix_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
    }

    /**
     * Each level of the tree wears its own letter.
     */
    public function test_a_sector_and_a_department_are_told_apart_by_their_prefix(): void {
        $this->assertStringStartsWith('s_', pseudonym::node('sector', 'Operations'));
        $this->assertStringStartsWith('d_', pseudonym::node('department', 'Operations/Rotterdam'));
    }

    /**
     * Only the prefix moved.
     *
     * The hash is still taken over "org:<level>:<value>", which is what makes
     * the stored references rewritable in place rather than needing a full
     * re-push: a code keeps its 32 hex characters and changes its first letter.
     */
    public function test_only_the_first_character_changed(): void {
        $salt = get_config('local_reportlms', 'salt');
        $expected = substr(hash_hmac('sha256', 'org:department:Operations/Rotterdam', $salt), 0, 32);

        $this->assertSame('d_' . $expected, pseudonym::node('department', 'Operations/Rotterdam'));
    }

    /**
     * A level nobody has added yet still produces a usable code.
     *
     * A third level is a schema change we may make later. Producing a fatal
     * here would turn that into an outage rather than a migration.
     */
    public function test_an_unknown_level_falls_back_rather_than_failing(): void {
        $this->assertStringStartsWith('n_', pseudonym::node('division', 'North'));
    }

    /**
     * The other coded kinds are untouched.
     */
    public function test_the_other_prefixes_are_unchanged(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->assertStringStartsWith('c_', pseudonym::course((int) $course->id));
        $this->assertStringStartsWith('f_', pseudonym::functionref('Driver'));
    }
}
