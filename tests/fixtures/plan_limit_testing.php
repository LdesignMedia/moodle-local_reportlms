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
 * Shared helpers for the plan limit tests.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms;

use local_reportlms\local\api_client;
use local_reportlms\local\batch_builder;
use local_reportlms\local\config;
use local_reportlms\local\mapping;
use local_reportlms\local\pseudonym;
use local_reportlms\local\push_refused_exception;
use local_reportlms\local\userstate;
use local_reportlms\task\push_batch;

/**
 * A connected site, a fake Report LMS, and ways to read what it was sent.
 */
trait plan_limit_testing {
    /** @var \stdClass The course everybody is enrolled on. */
    protected $course;

    /**
     * A connected site that pushed a moment ago, with one course.
     *
     * @return void
     */
    protected function connect_site(): void {
        set_config('apikey', 'k-' . str_repeat('a', 32), 'local_reportlms');
        set_config('signingsecret', str_repeat('s', 32), 'local_reportlms');
        set_config('launchsecret', 'launch-secret', 'local_reportlms');
        set_config('environmentid', 'env_test', 'local_reportlms');
        set_config('connectedat', time(), 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');
        set_config('salt', 'salt-for-unit-tests', 'local_reportlms');
        // Recent, so no test sends a heartbeat it did not ask for.
        set_config('lastpush', time(), 'local_reportlms');

        mapping::reset();

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Create people, enrolled on the course unless told otherwise, in id order.
     *
     * @param int $count
     * @param bool $enrol
     * @param array $fields Extra user fields, for every one of them.
     * @return \stdClass[]
     */
    protected function people(int $count, bool $enrol = true, array $fields = []): array {
        $people = [];

        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user($fields);

            if ($enrol) {
                $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
            }

            $people[] = $user;
        }

        return $people;
    }

    /**
     * Insert many plain user rows at once, without events.
     *
     * @param int $count
     * @param string $prefix Username prefix, unique per call.
     * @return int[] The new user ids, ascending.
     */
    protected function bulk_users(int $count, string $prefix): array {
        global $CFG, $DB;

        $now = time();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $prefix . $i,
                'firstname' => 'Bulk',
                'lastname' => (string) $i,
                'email' => $prefix . $i . '@example.com',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }

        $DB->insert_records('user', $rows);

        $ids = array_map('intval', $DB->get_fieldset_select('user', 'id', $DB->sql_like('username', ':prefix'), [
            'prefix' => $DB->sql_like_escape($prefix) . '%',
        ]));
        sort($ids);

        return $ids;
    }

    /**
     * The limits object Report LMS sends.
     *
     * @param int|null $users
     * @return array
     */
    protected function limits(?int $users): array {
        return ['limits' => ['users' => $users, 'courses' => null, 'activities' => null]];
    }

    /**
     * A fake Report LMS.
     *
     * The health call answers with the given reply, or fails when it is null.
     * Each push is accepted unless refuse is set; replies[n] answers the n-th
     * accepted push, reply answers the rest.
     *
     * @param array|null $health
     * @return api_client
     */
    protected function client(?array $health): api_client {
        $client = new class (config::baseurl()) extends api_client {
            /** @var array|null What the health call answers; null fails it. */
            public $health = [];
            /** @var array What a push answers besides its batch id. */
            public $reply = [];
            /** @var array Per-push answers, by push number from 0. */
            public $replies = [];
            /** @var string|null Refuse every push with this message. */
            public $refuse = null;
            /** @var array Decoded payloads accepted. */
            public $payloads = [];
            /** @var int Health calls made. */
            public $healthcalls = 0;
            /** @var int Pushes tried, accepted or not. */
            public $pushcalls = 0;

            /**
             * Answer the health call, or fail it.
             *
             * @param string $apikey
             * @return array
             */
            public function health(string $apikey): array {
                $this->healthcalls++;

                if ($this->health === null) {
                    throw new \moodle_exception('error:connectfailed', 'local_reportlms', '', 'unreachable');
                }

                return $this->health;
            }

            /**
             * Keep the payload and accept it, or refuse it.
             *
             * @param string $pushpath
             * @param string $rawbody
             * @param string $apikey
             * @param string $signingsecret
             * @param string $idempotencykey
             * @return array
             */
            public function push(
                string $pushpath,
                string $rawbody,
                string $apikey,
                string $signingsecret,
                string $idempotencykey
            ): array {
                $this->pushcalls++;

                if ($this->refuse !== null) {
                    throw new push_refused_exception('', $this->refuse);
                }

                $number = count($this->payloads);
                $this->payloads[] = json_decode($rawbody, true);

                return ['batch_id' => 'b-test'] + ($this->replies[$number] ?? $this->reply);
            }
        };
        $client->health = $health;

        return $client;
    }

    /**
     * Run the scheduled push and return what it said.
     *
     * @param api_client $client
     * @return string
     */
    protected function run_task(api_client $client): string {
        $task = new push_batch();
        $task->set_client($client);

        ob_start();
        $task->execute();

        return ob_get_clean();
    }

    /**
     * Every learner a client was sent, in order.
     *
     * @param api_client $client
     * @return string[]
     */
    protected function sent_learners(api_client $client): array {
        $learners = [];

        foreach ($client->payloads as $payload) {
            $learners = array_merge($learners, $payload['learners'] ?? []);
        }

        return $learners;
    }

    /**
     * Every coded person named in any fact row a client was sent.
     *
     * @param api_client $client
     * @return string[]
     */
    protected function sent_fact_pids(api_client $client): array {
        $pids = [];

        foreach ($client->payloads as $payload) {
            foreach ($payload['facts'] ?? [] as $rows) {
                foreach ($rows as $row) {
                    $pids[$row['learner_pid']] = $row['learner_pid'];
                }
            }
        }

        return array_values($pids);
    }

    /**
     * The organisation section a client was sent, or null.
     *
     * @param api_client $client
     * @return array|null
     */
    protected function sent_org(api_client $client): ?array {
        foreach ($client->payloads as $payload) {
            if (isset($payload['org'])) {
                return $payload['org'];
            }
        }

        return null;
    }

    /**
     * The people an organisation section marks held.
     *
     * @param array $org
     * @return string[]
     */
    protected function held_pids(array $org): array {
        return array_values(array_column(
            array_filter($org['attrs'], static fn(array $attr): bool => !empty($attr['held'])),
            'learner_pid'
        ));
    }

    /**
     * Coded ids of these people.
     *
     * @param \stdClass[] $people
     * @return string[]
     */
    protected function pids(array $people): array {
        return array_values(array_map(static fn($user) => pseudonym::learner((int) $user->id), $people));
    }

    /**
     * Whether this person is waiting to be sent.
     *
     * @param \stdClass $user
     * @return bool
     */
    protected function dirty(\stdClass $user): bool {
        global $DB;

        return (int) $DB->get_field(userstate::TABLE, 'dirty', ['userid' => $user->id]) === 1;
    }

    /**
     * Record everything as delivered, as the push task does after a 202.
     *
     * @return void
     */
    protected function pretend_everything_was_pushed(): void {
        global $DB;

        $org = batch_builder::org_section_if_changed();
        if ($org !== null) {
            set_config('lastorghash', substr($org['org_state_hash'], 2), 'local_reportlms');
        }

        $dims = batch_builder::dimensions_section_if_changed();
        if ($dims !== null) {
            set_config('lastdimhash', $dims['hash'], 'local_reportlms');
        }

        set_config('lastsettingshash', sha1(json_encode(batch_builder::settings_section())), 'local_reportlms');
        $DB->set_field(userstate::TABLE, 'dirty', 0, []);
    }
}
