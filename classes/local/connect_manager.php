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
 * Orchestrates the connect flow on the plugin side.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Connects this site to Report LMS.
 *
 * One round trip. The plugin generates a random verification token and serves
 * it on the public verify.php. Report LMS checks the licence for this site URL,
 * fetches that token back off the site to confirm the caller really operates
 * it, then returns the API key and signing secret.
 *
 * Domain control is the proof, not a shared secret, so reconnecting never needs
 * the old key. That matters: a site whose key expired or was lost has to be
 * able to reconnect, and that is exactly when it cannot present one.
 */
class connect_manager {
    /** @var int Seconds a pending verification token stays valid. */
    public const TOKEN_TTL = 900;

    /** @var api_client|null Client override for unit tests. */
    protected ?api_client $client;

    /**
     * Constructor.
     *
     * @param api_client|null $client Optional client override for tests.
     */
    public function __construct(?api_client $client = null) {
        $this->client = $client;
    }

    /**
     * The verification token verify.php should currently serve.
     *
     * Empty when no connect attempt is open or the last one has expired, so the
     * endpoint stops answering once it is no longer needed.
     *
     * @return string
     */
    public static function current_verification_token(): string {
        $expires = (int) get_config('local_reportlms', 'verifyexpires');

        if ($expires < time()) {
            return '';
        }

        return trim((string) get_config('local_reportlms', 'verifytoken'));
    }

    /**
     * Connect this site to Report LMS.
     *
     * @throws \moodle_exception When the licence check, the domain check or the request itself fails.
     */
    public function connect(): void {
        global $CFG;

        // Publish a fresh token before asking, so verify.php can answer the
        // moment Report LMS calls back for it.
        $token = random_string(64);
        set_config('verifytoken', $token, 'local_reportlms');
        set_config('verifyexpires', time() + self::TOKEN_TTL, 'local_reportlms');

        try {
            $client = $this->client ?? new api_client(config::baseurl());
            $data = $client->connect($CFG->wwwroot, $token);
        } finally {
            // The token is single use whatever happened. Leaving it live would
            // keep a public endpoint serving a value for no reason.
            unset_config('verifytoken', 'local_reportlms');
            unset_config('verifyexpires', 'local_reportlms');
        }

        set_config('environmentid', $data['environment_id'], 'local_reportlms');
        set_config('apikey', $data['api_key'], 'local_reportlms');
        set_config('signingsecret', $data['signing_secret'], 'local_reportlms');
        // Kept apart from the signing secret on purpose: this one signs tokens
        // that travel through a browser.
        set_config('launchsecret', $data['launch_secret'] ?? '', 'local_reportlms');
        set_config('enabled', 1, 'local_reportlms');

        if (!empty($data['push_path'])) {
            set_config('pushpath', $data['push_path'], 'local_reportlms');
        }
        if (!empty($data['embed_path'])) {
            set_config('embedpath', $data['embed_path'], 'local_reportlms');
        }
        if (!empty($data['tier'])) {
            set_config('tier', $data['tier'], 'local_reportlms');
        }
        if (!empty($data['expires_at'])) {
            // The key expires with the licence behind it, so this is also the
            // date the pushes stop if nobody renews.
            set_config('keyexpiresat', strtotime((string) $data['expires_at']) ?: '', 'local_reportlms');
        }
        set_config('connectedat', time(), 'local_reportlms');

        // A fresh connection has no pseudonymisation salt yet. Generate one now
        // and never send it anywhere: it is what keeps the SaaS unable to
        // reverse a learner id back to a person.
        if (trim((string) get_config('local_reportlms', 'salt')) === '') {
            set_config('salt', random_string(64), 'local_reportlms');
            set_config('saltepoch', 1, 'local_reportlms');
        }

        // The first push after connect is a full backfill, per the contract.
        // Flag every reportable learner now; the scheduled push task works
        // through them in chunks. This also covers a reconnect: if the
        // registry was not trusted, it is rebuilt from scratch here.
        userstate::mark_all_dirty();

        // Force the org tree and the dimensions catalogue into that backfill
        // too: what the SaaS held before this connection is not assumed.
        unset_config('lastorghash', 'local_reportlms');
        unset_config('lastdimhash', 'local_reportlms');

        // Start the backfill at the next cron tick rather than whenever the
        // fifteen-minute schedule next fires: an admin who just connected
        // should see data arriving, not an empty dashboard and a wait.
        \local_reportlms\task\push_now::queue();

        // Put the report within reach straight away. Never fatal: the site is
        // connected either way, and a missing convenience link is not a reason
        // to fail what the admin actually asked for.
        dashboard_block::ensure();
    }

    /**
     * Tear down the connection.
     *
     * The salt deliberately survives a disconnect. Regenerating it would change
     * every pseudonym, so a later reconnect would look like a completely new
     * population and orphan every fact already stored on the SaaS.
     */
    public static function disconnect(): void {
        unset_config('apikey', 'local_reportlms');
        unset_config('signingsecret', 'local_reportlms');
        unset_config('launchsecret', 'local_reportlms');
        unset_config('environmentid', 'local_reportlms');
        unset_config('tier', 'local_reportlms');
        unset_config('connectedat', 'local_reportlms');
        unset_config('keyexpiresat', 'local_reportlms');
        unset_config('verifytoken', 'local_reportlms');
        unset_config('verifyexpires', 'local_reportlms');
    }

    /**
     * Whether the plugin currently holds keys from a completed connect.
     *
     * @return bool
     */
    public static function is_connected(): bool {
        return trim((string) get_config('local_reportlms', 'apikey')) !== ''
            && trim((string) get_config('local_reportlms', 'signingsecret')) !== ''
            && (int) get_config('local_reportlms', 'connectedat') > 0;
    }
}
