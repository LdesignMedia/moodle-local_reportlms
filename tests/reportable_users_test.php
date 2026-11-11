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
 * The shared "who can be reported" SQL condition.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\userstate;

/**
 * The condition keeps exactly the users the old literal "id > 2" kept.
 *
 * @covers \local_reportlms\local\userstate::reportable_sql
 */
final class reportable_users_test extends \advanced_testcase {
    /**
     * The placeholder form returns the same ids as the literal it replaced.
     */
    public function test_same_users_as_the_literal_threshold(): void {
        global $DB;

        $this->resetAfterTest();
        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(['deleted' => 1]);

        [$sql, $params] = userstate::reportable_sql('');
        $now = $DB->get_fieldset_select('user', 'id', "deleted = 0 AND {$sql}", $params);
        $before = $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 2');

        $this->assertEqualsCanonicalizing($before, $now);
        $this->assertNotContains('1', array_map('strval', $now));
        $this->assertNotContains('2', array_map('strval', $now));
    }

    /**
     * An alias is applied to the column, and a bad one is refused.
     */
    public function test_alias(): void {
        [$sql, $params] = userstate::reportable_sql('u');
        $this->assertSame('u.id >= :' . userstate::REPORTABLE_PARAM, $sql);
        $this->assertSame([userstate::REPORTABLE_PARAM => 3], $params);

        $this->expectException(\coding_exception::class);
        userstate::reportable_sql('u; DROP');
    }
}
