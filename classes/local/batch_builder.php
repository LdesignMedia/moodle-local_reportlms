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
 * Builds contract payloads from collector output.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

use local_reportlms\local\collector\activity_completion;
use local_reportlms\local\collector\attempts;
use local_reportlms\local\collector\completion;
use local_reportlms\local\collector\enrolments;
use local_reportlms\local\collector\grades;
use local_reportlms\local\collector\org;

/**
 * Assembles push payloads: collectors in, contract v1.0 JSON out.
 *
 * The builder decides nothing about the data. It runs the collectors, swaps
 * every real id for its pseudonym, shapes rows exactly as the contract
 * specifies, and chunks at the fact-row cap without ever splitting one
 * learner across two chunks: a learner's slice is their complete current
 * state, and half a slice means wrong data on the SaaS.
 *
 * The org section is full state and rides in the first chunk only, and only
 * when its state hash differs from the last one the SaaS accepted.
 */
class batch_builder {
    /** @var int Contract cap on fact rows per batch. */
    public const MAX_FACT_ROWS = 5000;

    /**
     * Build the payload chunks for a set of dirty learners.
     *
     * @param array<int, int> $userids Real user ids, already exclusion-filtered.
     * @param int $snapshotat One timestamp shared by every chunk of this push.
     *
     * @return array<int, \stdClass> Chunks: {payload (array, json-ready),
     *         userids (the learners inside), factrows (int)}.
     */
    public static function build(array $userids, int $snapshotat): array {
        sort($userids);

        // One collector pass for the whole set, then regroup per learner so
        // the chunker can keep slices whole.
        $peruser = array_fill_keys($userids, null);
        foreach ($userids as $uid) {
            $peruser[$uid] = ['enrolments' => [], 'completion' => [], 'attempts' => [],
                'grades' => [], 'activity_completion' => []];
        }

        foreach (enrolments::collect($userids) as $row) {
            $peruser[$row->userid]['enrolments'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'course_ref' => pseudonym::course((int) $row->courseid),
                'enrolled_at' => self::iso((int) $row->enrolled_at),
                'active' => (bool) $row->active,
            ];
        }
        foreach (completion::collect($userids) as $row) {
            $peruser[$row->userid]['completion'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'course_ref' => pseudonym::course($row->courseid),
                'status' => $row->status,
                'completed_at' => $row->completed_at !== null ? self::iso($row->completed_at) : null,
                'due_at' => $row->due_at !== null ? self::iso($row->due_at) : null,
            ];
        }
        foreach (attempts::collect($userids) as $row) {
            $peruser[$row->userid]['attempts'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'activity_ref' => pseudonym::activity($row->cmid),
                'kind' => $row->kind,
                'summative' => $row->summative,
                'attempts' => $row->attempts,
            ];
        }
        foreach (grades::collect($userids) as $row) {
            $peruser[$row->userid]['grades'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'activity_ref' => pseudonym::activity($row->cmid),
                'grade' => $row->grade,
                'grade_max' => $row->grade_max,
            ];
        }
        foreach (activity_completion::collect($userids) as $row) {
            $peruser[$row->userid]['activity_completion'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'activity_ref' => pseudonym::activity($row->cmid),
                'kind' => $row->kind,
                'completed_at' => self::iso($row->completed_at),
            ];
        }

        // Chunk on learner boundaries.
        $chunks = [];
        $current = ['userids' => [], 'facts' => [], 'rows' => 0];
        foreach ($peruser as $uid => $blocks) {
            $slicerows = 0;
            foreach ($blocks as $rows) {
                $slicerows += count($rows);
            }
            if ($current['rows'] > 0 && $current['rows'] + $slicerows > self::MAX_FACT_ROWS) {
                $chunks[] = $current;
                $current = ['userids' => [], 'facts' => [], 'rows' => 0];
            }
            $current['userids'][] = $uid;
            $current['rows'] += $slicerows;
            foreach ($blocks as $block => $rows) {
                foreach ($rows as $row) {
                    $current['facts'][$block][] = $row;
                }
            }
        }
        if ($current['userids']) {
            $chunks[] = $current;
        }

        // Wrap each chunk in the envelope; org rides in the first chunk only,
        // and only when the tree actually changed.
        $orgsection = self::org_section_if_changed();

        $result = [];
        foreach ($chunks as $i => $chunk) {
            $payload = self::envelope($snapshotat);
            if ($i === 0 && $orgsection !== null) {
                $payload['org'] = $orgsection;
            }
            $payload['facts'] = array_filter($chunk['facts']);
            $result[] = (object) [
                'payload' => $payload,
                'userids' => $chunk['userids'],
                'factrows' => $chunk['rows'],
            ];
        }

        return $result;
    }

    /**
     * The coded org section, or null when unchanged since the last accepted push.
     *
     * The raw-state hash is remembered in lastorghash by the push task after
     * a 202, never here: building a payload that is then refused must not
     * mark the tree as delivered.
     *
     * @return array|null
     */
    public static function org_section_if_changed(): ?array {
        $org = org::collect();
        $hash = org::state_hash($org);

        if ($hash === (string) get_config('local_reportlms', 'lastorghash')) {
            return null;
        }

        // A null parent means top level: the SaaS stores the root as null,
        // there is no root sentinel node.
        $nodeid = function (?string $key): ?string {
            if ($key === null) {
                return null;
            }
            [$level, $value] = explode(':', $key, 2);
            return pseudonym::node($level, $value);
        };

        $section = ['org_state_hash' => 'h_' . $hash, 'nodes' => [], 'members' => [], 'managers' => [], 'attrs' => []];
        foreach ($org->nodes as $node) {
            $section['nodes'][] = [
                'node_id' => $nodeid($node->key),
                'parent_id' => $nodeid($node->parent),
                'kind' => $node->kind,
            ];
        }
        foreach ($org->members as $member) {
            $section['members'][] = [
                'learner_pid' => pseudonym::learner($member->userid),
                'node_id' => $nodeid($member->nodekey),
            ];
        }
        foreach ($org->attrs as $attr) {
            $section['attrs'][] = [
                'learner_pid' => pseudonym::learner($attr->userid),
                'auth' => $attr->auth,
                'active' => $attr->active,
                'function' => $attr->function !== null ? pseudonym::functionref($attr->function) : null,
            ];
        }

        return $section;
    }

    /**
     * The raw org state hash, for the push task to remember after a 202.
     *
     * @return string
     */
    public static function current_org_hash(): string {
        return org::state_hash(org::collect());
    }

    /**
     * The contract envelope.
     *
     * @param int $snapshotat
     *
     * @return array
     */
    protected static function envelope(int $snapshotat): array {
        global $CFG;

        return [
            'contract_version' => '1.0',
            'platform' => config::PLATFORM,
            'platform_version' => (string) $CFG->release,
            'plugin_version' => (int) get_config('local_reportlms', 'version'),
            'environment_id' => (string) get_config('local_reportlms', 'environmentid'),
            'snapshot_at' => self::iso($snapshotat),
        ];
    }

    /**
     * Unix time to the contract's ISO 8601 UTC form.
     *
     * @param int $timestamp
     *
     * @return string
     */
    protected static function iso(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
