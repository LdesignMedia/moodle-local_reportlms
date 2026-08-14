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
 * Tests for the front page block.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\dashboard_block;

/**
 * The block is stored markup, so every change to it is a migration.
 *
 * @covers \local_reportlms\local\dashboard_block
 */
final class dashboard_block_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_a_fresh_site_has_no_block(): void {
        $this->assertFalse(dashboard_block::exists());
        $this->assertSame([], dashboard_block::instances());
    }

    public function test_adding_one_makes_it_findable(): void {
        dashboard_block::add();

        $this->assertTrue(dashboard_block::exists());
        $this->assertCount(1, dashboard_block::instances());
    }

    /**
     * Identity is the contents, not a stored id.
     *
     * The block is a core html block, so there is nothing about it that says
     * local_reportlms except what it links to. Recording its id instead would
     * lose track of it the moment somebody moved or rebuilt it, which is how
     * sites ended up with a copy per uninstall-and-reconnect cycle.
     */
    public function test_an_unrelated_html_block_is_not_ours(): void {
        global $DB;

        dashboard_block::add();

        $mine = array_values(dashboard_block::instances())[0];

        $DB->insert_record('block_instances', (object) [
            'blockname' => 'html',
            'parentcontextid' => $mine->parentcontextid,
            'showinsubcontexts' => 0,
            'pagetypepattern' => $mine->pagetypepattern,
            'subpagepattern' => $mine->subpagepattern,
            'defaultregion' => 'content',
            'defaultweight' => 5,
            'configdata' => base64_encode(serialize((object) [
                'title' => 'Something else',
                'text' => '<p>A block that has nothing to do with us.</p>',
                'format' => FORMAT_HTML,
            ])),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertCount(1, dashboard_block::instances());
    }

    /**
     * Copies in the same slot are cleared, and exactly one survives.
     */
    public function test_deduplicate_leaves_one_per_slot(): void {
        global $DB;

        dashboard_block::add();
        $mine = array_values(dashboard_block::instances())[0];

        // Two more in the same slot, which is what a reconnect used to leave
        // behind: only the last was removable through the UI.
        foreach ([1, 2] as $copy) {
            $clone = clone $mine;
            unset($clone->id);
            $clone->defaultweight = $copy;
            $DB->insert_record('block_instances', $clone);
        }

        $this->assertCount(3, dashboard_block::instances());
        $this->assertSame(2, dashboard_block::deduplicate());
        $this->assertCount(1, dashboard_block::instances());
    }

    /**
     * Refresh rewrites what is there and never creates anything.
     *
     * A site that removed the block on purpose must stay without it: an upgrade
     * that put it back would be the plugin overruling a decision somebody made.
     */
    public function test_refresh_never_creates_a_block(): void {
        $this->assertSame(0, dashboard_block::refresh());
        $this->assertFalse(dashboard_block::exists());
    }

    public function test_refresh_brings_an_existing_block_up_to_date(): void {
        global $DB;

        dashboard_block::add();
        $mine = array_values(dashboard_block::instances())[0];

        // Age it: an older release wrote a body without the settings cog.
        $config = unserialize(base64_decode($mine->configdata));
        $config->text = '<div class="local-reportlms-block">'
            . '<a href="/local/reportlms/view.php">Open the report</a></div>';
        $DB->set_field(
            'block_instances',
            'configdata',
            base64_encode(serialize($config)),
            ['id' => $mine->id]
        );

        $this->assertSame(1, dashboard_block::refresh());

        $updated = $DB->get_record('block_instances', ['id' => $mine->id]);
        $text = unserialize(base64_decode($updated->configdata))->text;

        $this->assertStringContainsString('local-reportlms-cog', $text);
        $this->assertStringContainsString('/local/reportlms/view.php', $text);
    }

    /**
     * Removing it is remembered, so ensure() does not put it back.
     */
    public function test_a_removed_block_is_not_reinstated(): void {
        dashboard_block::add();
        $this->assertTrue(dashboard_block::exists());

        dashboard_block::remove();
        $this->assertFalse(dashboard_block::exists());

        dashboard_block::ensure();
        $this->assertFalse(dashboard_block::exists());
    }

    /**
     * The cog routes through admin.php, never straight at the settings page
     * a :manage holder cannot open.
     */
    public function test_the_cog_points_at_the_routing_page(): void {
        dashboard_block::add();

        $instances = dashboard_block::instances();
        $instance = reset($instances);
        $text = unserialize(base64_decode($instance->configdata))->text;

        $this->assertStringContainsString('/local/reportlms/admin.php', $text);
        $this->assertStringNotContainsString('/admin/settings.php', $text);
    }

    /**
     * The cog URL change must not have moved the block's identity: exists(),
     * adopt() and dedupe() all hang off the view.php link and the stored
     * signature, and a refresh over current markup is a no-op.
     */
    public function test_the_new_markup_is_still_recognised_as_ours(): void {
        dashboard_block::add();

        $this->assertSame(0, dashboard_block::refresh());
        $this->assertCount(1, dashboard_block::instances());
        $this->assertTrue(dashboard_block::exists());
    }
}
