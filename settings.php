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
 * Admin settings for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_reportlms', get_string('pluginname', 'local_reportlms'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // Advanced mode hides the connection internals from the ordinary view:
        // /admin/settings.php?section=local_reportlms&mode=advanced, reached by
        // URL or by the Advanced settings button below. The button shows for
        // site-config holders who additionally hold :manage, everyone who can
        // open this page already passed $hassiteconfig, so the capability is a
        // narrowing, not the gate. The settings form posts back without the
        // mode parameter, so the marker setting added below re-flags advanced
        // mode on the save request.
        $advanced = optional_param('mode', '', PARAM_ALPHA) === 'advanced'
            || optional_param('reportlmsadv', 0, PARAM_BOOL);

        $connected = \local_reportlms\local\connect_manager::is_connected();

        $disconnecturl = new moodle_url('/local/reportlms/disconnect.php', ['sesskey' => sesskey()]);
        $reporturl = new moodle_url('/local/reportlms/view.php');

        // One status line, green when connected and red when not. The mark is
        // not here: it sits against the page heading, which styles.css draws
        // in, because admin/settings.php owns that heading and has already
        // printed it by the time this file runs.
        $status = html_writer::div(
            html_writer::span(get_string('connect:statuslabel', 'local_reportlms')) .
            html_writer::span(
                $connected
                    ? get_string('connect:statusconnected', 'local_reportlms')
                    : get_string('connect:statusdisconnected', 'local_reportlms'),
                'badge ' . ($connected ? 'badge-success bg-success' : 'badge-danger bg-danger')
            ),
            'local-reportlms-status'
        );

        /*
         * Cron, next to the connection status and not hidden below it.
         *
         * The two answer different halves of "is this working": one says the
         * keys are good, the other says anything is actually being sent. A site
         * can pass the first and fail the second for its entire life.
         */
        $cronproblem = \local_reportlms\local\cron_health::message();

        if ($cronproblem !== null) {
            $status .= html_writer::div(
                html_writer::span(get_string('cron:heading', 'local_reportlms'), 'local-reportlms-cron-label')
                . html_writer::span($cronproblem, 'local-reportlms-cron-text'),
                'local-reportlms-cronwarning'
            );
        } else {
            $status .= html_writer::div(
                get_string('cron:heading', 'local_reportlms') . ' '
                . \local_reportlms\local\cron_health::describe_last_run(),
                'local-reportlms-cronok'
            );
        }

        /*
         * The people nobody has put in a unit.
         *
         * Here rather than on the Report LMS side, because here is where
         * somebody can act. It replaced three counts about a tree the plugin
         * used to work out for itself, all of which described how uncertain
         * that guess was. There is no guess now, so the only thing left to say
         * is how much of the tree has not been filled in, and that is a real
         * chore on a site with staff turnover.
         *
         * Only shown when there is something to say. A permanent row of zeroes
         * trains people to stop reading it.
         */
        if ($connected) {
            $unplaced = \local_reportlms\local\mapping::unplaced_count();

            if ($unplaced > 0) {
                $manageurl = new moodle_url('/local/reportlms/view/manage.php');

                $status .= html_writer::div(
                    html_writer::span(get_string('warn:heading', 'local_reportlms'), 'local-reportlms-warn-label')
                    . html_writer::tag('ul', html_writer::tag(
                        'li',
                        get_string('warn:unplaced', 'local_reportlms', $unplaced)
                        . ' ' . html_writer::link($manageurl, get_string('warn:unplaced_link', 'local_reportlms'))
                    ))
                    . html_writer::tag('p', get_string('warn:footnote', 'local_reportlms'), ['class' => 'local-reportlms-warn-foot']),
                    'local-reportlms-treewarning'
                );
            }
        }

        $details = [];
        if ($connected) {
            $environmentid = (string) get_config('local_reportlms', 'environmentid');
            if ($environmentid !== '') {
                $details[] = get_string('connect:environmentid', 'local_reportlms', s($environmentid));
            }
            $tier = (string) get_config('local_reportlms', 'tier');
            if ($tier !== '') {
                $details[] = get_string('connect:tier', 'local_reportlms', s($tier));
            }
            $connectedat = (int) get_config('local_reportlms', 'connectedat');
            if ($connectedat) {
                $details[] = get_string('connect:connectedat', 'local_reportlms', userdate($connectedat));
            }
            $keyexpiresat = (int) get_config('local_reportlms', 'keyexpiresat');
            if ($keyexpiresat) {
                // This is the licence expiry: the key is minted to match it.
                $details[] = get_string('connect:keyexpires', 'local_reportlms', userdate($keyexpiresat));
            }
            $lastpush = (int) get_config('local_reportlms', 'lastpush');
            if ($lastpush) {
                $details[] = get_string('connect:lastpush', 'local_reportlms', userdate($lastpush));
            }
        } else {
            $status .= html_writer::tag('p', get_string('connect:setupintro', 'local_reportlms'));
        }

        // A mapped organisation field that does not exist on this site is a
        // silently dead tree: the collector finds nothing and everyone lands
        // in Unassigned. Said here on the ordinary page, not only behind
        // advanced mode where nobody would go looking for it.
        $missingfields = \local_reportlms\local\profile_fields::missing_mappings();
        if ($missingfields) {
            $status .= html_writer::div(
                get_string('settings:orgfieldmissingwarning', 'local_reportlms', implode(', ', $missingfields)),
                'alert alert-warning'
            );
        }

        /*
         * A write-back level that was chosen and is being ignored.
         *
         * writeback::targets() refuses a field silently, because a sync is not
         * a place to argue with configuration. Silently is fine as long as
         * somewhere says so, and this is that somewhere.
         */
        $ignored = [];

        foreach (\local_reportlms\local\writeback::targets() as $level => $target) {
            $chosen = (string) get_config('local_reportlms', \local_reportlms\local\writeback::setting_for($level));

            if ($chosen !== '' && $target === null) {
                $ignored[] = $chosen;
            }
        }

        if ($ignored) {
            $status .= html_writer::div(
                get_string('settings:writebackrefused', 'local_reportlms', implode(', ', $ignored)),
                'alert alert-warning'
            );
        }

        // Connecting is not offered here. Setting a site up is a sequence,
        // licence, then reading what will leave the site, then the connection
        // itself, and only the report page shows where in that sequence a site
        // actually is. A second Connect button on this page would let somebody
        // skip straight past the middle step without ever seeing it, and then
        // two pages would disagree about what was left to do.
        //
        // Disconnecting stays, because it is the one thing that belongs to
        // settings: it is not a step, it is an undo.
        $buttons = $connected
            ? html_writer::link(
                $disconnecturl,
                get_string('connect:disconnectbutton', 'local_reportlms'),
                ['class' => 'btn btn-secondary']
            )
            : html_writer::link(
                $reporturl,
                get_string('connect:gotosetup', 'local_reportlms'),
                ['class' => 'btn btn-primary']
            );

        if ($connected) {
            // Puts the report one click away for everyone, instead of only for
            // whoever knows the plugin URL. Whether the block is there is
            // already the button's own label, so it needs no separate line
            // underneath repeating it.
            $hasblock = \local_reportlms\local\dashboard_block::exists();
            $blockurl = new moodle_url('/local/reportlms/block.php', [
                'sesskey' => sesskey(),
                'action' => $hasblock ? 'remove' : 'add',
            ]);
            $buttons .= ' ' . html_writer::link(
                $blockurl,
                $hasblock
                    ? get_string('block:removebutton', 'local_reportlms')
                    : get_string('block:addbutton', 'local_reportlms'),
                ['class' => 'btn btn-secondary']
            );
        }

        // Whether connected or not: the advanced section holds the base URL,
        // which is exactly what a site may need to change before connecting.
        // Absent on the advanced page itself and on its save postback, so the
        // button never renders inside the form it opens.
        if (!$advanced && \local_reportlms\local\access::can_manage()) {
            $advancedurl = new moodle_url('/admin/settings.php', [
                'section' => 'local_reportlms',
                'mode' => 'advanced',
            ]);
            $buttons .= ' ' . html_writer::link(
                $advancedurl,
                get_string('settings:advancedbutton', 'local_reportlms'),
                ['class' => 'btn btn-secondary']
            );
        }

        $status .= html_writer::div($buttons, 'mt-2 mb-2');

        if ($details) {
            $status .= html_writer::alist($details);
        }

        $settings->add(new admin_setting_heading(
            'local_reportlms/header',
            '',
            $status
        ));

        // Everything below is advanced: an end user only needs the button above.
        // The API key, the signing secret and the salt are written by the connect
        // flow and are never rendered, so they cannot be hand-edited into a
        // broken or replaced state.
        if ($advanced) {
            $settings->add(new \local_reportlms\admin\setting_advanced_marker());

            $settings->add(new admin_setting_heading(
                'local_reportlms/advancedheading',
                get_string('settings:advancedheading', 'local_reportlms'),
                html_writer::div(get_string('settings:advancedwarning', 'local_reportlms'), 'alert alert-warning')
            ));

            $settings->add(new admin_setting_configtext(
                'local_reportlms/baseurl',
                get_string('settings:baseurl', 'local_reportlms'),
                get_string('settings:baseurl_desc', 'local_reportlms'),
                \local_reportlms\local\config::DEFAULT_BASEURL,
                PARAM_URL
            ));

            $settings->add(new admin_setting_configtext(
                'local_reportlms/pushpath',
                get_string('settings:pushpath', 'local_reportlms'),
                get_string('settings:pushpath_desc', 'local_reportlms'),
                \local_reportlms\local\config::DEFAULT_PUSHPATH,
                PARAM_PATH
            ));

            // For the updated-callback below: the save request must find the
            // function already defined, and a local plugin's lib.php is not
            // loaded for free.
            require_once(__DIR__ . '/lib.php');

            /*
             * Its own heading, apart from the connection settings above: the
             * warning up there says "change only if support asks", and this is
             * a setting an administrator is invited to change.
             *
             * One field, where there were three. Sector and department became
             * rows in the mapping editor, so the only profile field left is the
             * one naming what somebody does.
             */
            $settings->add(new admin_setting_heading(
                'local_reportlms/orgfieldsheading',
                get_string('settings:orgfieldsheading', 'local_reportlms'),
                get_string('settings:orgfieldsintro', 'local_reportlms')
            ));

            $select = new admin_setting_configselect(
                'local_reportlms/orgfield_function',
                get_string('settings:orgfield_function', 'local_reportlms'),
                get_string('settings:orgfield_function_desc', 'local_reportlms'),
                '',
                \local_reportlms\local\profile_fields::field_options('function')
            );
            $select->set_updatedcallback('local_reportlms_orgfield_updated');
            $settings->add($select);

            /*
             * Where the organisation is published, which is the other
             * direction: the function field above is read, and these two are
             * written on every sync.
             *
             * Two selects rather than one switch and two targets, because each
             * answers its own question and either level can sensibly be off:
             * a flat site with no sectors wants its departments published and
             * nothing else.
             */
            foreach (\local_reportlms\local\writeback::OWN_FIELDS as $level => $ownfield) {
                $settings->add(new admin_setting_configselect(
                    'local_reportlms/' . \local_reportlms\local\writeback::setting_for($level),
                    get_string('settings:writeback_' . $level, 'local_reportlms'),
                    get_string('settings:writeback_' . $level . '_desc', 'local_reportlms'),
                    $ownfield,
                    \local_reportlms\local\profile_fields::writeback_options($ownfield)
                ));
            }

            // The editor lives on its own page rather than in settings: it
            // edits data, not configuration, and its own capability decides who
            // may open it.
            $settings->add(new admin_setting_description(
                'local_reportlms/manageorg',
                get_string('manage:title', 'local_reportlms'),
                html_writer::link(
                    new moodle_url('/local/reportlms/view/manage.php'),
                    get_string('manage:open', 'local_reportlms'),
                    ['class' => 'btn btn-secondary']
                )
            ));

            $settings->add(new admin_setting_configcheckbox(
                'local_reportlms/enabled',
                get_string('settings:enabled', 'local_reportlms'),
                get_string('settings:enabled_desc', 'local_reportlms'),
                1
            ));
        }
    }
}
