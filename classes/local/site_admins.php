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
 * The site administrators, and noticing when that list changes.
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   local_reportlms
 * @copyright 2026 LdesignMedia.nl - Luuk Verhoeven
 * @author    Nihaal Shaikh
 */

namespace local_reportlms\local;

/**
 * Who the site administrators are, and who stopped or started being one.
 *
 * An administrator is never counted ({@see gradable}), so becoming one or
 * stopping changes every figure the person appears in, and nothing about
 * their enrolments, grades or completions moves with it. Their slice has to
 * be sent again all the same.
 *
 * Compared against the list as it was last seen rather than observed as an
 * event. Moodle raises one for a change made on the Site administrators page
 * or with admin/cli/cfg.php, but not for a plain set_config() from a script,
 * and a list forced in config.php never raises anything at all. A comparison
 * on every push catches all of them, and costs one config read on the runs
 * where nothing changed, which is nearly all of them.
 */
class site_admins {
    /** @var string Plugin config key holding the list as last seen. */
    public const SEEN = 'siteadminsseen';

    /**
     * The current site administrators.
     *
     * The setting is_siteadmin() reads, but from the database rather than from
     * $CFG. The push runs inside cron, and a cron process keeps the $CFG it
     * started with: a list read from there would be minutes old, and a run on
     * an old list after a run on the new one would put somebody back into the
     * figures for a cycle. A list forced in config.php is that list, exactly as
     * the Site administrators page treats it.
     *
     * @return array<int, int> User ids, ascending.
     */
    public static function ids(): array {
        global $CFG, $DB;

        if (array_key_exists('siteadmins', $CFG->config_php_settings ?? [])) {
            return self::parse((string) $CFG->siteadmins);
        }

        return self::parse((string) $DB->get_field('config', 'value', ['name' => 'siteadmins']));
    }

    /**
     * Mark everybody who became or stopped being an administrator.
     *
     * The list is remembered only once everybody on the difference is marked,
     * so a failure part way leaves the old list in place and the next run
     * marks them again. The first run after an upgrade has nothing remembered,
     * which makes every current administrator new: that is what takes the ones
     * Report LMS was already counting back out.
     *
     * @return int How many people were marked.
     */
    public static function sync(): int {
        global $DB;

        $now = self::ids();
        $remembered = (string) get_config('local_reportlms', self::SEEN);

        // Without user id 2, which is never reported whatever its standing,
        // so the count says how many people actually go again.
        $changed = array_filter(
            array_merge(array_diff($now, self::parse($remembered)), array_diff(self::parse($remembered), $now)),
            static fn(int $userid): bool => $userid >= userstate::FIRST_REPORTABLE_USERID
        );

        /*
         * Only accounts that exist. A list typed into config.php can name an id
         * nobody has, and marking it would send Report LMS a learner with
         * nothing behind them. A deleted administrator is the deletion's
         * business: user_deleted marked them already.
         */
        if ($changed) {
            [$insql, $params] = $DB->get_in_or_equal($changed);
            $changed = $DB->get_fieldset_select('user', 'id', "deleted = 0 AND id {$insql}", $params);
        }

        foreach ($changed as $userid) {
            userstate::mark_dirty((int) $userid);
        }

        $list = implode(',', $now);

        if ($list !== $remembered) {
            set_config(self::SEEN, $list, 'local_reportlms');
        }

        return count($changed);
    }

    /**
     * A comma-separated id list as sorted, unique, positive ints.
     *
     * Moodle writes the setting without spaces, but a list typed into
     * config.php may have them.
     *
     * @param string $list
     * @return array<int, int>
     */
    private static function parse(string $list): array {
        $ids = array_unique(array_filter(array_map('intval', explode(',', $list)), static fn(int $id): bool => $id > 0));
        sort($ids);

        return array_values($ids);
    }
}
