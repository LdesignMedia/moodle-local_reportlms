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

    /** @var string Plugin config key: when the organisation hash was last accepted. */
    public const ORG_SENT_AT = 'lastorghashat';

    /** @var string Plugin config key: when Report LMS last accepted a push. */
    public const LAST_PUSH = 'lastpush';

    /** @var int Seconds Report LMS has to apply an organisation before it is sent again. */
    public const RESEND_AFTER = 3600;

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
     * does a value this plugin cannot read. A null limit clears it.
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
            unset_config(self::USERS, 'local_reportlms');

            return;
        }

        if (is_int($users) && $users >= 0) {
            set_config(self::USERS, $users, 'local_reportlms');
        }
    }

    /**
     * Forget the limit, for a disconnect.
     *
     * @return void
     */
    public static function clear(): void {
        unset_config(self::USERS, 'local_reportlms');
    }

    /**
     * Follow what a health reply says: the limit, and whether the organisation arrived.
     *
     * Report LMS answers with the organisation hash it last applied. When that
     * is not the one this site last had accepted, and that was over an hour
     * ago, Report LMS lost it, so the saved hash is cleared and the
     * organisation goes again on this run. A newer mismatch is left alone:
     * Report LMS works through accepted batches in a queue.
     *
     * @param array $health The decoded health reply.
     * @return bool Whether the organisation will be sent again.
     */
    public static function follow_health(array $health): bool {
        self::store_reply($health);

        $applied = $health['org_state_hash_applied'] ?? null;

        if (!is_string($applied) || $applied === '') {
            return false;
        }

        $saved = (string) get_config('local_reportlms', 'lastorghash');

        if ($saved === '') {
            return false;
        }

        if (strpos($applied, 'h_') === 0) {
            $applied = substr($applied, 2);
        }

        if ($applied === $saved) {
            return false;
        }

        if ((int) get_config('local_reportlms', self::ORG_SENT_AT) > time() - self::RESEND_AFTER) {
            return false;
        }

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

        $pricing = config::baseurl() . self::PRICING_PATH;

        return get_string('limit:notice', 'local_reportlms', (object) [
            'limit' => self::number($limit),
            'total' => self::number($total),
            'held' => self::number($total - $limit),
            'pricing' => \html_writer::link($pricing, $pricing),
        ]);
    }

    /**
     * Who takes a place: the same people the organisation section lists, less the site administrators.
     *
     * @return array{0: string, 1: array} SQL against {user} u, and its parameters.
     */
    protected static function counted_where(): array {
        global $DB;

        $where = 'u.deleted = 0 AND u.id >= :firstreportable';
        $params = ['firstreportable' => userstate::FIRST_REPORTABLE_USERID];

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
