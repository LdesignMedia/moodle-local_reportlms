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
use local_reportlms\local\backoff;
use local_reportlms\local\batch_builder;
use local_reportlms\local\config;
use local_reportlms\local\connect_manager;
use local_reportlms\local\label_map;
use local_reportlms\local\plan_limit;
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
     * Seconds without an accepted push before a quiet site says it is still here.
     *
     * Report LMS takes a site that has not pushed for three days as gone quiet.
     * A site where nobody and nothing changed pushed nothing at all, so a day
     * leaves room for a cron that misses a few runs.
     */
    public const HEARTBEAT_AFTER = DAYSECS;

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

        // The switch on the settings page: connected, and still not sending.
        // Only a stored 1 means on, so an unset value is as good as a zero.
        if ((int) get_config('local_reportlms', 'enabled') !== 1) {
            mtrace('Report LMS: sending is turned off, skipping the push.');

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
        // Injectable so a test can hand back a refusal without a network.
        $client = $this->client ?? new api_client(config::baseurl());

        // The plan limit first, so this run sends what the plan allows now.
        $this->follow_plan($client);

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

        /*
         * After Report LMS said the key ended, a refused push waits longer each
         * time, like the heartbeat. Whatever it carries, learners, a change to
         * the organisation, the catalogue, the settings or the contact, or a
         * resend, the same key is refused again, so it does not go every
         * fifteen minutes. What waits stays waiting and goes once it can.
         */
        if (backoff::key_has_ended() && !backoff::due('push')) {
            $fails = backoff::fails('push');
            mtrace('Report LMS: this site\'s key has ended, not sending again yet after ' . $fails
                . ($fails === 1 ? ' failed try.' : ' failed tries.'));

            return;
        }

        // Who is inside the plan, worked out once for the whole run, after the
        // administrators above are known: they take no place.
        $plan = plan_limit::current();

        // Taken before the batch is read: a change after this moment is newer than
        // what is about to be sent, and must stay dirty when the push is accepted.
        $readat = time();
        $userids = userstate::get_dirty_batch(self::MAX_USERS_PER_RUN, $plan->cutoff());

        if ($userids) {
            try {
                $chunks = batch_builder::build($userids, time(), $plan);
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
            $heartbeat = self::heartbeat_due();
            $structure = batch_builder::structure_only(time(), $plan, $heartbeat);

            if ($structure === null) {
                $held = userstate::count_held($plan->cutoff());
                mtrace('Report LMS: nothing to push' . ($held ? ", {$held} held by the plan." : '.'));

                return;
            }

            if (!empty($structure->heartbeatonly)) {
                mtrace('Report LMS: nothing sent for a day, sending a heartbeat.');
            } else {
                mtrace('Report LMS: no learner changed, sending the organisation and catalogue.');
            }
            $chunks = [$structure];
        }

        $sent = 0;
        $accepted = false;
        foreach ($chunks as $i => $chunk) {
            try {
                $reply = $client->push_payload($chunk->payload);
            } catch (\moodle_exception $e) {
                mtrace("Report LMS push stopped at chunk {$i}: " . self::single_line($e->getMessage()));

                // A refused heartbeat waits longer each time instead of going every fifteen minutes.
                if (!empty($chunk->heartbeatonly)) {
                    backoff::failed('heartbeat');
                }

                // Every refused push counts, so the first one accepted after an
                // outage is known as a recovery. The count holds the next push
                // back only while the key has ended.
                backoff::failed('push');

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

            userstate::clear($chunk->userids, [], $readat);

            // Any accepted push counts, so a quiet site knows when a heartbeat
            // is due. Once per run: a run of many chunks writes it once.
            if (!$accepted) {
                $accepted = true;
                set_config(plan_limit::LAST_PUSH, time(), 'local_reportlms');

                // Accepted after the key ended or after refused pushes, the
                // heartbeat included: a recovery, so the plan limit is asked on
                // the next run rather than after the health call's wait. An
                // ordinary accepted push leaves that wait alone.
                if (backoff::key_has_ended() || backoff::fails('push') > 0) {
                    backoff::succeeded('health');
                }

                // Accepted, so the key works whatever an earlier answer said.
                backoff::succeeded('heartbeat');
                backoff::forget_key_ended();
            }

            // The reply carries the limit too; an older Report LMS sends none.
            plan_limit::store_reply($reply);

            // Rows for users that no longer exist have now been reported as
            // deleted once, so they are dropped rather than kept dirty.
            userstate::purge_orphans($chunk->userids);

            // Report LMS refuses a batch from a site that has not read the
            // statement, so an accepted one is proof it knows.
            \local_reportlms\local\privacy_statement::accepted_in_push();

            // The first chunk carried the org tree; remember what the SaaS
            // now holds so unchanged trees are omitted from later pushes.
            if (isset($chunk->payload['org'])) {
                // Sent because it changed, not because it went missing: if this
                // one is lost, that is a new loss, and its resend waits an hour.
                if ((string) get_config('local_reportlms', 'lastorghash') !== '') {
                    plan_limit::forget_resends();
                }

                set_config('lastorghash', substr($chunk->payload['org']['org_state_hash'], 2), 'local_reportlms');
                // When, so a hash Report LMS has not applied an hour later is sent again.
                set_config(plan_limit::ORG_SENT_AT, time(), 'local_reportlms');

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

            /*
             * A limit that changed while this run was sending.
             *
             * Every chunk was built with the limit this run started with. After
             * a lower one arrives, the rest could carry people the plan no
             * longer covers, so they wait: still dirty, they are cut again with
             * the new limit on the next run. A higher one stops the run the same
             * way, and the next run takes the people it now covers along. After
             * this chunk's own bookkeeping, so what was accepted is recorded as
             * accepted.
             */
            if (plan_limit::stored() !== $plan->limit()) {
                mtrace('Report LMS: the plan limit changed during this run, the rest goes on the next run.');
                $plan = plan_limit::current();

                break;
            }
        }

        $waiting = userstate::count_dirty();

        if ($plan->cutoff() === null) {
            mtrace("Report LMS push complete: {$sent} learners sent, {$waiting} still waiting.");

            return;
        }

        // Held people are dirty too, but they are not on their way: split them out.
        $held = userstate::count_held($plan->cutoff());
        mtrace("Report LMS push complete: {$sent} learners sent, " . ($waiting - $held) . ' still waiting, '
            . "{$held} held by the plan.");
    }

    /**
     * Ask Report LMS for the plan limit, unless it has been failing.
     *
     * Never a reason to stop: Report LMS being unreachable here says nothing
     * about the push, which is retried on its own terms, and what was stored
     * stays. But a key that has ended, or a Report LMS that keeps failing, is
     * not asked every fifteen minutes forever: each failure doubles the wait,
     * from an hour up to a day. A push accepted after failing, or a new Report
     * LMS address, ends the wait.
     *
     * While the key has ended the wait stops at an hour. A renewal moves the
     * end date of the same key, and only this call hears of it, so the pushes
     * held meanwhile go out within an hour of it. The pushes themselves still
     * wait up to a day.
     *
     * @param api_client $client
     *
     * @return void
     */
    protected function follow_plan(api_client $client): void {
        $cap = backoff::key_has_ended() ? backoff::FIRST : backoff::CAP;

        if (!backoff::due('health', $cap)) {
            // Said as it is: after an answer that the key ended, nothing failed.
            if (backoff::key_has_ended()) {
                mtrace('Report LMS: this site\'s key has ended, not asking again yet, keeping the stored plan limit.');

                return;
            }

            $fails = backoff::fails('health');
            mtrace('Report LMS: not asking for the plan limit yet after ' . $fails
                . ($fails === 1 ? ' failed try' : ' failed tries') . ', keeping the stored plan limit.');

            return;
        }

        try {
            $health = $client->health((string) get_config('local_reportlms', 'apikey'));
        } catch (\moodle_exception $e) {
            backoff::failed('health');
            mtrace('Report LMS: could not ask for the plan limit, keeping the stored plan limit: '
                . self::single_line($e->getMessage()));

            return;
        }

        // Outside the try: a database error here is not Report LMS being unreachable.
        if (plan_limit::follow_health($health)) {
            mtrace('Report LMS has not applied the organisation sent earlier, sending it again (resend '
                . plan_limit::org_resends() . ').');
        }

        if (plan_limit::key_ended($health)) {
            backoff::failed('health');

            if (!backoff::key_has_ended()) {
                set_config(backoff::KEY_ENDED, 1, 'local_reportlms');
            }

            mtrace('Report LMS says this site\'s key has ended: no heartbeat, and asking less often until it is renewed.');

            return;
        }

        backoff::succeeded('health');
        backoff::forget_key_ended();
    }

    /**
     * Whether a quiet site should say it is still here.
     *
     * After a day without an accepted push, unless Report LMS has said the key
     * ended or recent heartbeats were refused.
     *
     * @return bool
     */
    protected static function heartbeat_due(): bool {
        return (int) get_config('local_reportlms', plan_limit::LAST_PUSH) < time() - self::HEARTBEAT_AFTER
            && !backoff::key_has_ended()
            && backoff::due('heartbeat');
    }

    /**
     * A message on one line, so one refusal is one line of the cron log.
     *
     * @param string $message
     *
     * @return string
     */
    protected static function single_line(string $message): string {
        return trim(preg_replace('/[\r\n]+/', ' ', $message));
    }
}
