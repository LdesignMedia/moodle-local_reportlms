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
use local_reportlms\local\collector\certificates;
use local_reportlms\local\collector\completion;
use local_reportlms\local\collector\dimensions;
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
     * Which of these users Moodle now reports deleted.
     *
     * Asked of the user table rather than tracked, because deletion is Moodle's
     * fact and the plugin's own tracking table only ever learns about it
     * second-hand. A user row survives deletion — it is flagged, not removed —
     * which is also why their coded id can still be computed for the block.
     *
     * @param array<int, int> $userids
     *
     * @return array<int, int> User ids, in the order they were given.
     */
    protected static function departed(array $userids): array {
        global $DB;

        if ($userids === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        return array_map('intval', array_keys($DB->get_records_select(
            'user',
            "deleted = 1 AND id $insql",
            $params,
            'id ASC',
            'id'
        )));
    }

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

        /*
         * The departed, taken out before anything else looks at them.
         *
         * A deleted user has no enrolments left in Moodle and no profile
         * fields, so every collector below would return nothing for them and
         * they would arrive as a learner whose slice is empty — which the SaaS
         * reads as "taken off every course", not as "gone". The two are
         * different states and the second one cannot be inferred from an
         * absence, so it is stated in its own block instead.
         */
        $departed = self::departed($userids);
        $userids = array_values(array_diff($userids, $departed));

        // One collector pass for the whole set, then regroup per learner so
        // the chunker can keep slices whole.
        $peruser = array_fill_keys($userids, null);
        foreach ($userids as $uid) {
            $peruser[$uid] = ['enrolments' => [], 'completion' => [], 'attempts' => [],
                'grades' => [], 'activity_completion' => [], 'certificates' => []];
        }

        /*
         * Who counts, decided once and applied to every block.
         *
         * A course-role Manager is enrolled like anybody else, and without
         * this filter every chart counts them as a learner who never
         * finishes. The rule (a gradable role in the course, per Moodle's own
         * gradebook roles setting) lives in one place and gates all six fact
         * blocks together, so a manager cannot vanish from the enrolment
         * counts yet linger in the attempts bands.
         *
         * The enrolments block is the one exception, and deliberately so: see
         * the flag below. Every other block is still gated here.
         */
        $countable = gradable::pairs($userids);

        foreach (enrolments::collect($userids) as $row) {
            /*
             * Sent whether or not it counts, and flagged rather than dropped.
             *
             * Dropping a non-gradable enrolment made the slice lie about the
             * learner: somebody promoted to course Manager disappeared from
             * their own block while still being enrolled, so the row already on
             * the SaaS had nothing to overwrite it and stayed countable
             * forever. That is open issue #13, and it is also what stopped the
             * SaaS reading a slice as the whole truth about a learner — the
             * absence of a course could mean unenrolled or merely not gradable,
             * and no reader can tell those apart.
             *
             * With the flag it can. `counted` carries the gradable-role rule,
             * the aggregation filters on it exactly where it used to rely on
             * the row not being there, and no figure moves.
             *
             * The deadline rides with the enrolment, not with the completion.
             * Everybody enrolled has an enrolment; only somebody who has
             * started has a completion row. Carrying the deadline on the
             * completion would mean a learner who never opened the course could
             * not be late for it, which is the case the tile exists to show.
             */
            $peruser[$row->userid]['enrolments'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'course_ref' => pseudonym::course((int) $row->courseid),
                'enrolled_at' => self::iso((int) $row->enrolled_at),
                'active' => (bool) $row->active,
                'due_at' => $row->due_at !== null ? self::iso((int) $row->due_at) : null,
                'counted' => isset($countable[$row->userid][(int) $row->courseid]),
            ];
        }
        foreach (completion::collect($userids) as $row) {
            if (!isset($countable[$row->userid][(int) $row->courseid])) {
                continue;
            }
            $peruser[$row->userid]['completion'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'course_ref' => pseudonym::course($row->courseid),
                'status' => $row->status,
                'completed_at' => $row->completed_at !== null ? self::iso($row->completed_at) : null,
                'due_at' => $row->due_at !== null ? self::iso($row->due_at) : null,
            ];
        }
        foreach (attempts::collect($userids) as $row) {
            if (!isset($countable[$row->userid][(int) $row->courseid])) {
                continue;
            }
            $peruser[$row->userid]['attempts'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'activity_ref' => pseudonym::activity($row->cmid),
                'kind' => $row->kind,
                'summative' => $row->summative,
                'attempts' => $row->attempts,
                // When the latest of those attempts was finished. Null when it
                // is not known, which the report shows as a dash rather than
                // as a date.
                'last_attempt_at' => $row->lastattemptat !== null ? self::iso($row->lastattemptat) : null,
            ];
        }
        foreach (grades::collect($userids) as $row) {
            if (!isset($countable[$row->userid][(int) $row->courseid])) {
                continue;
            }
            $peruser[$row->userid]['grades'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'activity_ref' => pseudonym::activity($row->cmid),
                'grade' => $row->grade,
                'grade_max' => $row->grade_max,
            ];
        }
        foreach (certificates::collect($userids) as $row) {
            if (!isset($countable[$row->userid][(int) $row->courseid])) {
                continue;
            }
            $peruser[$row->userid]['certificates'][] = [
                'learner_pid' => pseudonym::learner($row->userid),
                'course_ref' => pseudonym::course($row->courseid),
                'issued_at' => self::iso($row->issuedat),
                // Null where the certificate plugin has no expiry concept at
                // all, which is every mod_customcert issue. Report LMS reads
                // that as "does not expire" rather than "expired".
                'expires_at' => $row->expiresat !== null ? self::iso($row->expiresat) : null,
            ];
        }
        foreach (activity_completion::collect($userids) as $row) {
            if (!isset($countable[$row->userid][(int) $row->courseid])) {
                continue;
            }
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

        /*
         * A push made entirely of departures still has to go.
         *
         * Delete a handful of accounts on a quiet site and every dirty learner
         * is one of them, leaving no chunk to carry the news. Without this the
         * departures are built and then dropped, the flags are never cleared
         * because nothing was accepted, and the same silent push is retried
         * every fifteen minutes for as long as the site runs.
         */
        if ($chunks === [] && $departed !== []) {
            $chunks[] = ['userids' => [], 'facts' => [], 'rows' => 0];
        }

        // Wrap each chunk in the envelope; the org tree and the dimensions
        // catalogue ride in the first chunk only, and only when they changed.
        $orgsection = self::org_section_if_changed();
        $dimensions = self::dimensions_section_if_changed();

        $result = [];
        foreach ($chunks as $i => $chunk) {
            $payload = self::envelope($snapshotat);
            if ($i === 0 && $orgsection !== null) {
                $payload['org'] = $orgsection;
            }
            if ($i === 0 && $dimensions !== null) {
                $payload['dimensions'] = $dimensions['section'];
            }
            if ($i === 0) {
                $payload['settings'] = self::settings_section();
            }
            /*
             * Who this chunk is a complete statement about.
             *
             * The facts alone cannot say it. A learner who has just been taken
             * off every course sends no enrolment rows at all, so reading the
             * roster out of the blocks would make the one case that matters —
             * somebody with nothing left — indistinguishable from somebody who
             * was never in this chunk.
             *
             * Named here, so the SaaS can treat their slice as the whole truth
             * and retire whatever it holds for them that this push does not
             * mention. Chunking already guarantees a learner is never split
             * across two chunks, which is what makes the claim safe to make.
             */
            $payload['learners'] = array_values(array_map(
                static fn(int $userid): string => pseudonym::learner($userid),
                $chunk['userids']
            ));
            /*
             * Departures ride in the first chunk, like the org tree: they are a
             * statement about the site rather than about this chunk's learners,
             * and repeating them per chunk would say the same thing many times.
             */
            if ($i === 0 && $departed !== []) {
                $payload['departures'] = array_values(array_map(
                    static fn(int $userid): string => pseudonym::learner($userid),
                    $departed
                ));
            }
            $payload['facts'] = array_filter($chunk['facts']);
            $result[] = (object) [
                'payload' => $payload,
                /*
                 * Whose dirty flag this chunk clears, which is not the same as
                 * whose slice it carries.
                 *
                 * The departed are deliberately absent from `learners` above —
                 * that list is a claim to have sent somebody's whole state, and
                 * for them there is none. They are still waiting on a flag,
                 * though, and leaving it set retries the same departure every
                 * fifteen minutes forever.
                 */
                'userids' => $i === 0 ? array_merge($chunk['userids'], $departed) : $chunk['userids'],
                'factrows' => $chunk['rows'],
                // For the push task to remember after a 202, never before.
                'dimhash' => ($i === 0 && $dimensions !== null) ? $dimensions['hash'] : null,
            ];
        }

        return $result;
    }

    /**
     * A batch carrying only the org tree and the catalogue, when nobody is dirty.
     *
     * Both sections ride in the first chunk of a learner push, and there is no
     * first chunk when no learner changed. So on a quiet site a course rename, a
     * course being hidden, a unit renamed in the mapping editor or a manager
     * named against one reached Report LMS only when some unrelated learner
     * happened to change. On a site between terms that is days, and on a site
     * that has finished its training it is never.
     *
     * The dashboard resolves coded ids against the catalogue and the tree, so
     * the visible symptom was an old department name on new numbers, or a
     * renamed course still showing its old title, with nothing on either side
     * saying anything was stale.
     *
     * Null when neither changed, which is the ordinary case and costs two
     * hashes.
     *
     * @param int $snapshotat
     *
     * @return \stdClass|null One chunk, shaped exactly like {@see build} returns.
     */
    /**
     * What this site counts as late, for the report to apply.
     *
     * Sent every time rather than only on change: it is two scalars, the
     * saving would be a hash to keep in step with them, and a setting that
     * silently fails to travel is worse than a few bytes on every push.
     *
     * The report decides what to do with it. This is a statement of the site's
     * policy, not a deadline computed here, so a second platform sends the same
     * pair and the rule itself stays in one place.
     *
     * @return array{deadline_source: string, deadline_days: int|null}
     */
    public static function settings_section(): array {
        $source = (string) get_config('local_reportlms', 'deadline_source');

        // Anything unrecognised, including a site that has never opened the
        // setting, means the behaviour the report has always had.
        if ($source !== 'period') {
            return ['deadline_source' => 'enrolment_end', 'deadline_days' => null];
        }

        $days = (int) get_config('local_reportlms', 'deadline_days');

        // A period of nothing is not a deadline. Rather than send a rule the
        // report would have to second-guess, say the other thing plainly.
        if ($days < 1 || $days > 3650) {
            return ['deadline_source' => 'enrolment_end', 'deadline_days' => null];
        }

        return ['deadline_source' => 'period', 'deadline_days' => $days];
    }

    /**
     * A batch carrying only what changed in the structure, for a push with no dirty learner.
     *
     * The org tree, the catalogue and the settings, each only when changed since
     * the last accepted push, and no facts.
     *
     * @param int $snapshotat
     *
     * @return \stdClass|null Null when nothing changed.
     */
    public static function structure_only(int $snapshotat): ?\stdClass {
        $orgsection = self::org_section_if_changed();
        $dimensions = self::dimensions_section_if_changed();
        $settings = self::settings_section();

        /*
         * The deadline rule is a reason to push on its own.
         *
         * Without this, an administrator who changes only that setting waits
         * for some unrelated learner or course to change before the report
         * hears about it. On a site that has finished its training that is
         * never, and the setting would look broken rather than slow.
         */
        $settingshash = sha1(json_encode($settings));
        $settingschanged = $settingshash !== (string) get_config('local_reportlms', 'lastsettingshash');

        if ($orgsection === null && $dimensions === null && !$settingschanged) {
            return null;
        }

        $payload = self::envelope($snapshotat);
        $payload['settings'] = $settings;

        if ($orgsection !== null) {
            $payload['org'] = $orgsection;
        }

        if ($dimensions !== null) {
            $payload['dimensions'] = $dimensions['section'];
        }

        // No facts at all. The push task clears an empty learner list and
        // stores the hashes exactly as it does for a first chunk, so nothing
        // downstream needs to know this batch is a different shape.
        $payload['facts'] = [];

        return (object) [
            'payload' => $payload,
            'userids' => [],
            'factrows' => 0,
            'dimhash' => $dimensions !== null ? $dimensions['hash'] : null,
            'settingshash' => $settingshash,
        ];
    }

    /**
     * The coded dimensions catalogue, or null when unchanged since the last
     * accepted push.
     *
     * Same discipline as the org tree: the hash is remembered in lastdimhash
     * by the push task after a 202, never here, so a refused batch does not
     * mark the catalogue as delivered.
     *
     * @return array{section: array, hash: string}|null
     */
    public static function dimensions_section_if_changed(): ?array {
        $dims = dimensions::collect();
        $hash = dimensions::state_hash($dims);

        if ($hash === (string) get_config('local_reportlms', 'lastdimhash')) {
            return null;
        }

        $section = ['courses' => [], 'activities' => []];
        foreach ($dims->courses as $course) {
            $section['courses'][] = [
                'course_ref' => pseudonym::course($course->courseid),
                'title' => \core_text::substr($course->title, 0, 255),
                'active' => $course->active,
                // Whether the course can complete anybody at all. Sent on every
                // dimension push, never once at discovery: a course gains and
                // loses criteria over its life and the report has to follow.
                'measurable' => $course->measurable,
                // Moodle's own created date, or null where it holds none.
                // Sent with every course for the same reason as measurable:
                // the catalogue is full state, and a field that rode along
                // only sometimes would be read as having been taken away.
                'created_at' => $course->timecreated !== null ? self::iso($course->timecreated) : null,
            ];
        }
        foreach ($dims->activities as $activity) {
            $section['activities'][] = [
                'activity_ref' => pseudonym::activity($activity->cmid),
                'course_ref' => pseudonym::course($activity->courseid),
                'title' => \core_text::substr($activity->title, 0, 255),
                'kind' => $activity->kind,
                'summative' => $activity->summative,
            ];
        }

        return ['section' => $section, 'hash' => $hash];
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

        $section = [
            'org_state_hash' => 'h_' . $hash,
            'nodes' => [],
            'members' => [],
            'managers' => [],
            'attrs' => [],
        ];
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
        foreach ($org->managers as $manager) {
            $section['managers'][] = [
                'manager_pid' => pseudonym::learner($manager->userid),
                'node_id' => $nodeid($manager->nodekey),
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
            // When somebody here read what leaves this site, or 0 if nobody
            // has. Report LMS refuses the batch until this is set, so it is
            // sent on every push rather than once. A timestamp only: who read
            // it stays on this side, which is the point of the statement.
            'privacy_acknowledged_at' => privacy_statement::acknowledged_at(),
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
