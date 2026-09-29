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
 * A site where no learner changed still says what changed about its shape.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

use local_reportlms\local\batch_builder;
use local_reportlms\local\mapping;

/**
 * The org tree and the catalogue used to ride only in the first chunk of a
 * learner push, and there is no first chunk when nobody is dirty. So a renamed
 * course, a renamed unit or a manager named in the editor waited for an
 * unrelated learner to change before it could travel. Between terms that is
 * days; on a site that has finished its training it is never.
 *
 * @covers \local_reportlms\local\batch_builder::structure_only
 */
final class structure_push_test extends \advanced_testcase {
    /** @var \stdClass */
    private $learner;

    /** @var int */
    private $courseid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        mapping::reset();

        /*
         * A site with something in it.
         *
         * Both sections are narrower than they look: the catalogue holds only
         * courses somebody is enrolled on or has completed, and the tree
         * declares only units that have members. An empty course and an empty
         * unit change neither hash, which is correct and is exactly what the
         * first draft of these tests got wrong.
         */
        $this->learner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Before']);
        $this->courseid = (int) $course->id;

        $this->getDataGenerator()->enrol_user($this->learner->id, $this->courseid);
    }

    /**
     * Nothing changed, so nothing is sent. The ordinary case, and it must stay
     * cheap: two hashes and no batch.
     */
    public function test_an_unchanged_site_sends_nothing(): void {
        // Whatever the current state is, record it as already delivered.
        $this->pretend_everything_was_pushed();

        $this->assertNull(batch_builder::structure_only(time()));
    }

    /**
     * A unit typed into the editor reaches Report LMS without waiting for a
     * learner to change, which is the whole point.
     */
    public function test_a_unit_gaining_somebody_travels_on_its_own(): void {
        $this->pretend_everything_was_pushed();

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $this->learner->id, $node);
        mapping::reset();

        $chunk = batch_builder::structure_only(time());

        $this->assertNotNull($chunk, 'The tree moved, so it has to travel.');
        $this->assertArrayHasKey('org', $chunk->payload);
    }

    /**
     * Naming a manager reaches Report LMS without waiting for a learner.
     *
     * Naming somebody writes one row and marks nobody dirty, deliberately: a
     * naming is not a fact about a learner, and the person named has not
     * changed. It travels because it is part of the org state, and the org
     * state has its own hash.
     *
     * Worth its own test because the two facts sit in different files. If
     * managers ever stopped being hashed with the tree, nothing else here would
     * fail, and a named manager would silently keep being counted among their
     * own people until an unrelated learner happened to change.
     */
    public function test_naming_a_manager_travels_on_its_own(): void {
        $this->pretend_everything_was_pushed();

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $this->learner->id, $node);
        mapping::reset();

        // Deliver that, so the naming below is the only thing left to send.
        $this->pretend_everything_was_pushed();
        $this->assertNull(batch_builder::structure_only(time()), 'Nothing outstanding yet.');

        $manager = $this->getDataGenerator()->create_user();
        mapping::add_manager($node, (int) $manager->id, null);
        mapping::reset();

        $chunk = batch_builder::structure_only(time());

        $this->assertNotNull($chunk, 'A naming is part of the org state.');
        $this->assertContains(
            \local_reportlms\local\pseudonym::learner((int) $manager->id),
            array_column($chunk->payload['org']['managers'] ?? [], 'manager_pid'),
            'And the manager has to be in it.'
        );
    }

    /**
     * Taking a naming back travels the same way.
     *
     * The direction that matters more: while a removal is in transit the person
     * still counts as a manager on the Report LMS side, and a removal that
     * never travelled would leave them excluded from their own department's
     * figures forever.
     */
    public function test_taking_a_naming_back_travels_on_its_own(): void {
        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $this->learner->id, $node);

        $manager = $this->getDataGenerator()->create_user();
        mapping::add_manager($node, (int) $manager->id, null);
        mapping::reset();

        $this->pretend_everything_was_pushed();
        $this->assertNull(batch_builder::structure_only(time()));

        $rows = mapping::managers();
        mapping::remove_manager((int) reset($rows)->id);
        mapping::reset();

        $chunk = batch_builder::structure_only(time());

        $this->assertNotNull($chunk, 'A removal moves the org state too.');
        $this->assertSame([], $chunk->payload['org']['managers'] ?? []);
    }

    /**
     * The batch carries no facts at all, and the receiving end accepts that:
     * its rule is that facts must be present and an array.
     */
    public function test_the_batch_carries_no_facts(): void {
        $this->pretend_everything_was_pushed();

        $node = mapping::save_node('Rotterdam', 'department');
        mapping::place((int) $this->learner->id, $node);
        mapping::reset();

        $chunk = batch_builder::structure_only(time());

        $this->assertSame([], $chunk->payload['facts']);
        $this->assertSame([], $chunk->userids, 'No learner is claimed, so none is cleared after a 202.');
        $this->assertSame(0, $chunk->factrows);
    }

    /**
     * A renamed course moves the catalogue hash, so the rename travels even
     * though no learner touched anything.
     */
    public function test_a_renamed_course_travels_on_its_own(): void {
        global $DB;

        $this->pretend_everything_was_pushed();

        $DB->set_field('course', 'fullname', 'After', ['id' => $this->courseid]);

        $chunk = batch_builder::structure_only(time());

        $this->assertNotNull($chunk);
        $this->assertArrayHasKey('dimensions', $chunk->payload);
        $this->assertNotEmpty($chunk->dimhash, 'The push task stores this only after a 202.');
    }

    /**
     * The hash is reported for the push task to store, never stored here.
     *
     * A refused batch must not mark the catalogue as delivered, or the next run
     * would omit the very thing that failed to arrive.
     */
    public function test_building_the_batch_does_not_mark_it_delivered(): void {
        global $DB;

        $this->pretend_everything_was_pushed();

        $before = (string) get_config('local_reportlms', 'lastdimhash');

        $DB->set_field('course', 'fullname', 'After', ['id' => $this->courseid]);

        batch_builder::structure_only(time());

        $this->assertSame(
            $before,
            (string) get_config('local_reportlms', 'lastdimhash'),
            'Only the push task writes this, and only after a 202.'
        );
    }

    /**
     * Record the current org tree and catalogue as already delivered, which is
     * what the push task does after Report LMS accepts a batch.
     */
    private function pretend_everything_was_pushed(): void {
        $org = batch_builder::org_section_if_changed();

        if ($org !== null) {
            set_config('lastorghash', substr($org['org_state_hash'], 2), 'local_reportlms');
        }

        $dims = batch_builder::dimensions_section_if_changed();

        if ($dims !== null) {
            set_config('lastdimhash', $dims['hash'], 'local_reportlms');
        }

        // The settings travel with the structure since the deadline rule moved
        // into them, and the push task records their hash the same way.
        set_config('lastsettingshash', sha1(json_encode(batch_builder::settings_section())), 'local_reportlms');
    }
}
