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
 * Puts a link to the report on the site front page.
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
 * It goes on the site front page, not the Dashboard. The Dashboard looks like
 * the obvious home for it, but Moodle copies the default Dashboard to each user
 * the first time they open it, so a block added to the default only ever
 * reaches people who have never logged in. On a live site that is almost
 * nobody: the admin who just pressed the button would not see it either. The
 * front page block is a single shared instance that appears immediately.
 *
 * Deliberately an explicit action rather than something the connect flow does
 * on its own. Adding a block changes what every visitor sees, which is not a
 * side effect anyone should get from pressing Connect, and a reconnect would
 * then quietly add a second copy.
 */
class dashboard_block {
    /** @var string Plugin config key holding the id of the block we created. */
    protected const CONFIG_KEY = 'dashboardblockid';

    /**
     * Whether our block is currently on the front page.
     *
     * @return bool
     */
    public static function exists(): bool {
        global $DB;

        $id = (int) get_config('local_reportlms', self::CONFIG_KEY);

        return $id > 0 && $DB->record_exists('block_instances', ['id' => $id]);
    }

    /**
     * Add the block to the site front page.
     *
     * @return void
     * @throws \moodle_exception When the html block is unavailable.
     */
    public static function add(): void {
        global $DB;

        if (self::exists()) {
            return;
        }

        if (!\core_component::get_plugin_directory('block', 'html')) {
            throw new \moodle_exception('error:nohtmlblock', 'local_reportlms');
        }

        $config = (object) [
            'title' => get_string('block:title', 'local_reportlms'),
            'text' => self::content(),
            'format' => FORMAT_HTML,
        ];

        $id = $DB->insert_record('block_instances', (object) [
            'blockname' => 'html',
            'parentcontextid' => \context_course::instance(SITEID)->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'site-index',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => -5,
            'configdata' => base64_encode(serialize($config)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        set_config(self::CONFIG_KEY, $id, 'local_reportlms');
    }

    /**
     * Remove the block we created.
     *
     * Only ever removes the instance recorded in our own config, so an admin
     * who later edited or replaced it by hand does not lose their work.
     */
    public static function remove(): void {
        global $DB;

        $id = (int) get_config('local_reportlms', self::CONFIG_KEY);

        if ($id > 0 && $DB->record_exists('block_instances', ['id' => $id])) {
            blocks_delete_instance($DB->get_record('block_instances', ['id' => $id]));
        }

        unset_config(self::CONFIG_KEY, 'local_reportlms');
    }

    /**
     * The block body: one sentence and a button.
     *
     * The link goes to the plugin page rather than straight to the SaaS,
     * because the token has to be minted per view and only that page knows who
     * is looking.
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
