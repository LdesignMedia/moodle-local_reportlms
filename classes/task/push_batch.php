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
use local_reportlms\local\label_map;
use local_reportlms\local\source_sweep;
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
     * The one refusal this site can do something about.
     *
     * Matched by code rather than by message, because the message is written
     * for a human and may be translated or reworded.
     *
     * @var string
     */
    protected const REFUSED_UNACKNOWLEDGED = 'privacynotacknowledged';

    /** @var api_client|null Set by tests; the task builds its own otherwise. */
    protected $client = null;

    /**
     * Hand the task a client, for tests.
     *
     * @param api_client $client
     *
     * @return void
     */
    public function set_client(api_client $client): void {
        $this->client = $client;
    }

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

        // One push at a time: the ad-hoc run queued at connect and the
        // fifteen-minute schedule may fire together, and while re-sending is
        // harmless (upserts), sending the same learners twice side by side
        // is pure waste. First runner wins, the other simply skips.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_reportlms');
        $lock = $lockfactory->get_lock('push', 0);
        if (!$lock) {
            mtrace('Report LMS: another push is already running, skipping.');

            return;
        }

        try {
            $this->push();
        } finally {
            $lock->release();
        }
    }

    /**
     * The push itself, under the lock.
     */
    protected function push(): void {
        /*
         * Before the empty-queue return, not after.
         *
         * The profile fields publish the organisation, and the organisation
         * changes on a site where nobody is dirty: somebody renames a unit or
         * moves a person, and no learner fact moved with them. Cheap when
         * nothing differs, which is every run after the first.
         */
        \local_reportlms\local\writeback::follow_locks();
        $published = \local_reportlms\local\writeback::apply();

        if ($published) {
            mtrace('Report LMS: published the organisation into ' . $published . ' profile value(s).');
        }

        // Before the batch is picked, so somebody who has just become an
        // administrator is taken out of the figures by this run, not the next.
        $admins = \local_reportlms\local\site_admins::sync();

        if ($admins) {
            mtrace('Report LMS: ' . $admins . ' person(s) to send again, for joining or leaving the site administrators.');
        }

        /*
         * New certificates, every run rather than every night.
         *
         * A certificate is often issued a few seconds after the completion it
         * rewards, when the learner opens it. The push that sends the
         * completion can go out in between, and nothing marks the learner
         * again: this plugin observes no customcert event, and the nightly
         * sweep was the only thing that looked. So the report showed
         * "Completed" with no certificate for up to a day, or for weeks on a
         * site where the nightly task was switched off.
         *
         * Before the batch is picked, so whatever the check finds goes out
         * with this run. A certificate is only looked for once it is two
         * minutes old (source_sweep::CERTIFICATE_LAG), so it reaches the report
         * within one push interval plus that.
         *
         * A failed read here is logged and skipped rather than failing the
         * push: the learners already waiting still go out, and the watermark
         * did not move, so the next run reads from the same place again. It is
         * logged on every run it happens.
         */
        $certificates = 0;

        foreach (source_sweep::certificate_sources() as $key => $source) {
            try {
                $certificates += source_sweep::sweep($key, $source);
            } catch (\dml_exception $e) {
                mtrace('Report LMS: could not check ' . $source['table'] . ' for new certificates: ' . $e->getMessage());
            }
        }

        if ($certificates) {
            mtrace('Report LMS: ' . $certificates . ' person(s) with a new certificate to send.');
        }

        $userids = userstate::get_dirty_batch(self::MAX_USERS_PER_RUN);

        if ($userids) {
            try {
                $chunks = batch_builder::build($userids, time());
            } catch (\local_reportlms\local\push_refused_exception $e) {
                // A refusal here is the builder declining to miscount (no
                // gradable roles, contexts mid-rebuild), not a broken site. The
                // flags stay dirty; returning lets the task retry on its normal
                // slot instead of failing into Moodle's exponential backoff,
                // which would stall every push for hours over a transient.
                mtrace('Report LMS push refused before sending: ' . $e->getMessage());

                return;
            }
        } else {
            /*
             * Nobody changed, but the shape of the site might have.
             *
             * The org tree and the catalogue ride in the first chunk of a
             * learner push, and there is no first chunk when no learner is
             * dirty. So a course renamed or hidden, a unit renamed in the
             * mapping editor, or a manager named against one, waited for some
             * unrelated learner to change before it could travel. Between terms
             * that is days; on a site that has finished its training it is
             * never, and the dashboard goes on showing an old name against
             * current numbers.
             */
            $structure = batch_builder::structure_only(time());

            if ($structure === null) {
                mtrace('Report LMS: nothing to push.');

                return;
            }

            mtrace('Report LMS: no learner changed, sending the organisation and catalogue.');
            $chunks = [$structure];
        }

        // Injectable so a test can hand back a refusal without a network.
        $client = $this->client ?? new api_client(config::baseurl());
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

                /*
                 * A refusal for want of the data statement is a decision, not
                 * weather, and retrying it every fifteen minutes for months
                 * changes nothing. Disconnect instead.
                 *
                 * That is not a punishment, it is the only thing that makes the
                 * problem visible: a disconnected site shows the setup steps on
                 * its report page, and reading the statement is step two. A
                 * site left connected and refused shows a dashboard that never
                 * fills and says nothing about why.
                 *
                 * What was already collected stays where it is. The salt is
                 * kept too, so reconnecting resumes on the same coded
                 * identifiers rather than starting a second history beside the
                 * first.
                 */
                if (
                    $e instanceof \local_reportlms\local\push_refused_exception
                        && $e->reason === self::REFUSED_UNACKNOWLEDGED
                ) {
                    connect_manager::disconnect();
                    mtrace('Report LMS refused this site until somebody reads the data statement.');
                    mtrace('Disconnected. What was already sent is kept, and reconnecting resumes it.');

                    return;
                }

                mtrace('Unconfirmed learners stay dirty and retry next run.');

                return;
            }

            userstate::clear($chunk->userids);

            // Rows for users that no longer exist have now been reported as
            // deleted once, so they are dropped rather than kept dirty.
            userstate::purge_orphans($chunk->userids);

            // Report LMS refuses a batch from a site that has not read the
            // statement, so an accepted one is proof it knows.
            \local_reportlms\local\privacy_statement::accepted_in_push();

            // The first chunk carried the org tree; remember what the SaaS
            // now holds so unchanged trees are omitted from later pushes.
            if (isset($chunk->payload['org'])) {
                set_config('lastorghash', substr($chunk->payload['org']['org_state_hash'], 2), 'local_reportlms');

                // An org section only rides along when the tree changed, so
                // this is the one moment we know a label may have moved. The
                // dashboard resolves codes against that map, and a stale entry
                // would put an old department name on new numbers.
                label_map::purge();
            }
            // Same for the dimensions catalogue. A course moved into a new or
            // renamed category changes it, and the category's name is resolved
            // from the label map, so that map goes stale here too.
            if (!empty($chunk->dimhash)) {
                set_config('lastdimhash', $chunk->dimhash, 'local_reportlms');
                label_map::purge();
            }
            /*
             * And for the deadline rule, remembered only after the SaaS has
             * taken it. Storing it before would mark a setting as delivered
             * that a refusal had thrown away, and the next run would see no
             * change and never send it again.
             */
            if (!empty($chunk->settingshash)) {
                set_config('lastsettingshash', $chunk->settingshash, 'local_reportlms');
            }
            // Same discipline for the contact address.
            if (isset($chunk->payload['contact_email'])) {
                \local_reportlms\local\contact_email::mark_sent((string) $chunk->payload['contact_email']);
            }

            $sent += count($chunk->userids);
            mtrace("chunk $i: $chunk->factrows fact rows for " . count($chunk->userids)
                . ' learners accepted (' . ($reply['batch_id'] ?? 'no batch id') . ').');
        }

        mtrace("Report LMS push complete: {$sent} learners sent, "
            . userstate::count_dirty() . ' still waiting.');
    }
}
