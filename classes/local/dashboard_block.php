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
     * What makes an html block one of ours: its body links to the report page.
     *
     * Deliberately the link and not a version marker. It is true of every block
     * this plugin has ever written, in any language and at any release, so a
     * site that has been through several of them is still recognisable.
     *
     * @var string
     */
    protected const SIGNATURE = '/local/reportlms/view.php';

    /** @var array<int, string> The only page types we ever place a block on. */
    protected const PAGE_TYPES = ['site-index', 'my-index'];

    /**
     * Whether our block is currently in place.
     *
     * @return bool
     */
    public static function exists(): bool {
        global $DB;

        $configdata = (string) get_config('local_reportlms', self::CONFIG_KEY);

        if ($configdata !== '' && $DB->record_exists_sql(
            'SELECT 1 FROM {block_instances} WHERE blockname = :name AND ' . self::configdata_match($DB),
            ['name' => 'html', 'configdata' => $configdata],
        )) {
            return true;
        }

        // The config says there is no block, so ask the blocks themselves.
        //
        // This is the case that used to produce duplicates. The config lives in
        // config_plugins, which Moodle wipes when the plugin is uninstalled, and
        // the blocks live in block_instances, which it does not: an uninstall
        // and reinstall therefore left real blocks on the site with nothing
        // pointing at them, connect saw "no block" and added another pair, and
        // the pair before that became unremovable. Three cycles, six blocks.
        return self::adopt() !== null;
    }

    /**
     * Take ownership of blocks that are ours but that the config has lost.
     *
     * @return string|null The configdata now recorded, or null if there are none.
     */
    protected static function adopt(): ?string {
        $instances = self::instances();

        if ($instances === []) {
            return null;
        }

        // The newest generation, so removal matches what a current add would
        // write rather than an older wording of the same block.
        $configdata = (string) end($instances)->configdata;

        set_config(self::CONFIG_KEY, $configdata, 'local_reportlms');

        return $configdata;
    }

    /**
     * Every block instance on this site that this plugin created.
     *
     * Reads the rows rather than matching a stored blob, so blocks written by
     * an earlier release, in another language, or before an uninstall are all
     * found. Narrowed to the html block on the two page types we use, so the
     * decode only runs on rows that could plausibly be ours.
     *
     * @return array<int, \stdClass> Keyed and ordered by id.
     */
    public static function instances(): array {
        global $DB;

        [$pagesql, $pageparams] = $DB->get_in_or_equal(self::PAGE_TYPES, SQL_PARAMS_NAMED, 'pt');

        $recordset = $DB->get_recordset_select(
            'block_instances',
            "blockname = :name AND pagetypepattern {$pagesql}",
            array_merge(['name' => 'html'], $pageparams),
            'id'
        );

        $ours = [];

        foreach ($recordset as $row) {
            if (self::is_ours($row->configdata)) {
                $ours[$row->id] = $row;
            }
        }

        $recordset->close();

        return $ours;
    }

    /**
     * Whether a block's stored configuration is one we wrote.
     *
     * @param string|null $configdata
     *
     * @return bool
     */
    protected static function is_ours(?string $configdata): bool {
        if ((string) $configdata === '') {
            return false;
        }

        $decoded = base64_decode((string) $configdata, true);

        if ($decoded === false) {
            return false;
        }

        // Only stdClass, and never a class this site happens to autoload: a
        // block's configdata is admin-editable, and unserialising it into
        // arbitrary objects would be an object injection surface.
        $config = @unserialize($decoded, ['allowed_classes' => ['stdClass']]);

        if (!is_object($config) || !isset($config->text)) {
            return false;
        }

        return strpos((string) $config->text, self::SIGNATURE) !== false;
    }

    /**
     * Leave one of our blocks per page, delete the rest.
     *
     * A page can legitimately hold several html blocks; what it must not hold
     * is two copies of ours. Keeps the oldest in each slot, because that is the
     * one an admin may have already moved or reordered.
     *
     * @return int How many were deleted.
     */
    public static function deduplicate(): int {
        global $CFG;

        require_once($CFG->dirroot . '/lib/blocklib.php');

        $seen = [];
        $deleted = 0;

        foreach (self::instances() as $instance) {
            $slot = $instance->parentcontextid . '|' . $instance->pagetypepattern
                . '|' . (string) $instance->subpagepattern;

            if (!isset($seen[$slot])) {
                $seen[$slot] = true;
                continue;
            }

            blocks_delete_instance($instance);
            $deleted++;
        }

        return $deleted;
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
        if ((int) get_config('local_reportlms', self::DISMISSED_KEY) === 1) {
            return;
        }

        try {
            if (self::exists()) {
                // Connect is the moment this runs on a site that may already
                // carry duplicates from before this check existed, so tidy
                // rather than merely decline to add another.
                self::deduplicate();

                return;
            }

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
            self::deduplicate();

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
     * Rewrite our blocks to the current wording and markup.
     *
     * Blocks store their body, so a change to what add() would write today
     * reaches only sites that have not connected yet. An upgrade that changes
     * the block calls this so existing sites are not left on last release's
     * version of it for good.
     *
     * Untouched if there is nothing of ours on the site: this never creates.
     *
     * @return int How many were rewritten.
     */
    public static function refresh(): int {
        global $DB;

        $instances = self::instances();

        if ($instances === []) {
            return 0;
        }

        $configdata = self::configdata();
        $now = time();
        $rewritten = 0;

        foreach ($instances as $instance) {
            if ((string) $instance->configdata === $configdata) {
                continue;
            }

            $DB->update_record('block_instances', (object) [
                'id' => $instance->id,
                'configdata' => $configdata,
                'timemodified' => $now,
            ]);
            $rewritten++;
        }

        set_config(self::CONFIG_KEY, $configdata, 'local_reportlms');

        return $rewritten;
    }

    /**
     * Remove every block we created.
     *
     * Found by their link to the report page rather than by a stored blob, so
     * "remove the block" also clears copies left by an earlier release or by an
     * uninstall. A block an admin wrote themselves that happens to link here is
     * removed too, which is what an admin asking for this would expect.
     *
     * @param bool $dismiss Remember the removal, so connect does not put it back.
     */
    public static function remove(bool $dismiss = true): void {
        global $CFG;

        require_once($CFG->dirroot . '/lib/blocklib.php');

        foreach (self::instances() as $instance) {
            blocks_delete_instance($instance);
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

        $blurb = \html_writer::div(
            branding::mark(36)
            . \html_writer::tag('p', get_string('block:intro', 'local_reportlms'), ['class' => 'local-reportlms-blurb']),
            'local-reportlms-lockup',
            ['style' => '--local-reportlms-mark-size: 36px;']
        );

        return \html_writer::div(
            $blurb . \html_writer::link(
                $url,
                get_string('block:button', 'local_reportlms'),
                ['class' => 'btn btn-primary']
            ),
            'local-reportlms-block'
        );
    }
}
