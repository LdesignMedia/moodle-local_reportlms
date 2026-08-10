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
$PAGE->set_pagelayout('admin');
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
                $roleid > 0 ? null : (optional_param('userid', 0, PARAM_INT) ?: null),
                $roleid ?: null
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

foreach (mapping::managers() as $row) {
    if ($row->roleid) {
        $who = get_string('manage:everyoneholding', 'local_reportlms', $roles[$row->roleid] ?? $row->roleid);
    } else {
        $user = core_user::get_user($row->userid);
        $who = $user ? fullname($user) : get_string('manage:goneuser', 'local_reportlms');
    }

    $remove = new moodle_url($pageurl, [
        'action' => 'removemanager',
        'id' => $row->id,
        'sesskey' => sesskey(),
    ]);

    $table->data[] = [
        $who,
        isset($nodes[$row->nodeid]) ? s($nodes[$row->nodeid]->name) : '-',
        get_string('manage:route:named', 'local_reportlms'),
        html_writer::link($remove, get_string('remove'), ['class' => 'text-danger']),
    ];
}

foreach (manager_resolver::managers() as $manager) {
    $user = core_user::get_user($manager->userid);

    $table->data[] = [
        $user ? fullname($user) : $manager->userid,
        $manager->departments ? s(reset($manager->departments)) : '-',
        get_string('manage:route:capability', 'local_reportlms'),
        '',
    ];
}

if (!$table->data) {
    echo html_writer::tag('p', get_string('manage:nomanagers', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    echo html_writer::table($table);
}

if ($nodes) {
    $unitoptions = [];

    foreach ($nodes as $node) {
        $unitoptions[$node->id] = $node->name;
    }

    $roleoptions = [0 => get_string('manage:norole', 'local_reportlms')] + $roles;

    (new \local_reportlms\form\name_manager($pageurl->out(false), [
        'units' => $unitoptions,
        'roles' => $roleoptions,
    ]))->display();
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
    $unitoptions = [];

    foreach ($nodes as $node) {
        $unitoptions[$node->id] = $node->name;
    }

    (new \local_reportlms\form\place_person($pageurl->out(false), ['units' => $unitoptions]))->display();
}

echo $OUTPUT->footer();
