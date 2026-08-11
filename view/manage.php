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
 * The organisation, as somebody types it in.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_reportlms\local\access;
use local_reportlms\local\branding;
use local_reportlms\local\manager_resolver;
use local_reportlms\local\mapping;

require_login();

$context = context_system::instance();

// The authority to say who manages whom. It shows nobody's numbers: somebody
// who may state a fact about the organisation is not thereby entitled to read
// the consequences of it, which is what lets a training coordinator maintain
// the tree without being given sight of everybody's progress.
access::require_manage();

$pageurl = new moodle_url('/local/reportlms/view/manage.php');

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
/*
 * A standard page, not the settings layout.
 *
 * 'admin' drags in the site administration tab strip, General, Users, Learning,
 * Grades and the rest, across the top of a page that is none of those things.
 * This is where a training coordinator says who runs which department, and most
 * of the people who hold :manage cannot open a single one of those tabs. A row
 * of doors that refuse you is worse than no row at all.
 */
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('local-reportlms-branded');
$PAGE->set_title(get_string('manage:title', 'local_reportlms'));
$PAGE->set_heading('');

$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '' && confirm_sesskey()) {
    switch ($action) {
        case 'addnode':
            $name = trim(required_param('name', PARAM_TEXT));

            if ($name !== '') {
                mapping::save_node(
                    $name,
                    required_param('kind', PARAM_ALPHA),
                    optional_param('parentid', 0, PARAM_INT) ?: null
                );
            }
            break;

        case 'deletenode':
            mapping::delete_node(required_param('nodeid', PARAM_INT));
            break;

        case 'place':
            $userid = required_param('userid', PARAM_INT);
            $nodeid = optional_param('nodeid', 0, PARAM_INT);

            // Zero is the "back to auto-detect" option in the same control, so
            // placing and releasing are one action to the person using it.
            if ($nodeid > 0) {
                mapping::place($userid, $nodeid);
            } else {
                mapping::release($userid);
            }
            break;

        case 'addmanager':
            $roleid = optional_param('roleid', 0, PARAM_INT);

            mapping::add_manager(
                required_param('nodeid', PARAM_INT),
                $roleid > 0 ? null : (optional_param('manageruserid', 0, PARAM_INT) ?: null),
                $roleid ?: null
            );
            break;

        case 'movemanager':
            mapping::move_manager(
                required_param('id', PARAM_INT),
                required_param('nodeid', PARAM_INT)
            );
            break;

        case 'namehere':
            // Promoting somebody the capability already found into somebody the
            // register states outright. Same act as the form below, reached from
            // the row that made it obvious.
            mapping::add_manager(
                required_param('nodeid', PARAM_INT),
                required_param('userid', PARAM_INT),
                null
            );
            break;

        case 'importunits':
            $created = mapping::import_manager_departments();
            redirect(
                $pageurl,
                get_string('manage:imported', 'local_reportlms', $created),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'removemanager':
            mapping::remove_manager(required_param('id', PARAM_INT));
            break;
    }

    redirect($pageurl);
}

$nodes = mapping::nodes();
$membership = mapping::membership();

$roles = role_fix_names(get_all_roles(), $context, ROLENAME_ORIGINAL, true);

echo $OUTPUT->header();

echo html_writer::div(
    html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading']),
    'local-reportlms-header'
);

echo html_writer::tag('p', get_string('manage:intro', 'local_reportlms'), ['class' => 'text-muted']);

// ---------------------------------------------------------------- units ----

echo html_writer::tag('h3', get_string('manage:units', 'local_reportlms'));

if (!$nodes) {
    echo html_writer::tag('p', get_string('manage:nounits', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [
        get_string('manage:unit', 'local_reportlms'),
        get_string('manage:kind', 'local_reportlms'),
        get_string('manage:parent', 'local_reportlms'),
        get_string('manage:people', 'local_reportlms'),
        '',
    ];

    foreach ($nodes as $node) {
        $delete = new moodle_url($pageurl, [
            'action' => 'deletenode',
            'nodeid' => $node->id,
            'sesskey' => sesskey(),
        ]);

        $table->data[] = [
            s($node->name),
            get_string('manage:kind:' . $node->kind, 'local_reportlms'),
            $node->parentid && isset($nodes[$node->parentid]) ? s($nodes[$node->parentid]->name) : '-',
            count(mapping::members_of((int) $node->id)),
            html_writer::link($delete, get_string('delete'), ['class' => 'text-danger']),
        ];
    }

    echo html_writer::table($table);
}

$sectors = [0 => get_string('manage:nosector', 'local_reportlms')];

foreach ($nodes as $node) {
    if ($node->kind === 'sector') {
        $sectors[$node->id] = $node->name;
    }
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addnode']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'name',
    'required' => 'required',
    'placeholder' => get_string('manage:unitname', 'local_reportlms'),
    'class' => 'form-control d-inline-block w-auto me-2',
]);
echo html_writer::select(
    [
        'department' => get_string('manage:kind:department', 'local_reportlms'),
        'sector' => get_string('manage:kind:sector', 'local_reportlms'),
    ],
    'kind',
    'department',
    false,
    ['class' => 'form-select d-inline-block w-auto me-2']
);
echo html_writer::select($sectors, 'parentid', 0, false, ['class' => 'form-select d-inline-block w-auto me-2']);
echo html_writer::tag('button', get_string('add'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// ------------------------------------------------------------- managers ----

/*
 * The units, as a picker's options. Built once here because three things below
 * need them: the manager form, the person form, and the in-place unit editor on
 * a register row.
 */
$unitoptions = [];

foreach ($nodes as $node) {
    $unitoptions[$node->id] = $node->name;
}

echo html_writer::tag('h3', get_string('manage:managers', 'local_reportlms'));
echo html_writer::tag('p', get_string('manage:managers_help', 'local_reportlms'), ['class' => 'text-muted']);

/*
 * The register, and it has to be one.
 *
 * Being a manager is data here rather than a role assignment, so an
 * administrator asking "who can see staff training data" would otherwise have
 * two places to look. This lists both routes in one table: the people named
 * below, and the people who arrived by holding the capability.
 */
$table = new html_table();
$table->head = [
    get_string('manage:manager', 'local_reportlms'),
    get_string('manage:unit', 'local_reportlms'),
    get_string('manage:route', 'local_reportlms'),
    '',
];

/*
 * One row per person per unit, whichever way they got there.
 *
 * Somebody named here who also holds the capability used to appear twice, once
 * per route, which reads as two managers and invites somebody to remove "the
 * duplicate". They are one person with one job and two reasons, so the routes
 * belong in one cell.
 *
 * Roles stay on their own rows. A role names a set of people that changes on its
 * own, so it cannot be merged with any individual without saying something
 * untrue about who is in it.
 */
$nodesbyname = [];

foreach ($nodes as $node) {
    $nodesbyname[core_text::strtolower(trim($node->name))] = $node;
}

$register = [];
$editid = optional_param('edit', 0, PARAM_INT);

foreach (mapping::managers() as $row) {
    if ($row->roleid) {
        $register['role:' . $row->id] = (object) [
            'who' => get_string('manage:everyoneholding', 'local_reportlms', $roles[$row->roleid] ?? $row->roleid),
            'unit' => isset($nodes[$row->nodeid]) ? $nodes[$row->nodeid]->name : '-',
            'nodeid' => (int) $row->nodeid,
            'userid' => 0,
            'mappingid' => (int) $row->id,
            'capability' => false,
        ];

        continue;
    }

    $user = core_user::get_user($row->userid);

    $register['u:' . $row->userid . ':' . $row->nodeid] = (object) [
        'who' => $user ? fullname($user) : get_string('manage:goneuser', 'local_reportlms'),
        'unit' => isset($nodes[$row->nodeid]) ? $nodes[$row->nodeid]->name : '-',
        'nodeid' => (int) $row->nodeid,
        'userid' => (int) $row->userid,
        'mappingid' => (int) $row->id,
        'capability' => false,
    ];
}

foreach (manager_resolver::managers() as $manager) {
    /*
     * Administrators are not managers, they are administrators.
     *
     * They hold every capability everywhere, so the detector finds them running
     * whatever they can see, which is everything. Listing that says nothing
     * about the organisation and buries the people the register is actually for.
     *
     * Filtered here rather than in the resolver: that list also decides who sees
     * whose numbers, and this screen is not the place to change that.
     */
    if (is_siteadmin($manager->userid)) {
        continue;
    }

    $user = core_user::get_user($manager->userid);
    $who = $user ? fullname($user) : (string) $manager->userid;
    $departments = array_filter(array_map('trim', $manager->departments), 'strlen');

    if (!$departments) {
        $key = 'u:' . $manager->userid . ':none';
        $register[$key] = $register[$key] ?? (object) [
            'who' => $who,
            'unit' => '-',
            'nodeid' => 0,
            'userid' => (int) $manager->userid,
            'mappingid' => 0,
            'capability' => false,
        ];
        $register[$key]->capability = true;

        continue;
    }

    foreach ($departments as $department) {
        $node = $nodesbyname[core_text::strtolower($department)] ?? null;
        $key = 'u:' . $manager->userid . ':' . ($node ? $node->id : 'name:' . core_text::strtolower($department));

        $register[$key] = $register[$key] ?? (object) [
            'who' => $who,
            'unit' => $node ? $node->name : $department,
            'nodeid' => $node ? (int) $node->id : 0,
            'userid' => (int) $manager->userid,
            'mappingid' => 0,
            'capability' => false,
        ];
        $register[$key]->capability = true;
    }
}

foreach ($register as $entry) {
    $routes = [];

    if ($entry->mappingid) {
        $routes[] = get_string('manage:route:named', 'local_reportlms');
    }

    if ($entry->capability) {
        $routes[] = get_string('manage:route:capability', 'local_reportlms');
    }

    $actions = [];

    if ($entry->mappingid) {
        $actions[] = html_writer::link(
            new moodle_url($pageurl, ['edit' => $entry->mappingid]),
            get_string('edit')
        );
        /*
         * Remove takes away the naming and nothing else.
         *
         * A capability is a role assignment in Moodle, and this editor does not
         * edit roles: being a manager is data here precisely so that it can be
         * stated and withdrawn without touching them. So a person who also holds
         * the capability stays in this table afterwards, with one route left,
         * and the button says which one it is taking so that looks deliberate
         * rather than broken.
         */
        $actions[] = html_writer::link(
            new moodle_url($pageurl, [
                'action' => 'removemanager',
                'id' => $entry->mappingid,
                'sesskey' => sesskey(),
            ]),
            $entry->capability
                ? get_string('manage:removenaming', 'local_reportlms')
                : get_string('remove'),
            ['class' => 'text-danger']
        );
    } else if ($entry->userid && $entry->nodeid) {
        // Detected, and the unit it points at exists, so it can be written down.
        $actions[] = html_writer::link(
            new moodle_url($pageurl, [
                'action' => 'namehere',
                'userid' => $entry->userid,
                'nodeid' => $entry->nodeid,
                'sesskey' => sesskey(),
            ]),
            get_string('manage:namehere', 'local_reportlms')
        );
    }

    if ($editid && $editid === $entry->mappingid && $unitoptions) {
        // The unit cell becomes the editor, in place. A correction is a small
        // act and does not deserve a second screen to get lost on.
        $cell = html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out_omit_querystring(), 'class' => 'd-flex gap-2'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'movemanager'])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $entry->mappingid])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::select($unitoptions, 'nodeid', $entry->nodeid, false, ['class' => 'custom-select'])
            . html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-primary',
                'value' => get_string('savechanges'),
            ])
            . html_writer::end_tag('form');
    } else {
        $cell = s($entry->unit);
    }

    $table->data[] = [
        $entry->who,
        $cell,
        implode(', ', $routes),
        implode(' &nbsp; ', $actions),
    ];
}

if (!$table->data) {
    echo html_writer::tag('p', get_string('manage:nomanagers', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    echo html_writer::table($table);
}

/*
 * The offer to turn detected departments into units, made once.
 *
 * Auto-detect already reads these off profile fields, so nothing here is new
 * information. What it changes is that a department becomes a row somebody can
 * rename, re-parent and name a manager against, instead of a value that only
 * exists inside a profile field.
 *
 * There is no flag remembering whether it was taken. The offer is shown when
 * there is something left to import and hidden when there is not, so accepting
 * it removes the reason to show it and a site that never wants it is never asked
 * a second time about the same department.
 */
if (mapping::has_departments_to_import()) {
    $importurl = new moodle_url($pageurl, ['action' => 'importunits', 'sesskey' => sesskey()]);

    echo html_writer::div(
        html_writer::tag('p', get_string('manage:importoffer', 'local_reportlms'))
        . html_writer::link($importurl, get_string('manage:importaction', 'local_reportlms'), [
            'class' => 'btn btn-primary',
        ]),
        'alert alert-info'
    );
}

if ($nodes) {
    $roleoptions = [0 => get_string('manage:norole', 'local_reportlms')] + $roles;

    (new \local_reportlms\form\name_manager($pageurl->out(false), [
        'units' => $unitoptions,
        'roles' => $roleoptions,
    ]))->display();
} else {
    /*
     * Say why there is no form here.
     *
     * There used to be nothing: a heading, a line saying nobody manages
     * anything, and then blank page where the controls belong. A control that
     * is absent for a good reason looks exactly like one that is broken, and
     * the reader has no way to tell which. This is the same fault the report
     * page had when it showed zeros with no explanation.
     */
    echo html_writer::tag(
        'p',
        get_string('manage:needunitformanager', 'local_reportlms'),
        ['class' => 'alert alert-info']
    );
}

// -------------------------------------------------------------- people ----

echo html_writer::tag('h3', get_string('manage:placed', 'local_reportlms'));
echo html_writer::tag('p', get_string('manage:placed_help', 'local_reportlms'), ['class' => 'text-muted']);

if (!$membership) {
    echo html_writer::tag('p', get_string('manage:noplaced', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [
        get_string('manage:person', 'local_reportlms'),
        get_string('manage:unit', 'local_reportlms'),
        '',
    ];

    foreach ($membership as $userid => $nodeid) {
        $user = core_user::get_user($userid);
        $release = new moodle_url($pageurl, [
            'action' => 'place',
            'userid' => $userid,
            'nodeid' => 0,
            'sesskey' => sesskey(),
        ]);

        $table->data[] = [
            $user ? fullname($user) : $userid,
            isset($nodes[$nodeid]) ? s($nodes[$nodeid]->name) : '-',
            html_writer::link($release, get_string('manage:release', 'local_reportlms')),
        ];
    }

    echo html_writer::table($table);
}

if ($nodes) {
    (new \local_reportlms\form\place_person($pageurl->out(false), ['units' => $unitoptions]))->display();
} else {
    // Same reasoning as the manager form above: an absent control that explains
    // itself is a step, and one that does not is a bug report.
    echo html_writer::tag(
        'p',
        get_string('manage:needunitforperson', 'local_reportlms'),
        ['class' => 'alert alert-info']
    );
}

echo $OUTPUT->footer();
