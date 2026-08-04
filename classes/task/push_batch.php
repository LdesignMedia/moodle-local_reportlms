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
 * The scheduled push to Report LMS.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\task;

use local_reportlms\local\api_client;
use local_reportlms\local\batch_builder;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\userstate;

/**
 * Sends changed learners to the SaaS, every fifteen minutes by default.
 *
 * The loop is deliberately conservative: learners are only marked clean
 * after the SaaS confirms with a 202, so a crash, timeout or refusal at any
 * point leaves their dirty flags set and the next run retries exactly them.
 * Combined with upsert-by-key on the SaaS side, a batch that was received
 * but not confirmed is harmless to send again.
 *
 * A refusal stops the run rather than skipping the chunk: refusals are
 * contract or auth problems that the next chunk would hit too, and pushing
 * later chunks first would deliver learners out of id order for no gain.
 */
class push_batch extends \core\task\scheduled_task {
    /**
     * Upper bound of learners taken per run.
     *
     * Bounds the memory and runtime of one cron slot. A backlog larger than
     * this (a first backfill on a big site) simply spills into the next run,
     * a few minutes later.
     */
    public const MAX_USERS_PER_RUN = 25000;

    /**
     * Task name shown in the scheduled task admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:push', 'local_reportlms');
    }

    /**
     * Build, sign and send; clear only what the SaaS confirmed.
     */
    public function execute(): void {
        if (!connect_manager::is_connected()) {
            mtrace('Report LMS is not connected, skipping the push.');

            return;
        }

        $userids = userstate::get_dirty_batch(self::MAX_USERS_PER_RUN);
        if (!$userids) {
            mtrace('Report LMS: nothing to push.');

            return;
        }

        $chunks = batch_builder::build($userids, time());

        $client = new api_client(config::baseurl());
        $pushpath = config::pushpath();
        $apikey = (string) get_config('local_reportlms', 'apikey');
        $signingsecret = (string) get_config('local_reportlms', 'signingsecret');
        $environment = (string) get_config('local_reportlms', 'environmentid');

        $sent = 0;
        foreach ($chunks as $i => $chunk) {
            $rawbody = json_encode($chunk->payload);
            $idempotencykey = $environment . ':' . $chunk->payload['snapshot_at'] . ':' . hash('sha256', $rawbody);

            try {
                $reply = $client->push($pushpath, $rawbody, $apikey, $signingsecret, $idempotencykey);
            } catch (\moodle_exception $e) {
                mtrace("Report LMS push stopped at chunk {$i}: " . $e->getMessage());
                mtrace('Unconfirmed learners stay dirty and retry next run.');

                return;
            }

            userstate::clear($chunk->userids);

            // The first chunk carried the org tree; remember what the SaaS
            // now holds so unchanged trees are omitted from later pushes.
            if (isset($chunk->payload['org'])) {
                set_config('lastorghash', substr($chunk->payload['org']['org_state_hash'], 2), 'local_reportlms');
            }
            // Same for the dimensions catalogue.
            if (!empty($chunk->dimhash)) {
                set_config('lastdimhash', $chunk->dimhash, 'local_reportlms');
            }

            $sent += count($chunk->userids);
            mtrace("chunk {$i}: {$chunk->factrows} fact rows for " . count($chunk->userids)
                . ' learners accepted (' . ($reply['batch_id'] ?? 'no batch id') . ').');
        }

        mtrace("Report LMS push complete: {$sent} learners sent, "
            . userstate::count_dirty() . ' still waiting.');
    }
}
