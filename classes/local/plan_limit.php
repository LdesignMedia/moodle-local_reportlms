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
 * How many people this site's plan covers, and which ones.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

/**
 * The plan limit Report LMS sends, and who is inside it.
 *
 * Report LMS says how many people this site may send data for. This side
 * decides which people that is, because only this side knows Moodle's order:
 * the people who are not deleted and not a site administrator, suspended
 * people included, in user id order, take the places. The first so many are
 * inside the plan. Everybody else is still listed in the organisation, so the
 * headcount stays true, but marked held, and their facts wait until the plan
 * grows. Site administrators take no place, and their data always goes out.
 *
 * An older Report LMS sends no limit at all, and then nothing here changes
 * what is sent.
 */
class plan_limit {
    /** @var string Plugin config key holding the limit; absent means no limit. */
    public const USERS = 'limitusers';

    /** @var int The largest limit kept: Report LMS accepts nothing larger back in limit_applied. */
    public const MAX_USERS = 2147483647;

    /** @var string Plugin config key: when the organisation hash was last accepted. */
    public const ORG_SENT_AT = 'lastorghashat';

    /** @var string Plugin config key: organisation resends in a row that Report LMS did not apply. */
    public const ORG_RESENDS = 'orgresends';

    /** @var string Plugin config key: when Report LMS last accepted a push. */
    public const LAST_PUSH = 'lastpush';

    /** @var string Where a site can buy a bigger plan, on the Report LMS host. */
    public const PRICING_PATH = '/pricing';

    /** @var int|null How many people the plan covers, or null for no limit. */
    private $limit;

    /** @var int|null Highest user id inside the plan, or null when nobody is held. */
    private $cutoff;

    /**
     * A plan limit as applied to one run.
     *
     * @param int|null $limit How many people the plan covers, or null for no limit.
     * @param int|null $cutoff Highest user id inside the plan, or null when nobody is held.
     */
    public function __construct(?int $limit, ?int $cutoff) {
        $this->limit = $limit;
        $this->cutoff = $cutoff;
    }

    /**
     * No limit: everybody is inside.
     *
     * @return self
     */
    public static function none(): self {
        return new self(null, null);
    }

    /**
     * The stored limit and its cut-off, worked out once for a run.
     *
     * One query, whatever the size of the site.
     *
     * @return self
     */
    public static function current(): self {
        $limit = self::stored();

        return new self($limit, self::cutoff_for($limit));
    }

    /**
     * How many people the plan covers, or null for no limit.
     *
     * @return int|null
     */
    public function limit(): ?int {
        return $this->limit;
    }

    /**
     * The highest user id inside the plan, or null when nobody is held.
     *
     * @return int|null
     */
    public function cutoff(): ?int {
        return $this->cutoff;
    }

    /**
     * Whether a counted person is outside the plan.
     *
     * Only for people who take a place: the caller leaves out site
     * administrators and people who are gone.
     *
     * @param int $userid
     * @return bool
     */
    public function holds(int $userid): bool {
        return $this->cutoff !== null && $userid > $this->cutoff;
    }

    /**
     * The limit as last told, or null for no limit.
     *
     * @return int|null
     */
    public static function stored(): ?int {
        $value = get_config('local_reportlms', self::USERS);

        if ($value === false || $value === '' || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Keep the limit a reply from Report LMS carries.
     *
     * A reply with no limits (an older Report LMS) keeps what is stored, and so
     * does a value this plugin cannot read. A null limit clears it. Written
     * only when it changes, so an unchanged limit costs no write on every run.
     *
     * @param array $reply A decoded connect, health or push reply.
     * @return void
     */
    public static function store_reply(array $reply): void {
        if (!array_key_exists('limits', $reply) || !is_array($reply['limits'])) {
            return;
        }

        if (!array_key_exists('users', $reply['limits'])) {
            return;
        }

        $users = $reply['limits']['users'];

        if ($users === null) {
            if (self::stored() !== null) {
                unset_config(self::USERS, 'local_reportlms');
            }

            return;
        }

        if (is_int($users) && $users >= 0 && $users <= self::MAX_USERS && $users !== self::stored()) {
            set_config(self::USERS, $users, 'local_reportlms');
        }
    }

    /**
     * Forget the limit and the resend count, for a disconnect.
     *
     * @return void
     */
    public static function clear(): void {
        if (self::stored() !== null) {
            unset_config(self::USERS, 'local_reportlms');
        }

        self::forget_resends();
    }

    /**
     * Start the organisation resend count again.
     *
     * @return void
     */
    public static function forget_resends(): void {
        if (get_config('local_reportlms', self::ORG_RESENDS) !== false) {
            unset_config(self::ORG_RESENDS, 'local_reportlms');
        }
    }

    /**
     * How many times in a row the organisation was sent again without Report LMS applying it.
     *
     * @return int
     */
    public static function org_resends(): int {
        return (int) get_config('local_reportlms', self::ORG_RESENDS);
    }

    /**
     * Whether a health reply says this site's key has ended.
     *
     * Report LMS answers a key that has ended too, so a site can learn that.
     * The reply's date is the one to trust: the key's end moves with the
     * licence, so the date stored at connect goes stale on a renewal.
     *
     * @param array $health The decoded health reply.
     * @return bool
     */
    public static function key_ended(array $health): bool {
        $value = $health['key_expires_at'] ?? null;

        if (!is_string($value) || $value === '') {
            return false;
        }

        $at = strtotime($value);

        return $at !== false && $at <= time();
    }

    /**
     * Follow what a health reply says: the limit, and whether the organisation arrived.
     *
     * Report LMS answers with the organisation hash it last applied, or null
     * when it never applied one. When that is not the one this site last had
     * accepted, and that was long enough ago, Report LMS lost it, so the saved
     * hash is cleared and the organisation goes again on this run. A newer
     * mismatch is left alone: Report LMS works through accepted batches in a
     * queue.
     *
     * Long enough is an hour for the first resend, then two, four and so on up
     * to a day, so a Report LMS that can never apply this organisation is not
     * sent the whole of it every hour. A match starts the count again, and so
     * does an organisation accepted because it changed rather than because it
     * went missing: losing that one is a new loss.
     *
     * @param array $health The decoded health reply.
     * @return bool Whether the organisation will be sent again.
     */
    public static function follow_health(array $health): bool {
        self::store_reply($health);

        // No key at all is an older Report LMS, which never says what it applied.
        if (!array_key_exists('org_state_hash_applied', $health)) {
            return false;
        }

        $applied = $health['org_state_hash_applied'];

        // Anything but a hash or an explicit null is not an answer this plugin can read.
        if ($applied !== null && (!is_string($applied) || $applied === '')) {
            return false;
        }

        $saved = (string) get_config('local_reportlms', 'lastorghash');

        if ($saved === '') {
            return false;
        }

        // An explicit null: Report LMS never applied any organisation for this
        // site, so a first section that was accepted and then lost in its
        // queue is sent again, by the same rule as a different hash.
        if ($applied !== null) {
            if (strpos($applied, 'h_') === 0) {
                $applied = substr($applied, 2);
            }

            if ($applied === $saved) {
                self::forget_resends();

                return false;
            }
        }

        $resends = self::org_resends();

        if ((int) get_config('local_reportlms', self::ORG_SENT_AT) > time() - backoff::wait($resends)) {
            return false;
        }

        set_config(self::ORG_RESENDS, $resends + 1, 'local_reportlms');
        unset_config('lastorghash', 'local_reportlms');

        return true;
    }

    /**
     * The highest user id inside a limit, or null when nobody is held.
     *
     * @param int|null $limit
     * @return int|null
     */
    protected static function cutoff_for(?int $limit): ?int {
        global $DB;

        if ($limit === null) {
            return null;
        }

        if ($limit <= 0) {
            return 0;
        }

        // The person in the last place, read on its own rather than the whole list.
        [$where, $params] = self::counted_where();
        $last = $DB->get_records_sql("SELECT u.id FROM {user} u WHERE {$where} ORDER BY u.id ASC", $params, $limit - 1, 1);

        // Fewer people than places: nobody is held.
        if (!$last) {
            return null;
        }

        return (int) array_key_first($last);
    }

    /**
     * How many people take a place on this site.
     *
     * @return int
     */
    public static function headcount(): int {
        global $DB;

        [$where, $params] = self::counted_where();

        return (int) $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE {$where}", $params);
    }

    /**
     * What the settings and report pages say when the plan holds people back.
     *
     * Held people are not left out altogether: they are listed by coded id
     * with their account status and login method, and any unit they manage,
     * so the headcount stays true. The text says so, rather than that nothing
     * is sent for them.
     *
     * @return string|null Null when there is no limit or everybody fits.
     */
    public static function notice(): ?string {
        $limit = self::stored();

        if ($limit === null) {
            return null;
        }

        $total = self::headcount();

        if ($total <= $limit) {
            return null;
        }

        $held = $total - $limit;
        $pricing = config::baseurl() . self::PRICING_PATH;

        $sentences = [
            $limit === 1
                ? get_string('limit:covers_one', 'local_reportlms')
                : get_string('limit:covers', 'local_reportlms', self::number($limit)),
            $total === 1
                ? get_string('limit:sitehas_one', 'local_reportlms')
                : get_string('limit:sitehas', 'local_reportlms', self::number($total)),
        ];

        if ($limit === 0) {
            $sentences[] = get_string('limit:shown_none', 'local_reportlms');
            $sentences[] = get_string('limit:held_all', 'local_reportlms');
        } else {
            $sentences[] = $limit === 1
                ? get_string('limit:shown_one', 'local_reportlms')
                : get_string('limit:shown', 'local_reportlms', self::number($limit));
            $sentences[] = $held === 1
                ? get_string('limit:held_one', 'local_reportlms')
                : get_string('limit:held', 'local_reportlms', self::number($held));
        }

        // A plain string, not a moodle_url: html_writer escapes it either way,
        // and moodle_url throws on an address parse_url() rejects, which would
        // take the whole settings page down with it.
        $sentences[] = get_string('limit:upgrade', 'local_reportlms', \html_writer::link($pricing, s($pricing)));

        return implode(' ', $sentences);
    }

    /**
     * Who takes a place: the same people the organisation section lists, less the site administrators.
     *
     * @return array{0: string, 1: array} SQL against {user} u, and its parameters.
     */
    protected static function counted_where(): array {
        global $DB;

        [$reportable, $params] = userstate::reportable_sql();
        $where = "u.deleted = 0 AND {$reportable}";

        $admins = site_admins::ids();

        if ($admins) {
            [$notin, $adminparams] = $DB->get_in_or_equal($admins, SQL_PARAMS_NAMED, 'admin', false);
            $where .= " AND u.id {$notin}";
            $params += $adminparams;
        }

        return [$where, $params];
    }

    /**
     * A count with this site's thousands separator, so 1500 reads 1,500.
     *
     * @param int $count
     * @return string
     */
    protected static function number(int $count): string {
        return number_format($count, 0, '', get_string('thousandssep', 'langconfig'));
    }
}
