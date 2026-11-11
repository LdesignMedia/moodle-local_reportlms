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
 * Tests for the capability check inside each external function.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\external\resolve_labels;
use local_reportlms\external\resolve_learner_contacts;
use local_reportlms\external\resolve_learner_emails;
use local_reportlms\external\resolve_learners;
use local_reportlms\local\mapping;

/**
 * Every external function refuses a user who does not hold the view capability.
 *
 * @covers \local_reportlms\external\resolve_labels
 * @covers \local_reportlms\external\resolve_learners
 * @covers \local_reportlms\external\resolve_learner_emails
 * @covers \local_reportlms\external\resolve_learner_contacts
 */
final class external_capability_test extends \advanced_testcase {
    /**
     * Cached mapping state would leak between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        mapping::reset();
    }

    /**
     * The four functions and the call that reaches each one's check.
     *
     * @return array
     */
    public static function functions_provider(): array {
        return [
            'labels' => [static fn() => resolve_labels::execute(['abc'])],
            'learners' => [static fn() => resolve_learners::execute(['abc'])],
            'emails' => [static fn() => resolve_learner_emails::execute(['abc'])],
            'contacts' => [static fn() => resolve_learner_contacts::execute(['abc'])],
        ];
    }

    /**
     * An ordinary user holds nothing and is refused.
     *
     * @dataProvider functions_provider
     * @param callable $call
     */
    public function test_an_ordinary_user_is_refused(callable $call): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $call();
    }

    /**
     * Each function is declared in db/services.php with the view capability.
     */
    public function test_services_declare_the_view_capability(): void {
        global $CFG;

        $functions = [];
        require($CFG->dirroot . '/local/reportlms/db/services.php');

        foreach (['resolve_labels', 'resolve_learners', 'resolve_learner_emails', 'resolve_learner_contacts'] as $name) {
            $this->assertSame('local/reportlms:view', $functions['local_reportlms_' . $name]['capabilities']);
        }
    }
}
