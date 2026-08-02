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
 * Puts a link to the report where users actually land.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Creates and removes an HTML block holding a button to the report.
 *
 * Added automatically when the site connects, so the report is reachable
 * without anyone hunting for a plugin URL. Adding is idempotent, so a reconnect
 * never produces a second copy. An admin who removes it by hand is not
 * overruled: the removal is remembered and a later reconnect leaves it alone.
 *
 * Where it goes follows $CFG->defaulthomepage, because a block on a page nobody
 * opens is the same as no block at all.
 *
 * The Dashboard case is the awkward one. Moodle copies the default Dashboard to
 * each user the first time they open it, so writing only to the default reaches
 * nobody who has already logged in, which on a live site is close to everyone.
 * The default gets a row so future users inherit it, and every Dashboard a user
 * already owns gets one too, in a single insert-select rather than a query per
 * user.
 */
class dashboard_block {
    /** @var string Plugin config key holding the configdata of the blocks we created. */
    protected const CONFIG_KEY = 'dashboardblockdata';

    /** @var string Set when an admin removed the block, so connect stops re-adding it. */
    protected const DISMISSED_KEY = 'dashboardblockdismissed';

    /** @var int Moodle's context level for a user. */
    protected const CONTEXT_USER = 30;

    /**
     * Whether our block is currently in place.
     *
     * @return bool
     */
    public static function exists(): bool {
        global $DB;

        $configdata = (string) get_config('local_reportlms', self::CONFIG_KEY);

        if ($configdata === '') {
            return false;
        }

        return $DB->record_exists_sql(
            'SELECT 1 FROM {block_instances} WHERE blockname = :name AND ' . self::configdata_match($DB),
            ['name' => 'html', 'configdata' => $configdata],
        );
    }

    /**
     * The WHERE fragment matching our own blocks.
     *
     * configdata is a TEXT column, and Moodle refuses a plain equality on those
     * because not every database can index or compare them directly. Without
     * sql_compare_text this throws a dml_exception on every call after the
     * first, which is the sort of failure that only shows up once a site has
     * been connected for a while.
     *
     * @param \moodle_database $db
     * @return string
     */
    protected static function configdata_match(\moodle_database $db): string {
        return $db->sql_compare_text('configdata', 255) . ' = ' . $db->sql_compare_text(':configdata', 255);
    }

    /**
     * Add the block unless it is already there or an admin removed it.
     *
     * Called from the connect flow, where a failure must never matter: the site
     * is connected either way, and a missing convenience link is not a reason to
     * fail the thing the admin actually asked for.
     */
    public static function ensure(): void {
        if (self::exists() || (int) get_config('local_reportlms', self::DISMISSED_KEY) === 1) {
            return;
        }

        try {
            self::add();
        } catch (\Throwable $e) {
            debugging('local_reportlms could not add the block: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Add the block to whichever page this site uses as its home.
     *
     * @throws \moodle_exception When the html block is unavailable.
     */
    public static function add(): void {
        if (self::exists()) {
            return;
        }

        if (!\core_component::get_plugin_directory('block', 'html')) {
            throw new \moodle_exception('error:nohtmlblock', 'local_reportlms');
        }

        $configdata = self::configdata();

        if (self::homepage_is_dashboard()) {
            self::add_to_dashboards($configdata);
        } else {
            self::add_to_frontpage($configdata);
        }

        set_config(self::CONFIG_KEY, $configdata, 'local_reportlms');
        unset_config(self::DISMISSED_KEY, 'local_reportlms');
    }

    /**
     * Remove every block we created.
     *
     * Matched on the exact configdata we wrote, so a block an admin created or
     * edited themselves is never touched.
     *
     * @param bool $dismiss Remember the removal, so connect does not put it back.
     */
    public static function remove(bool $dismiss = true): void {
        global $DB;

        $configdata = (string) get_config('local_reportlms', self::CONFIG_KEY);

        if ($configdata !== '') {
            $instances = $DB->get_records_sql(
                'SELECT * FROM {block_instances} WHERE blockname = :name AND ' . self::configdata_match($DB),
                ['name' => 'html', 'configdata' => $configdata],
            );

            foreach ($instances as $instance) {
                blocks_delete_instance($instance);
            }
        }

        unset_config(self::CONFIG_KEY, 'local_reportlms');

        if ($dismiss) {
            set_config(self::DISMISSED_KEY, 1, 'local_reportlms');
        }
    }

    /**
     * Whether this site sends people somewhere other than the front page.
     *
     * @return bool
     */
    protected static function homepage_is_dashboard(): bool {
        global $CFG;

        return (int) ($CFG->defaulthomepage ?? HOMEPAGE_SITE) !== HOMEPAGE_SITE;
    }

    /**
     * One shared block on the site front page.
     *
     * @param string $configdata
     */
    protected static function add_to_frontpage(string $configdata): void {
        global $DB;

        $now = time();

        $DB->insert_record('block_instances', (object) [
            'blockname' => 'html',
            'parentcontextid' => \context_course::instance(SITEID)->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'site-index',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => -5,
            'configdata' => $configdata,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The default Dashboard, plus every Dashboard a user already owns.
     *
     * @param string $configdata
     */
    protected static function add_to_dashboards(string $configdata): void {
        global $DB;

        $now = time();

        // Future users, who are seeded from the default page.
        $default = $DB->get_record('my_pages', ['userid' => null, 'name' => '__default', 'private' => 1]);

        if ($default) {
            $DB->insert_record('block_instances', (object) [
                'blockname' => 'html',
                'parentcontextid' => \context_system::instance()->id,
                'showinsubcontexts' => 0,
                'requiredbytheme' => 0,
                'pagetypepattern' => 'my-index',
                'subpagepattern' => $default->id,
                'defaultregion' => 'side-pre',
                'defaultweight' => -5,
                'configdata' => $configdata,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Everyone who already has their own copy. One statement rather than a
        // query per user, so this stays sane on a site with 100k accounts.
        $sql = "INSERT INTO {block_instances}
                    (blockname, parentcontextid, showinsubcontexts, requiredbytheme,
                     pagetypepattern, subpagepattern, defaultregion, defaultweight,
                     configdata, timecreated, timemodified)
                SELECT 'html', ctx.id, 0, 0, 'my-index', mp.id, 'side-pre', -5, :configdata, :created, :modified
                  FROM {my_pages} mp
                  JOIN {context} ctx ON ctx.contextlevel = :userlevel AND ctx.instanceid = mp.userid
                 WHERE mp.private = 1
                   AND mp.userid IS NOT NULL";

        $DB->execute($sql, [
            'configdata' => $configdata,
            'created' => $now,
            'modified' => $now,
            'userlevel' => self::CONTEXT_USER,
        ]);
    }

    /**
     * The serialised block configuration.
     *
     * Also the marker used to find our blocks again: every instance we create
     * carries byte-identical configdata, so removal can match on it exactly
     * without recording thousands of ids.
     *
     * @return string
     */
    protected static function configdata(): string {
        return base64_encode(serialize((object) [
            'title' => get_string('block:title', 'local_reportlms'),
            'text' => self::content(),
            'format' => FORMAT_HTML,
        ]));
    }

    /**
     * The block body: one sentence and a button.
     *
     * The link goes to the plugin page rather than straight to the SaaS, because
     * the token has to be minted per view and only that page knows who is
     * looking.
     *
     * @return string
     */
    protected static function content(): string {
        $url = new \moodle_url('/local/reportlms/view.php');

        return \html_writer::tag('p', get_string('block:intro', 'local_reportlms'))
            . \html_writer::link(
                $url,
                get_string('block:button', 'local_reportlms'),
                ['class' => 'btn btn-primary']
            );
    }
}
