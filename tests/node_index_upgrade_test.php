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
 * Tests for the upgrade step that adds the unique index on unit names.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

/**
 * The 2026111004 step.
 *
 * @covers ::xmldb_local_reportlms_upgrade
 */
final class node_index_upgrade_test extends \advanced_testcase {
    /** @var int The version just before the step. */
    private const BEFORE = 2026111003;

    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/reportlms/db/upgrade.php');
    }

    /**
     * A node row, written the way an older version would have.
     *
     * @param string $name
     * @param string $kind
     * @param int|null $parentid
     *
     * @return int
     */
    private function node(string $name, string $kind, ?int $parentid): int {
        global $DB;

        return (int) $DB->insert_record('local_reportlms_node', (object) [
            'name' => $name, 'kind' => $kind, 'parentid' => $parentid, 'timemodified' => time(), 'usermodified' => 0,
        ]);
    }

    public function test_the_step_adds_the_index_and_settles_what_was_already_there(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_reportlms_node');
        $index = new \xmldb_index('kindparentname', XMLDB_INDEX_UNIQUE, ['kind', 'parentid', 'name']);
        $dbman->drop_index($table, $index);

        // Two top-level sectors of one name, stored with a null parent as older versions did.
        $first = $this->node('Ops', 'sector', null);
        $second = $this->node('ops', 'sector', null);
        $third = $this->node('Ops', 'sector', 0);
        $unique = $this->node('Quiet', 'sector', null);
        // And the same name under one parent, which is allowed under another.
        $under = $this->node('HR', 'department', $first);
        $twin = $this->node('HR', 'department', $first);
        $elsewhere = $this->node('HR', 'department', $unique);

        set_config('version', self::BEFORE, 'local_reportlms');
        $this->assertTrue(xmldb_local_reportlms_upgrade(self::BEFORE));

        $this->assertTrue($dbman->index_exists($table, $index));
        $this->assertSame(0, $DB->count_records_select('local_reportlms_node', 'parentid IS NULL'));

        // Nothing was deleted, the first of each keeps its name, the rest are numbered.
        $this->assertSame(7, $DB->count_records('local_reportlms_node'));
        $this->assertSame('Ops', $DB->get_field('local_reportlms_node', 'name', ['id' => $first]));
        $this->assertSame('ops (2)', $DB->get_field('local_reportlms_node', 'name', ['id' => $second]));
        $this->assertSame('Ops (3)', $DB->get_field('local_reportlms_node', 'name', ['id' => $third]));
        $this->assertSame('Quiet', $DB->get_field('local_reportlms_node', 'name', ['id' => $unique]));
        $this->assertSame('HR', $DB->get_field('local_reportlms_node', 'name', ['id' => $under]));
        $this->assertSame('HR (2)', $DB->get_field('local_reportlms_node', 'name', ['id' => $twin]));
        $this->assertSame('HR', $DB->get_field('local_reportlms_node', 'name', ['id' => $elsewhere]));
        $this->assertSame(2026111004, (int) get_config('local_reportlms', 'version'));
    }
}
