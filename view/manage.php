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
use local_reportlms\local\capability_holders;
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

/*
 * Which unit's people are open, carried across an action.
 *
 * Releasing somebody is done from inside that list, and a redirect that dropped
 * this would close the panel after every single release. Somebody correcting
 * four placements should not have to click the number four times.
 */
$openunit = optional_param('people', 0, PARAM_INT);
$returnurl = $openunit ? new moodle_url($pageurl, ['people' => $openunit]) : $pageurl;

if ($action !== '' && confirm_sesskey()) {
    switch ($action) {
        case 'addnode':
            $name = trim(required_param('name', PARAM_TEXT));
            $kind = required_param('kind', PARAM_ALPHA);
            $parentid = optional_param('parentid', 0, PARAM_INT) ?: null;

            if ($name !== '') {
                /*
                 * Checked here so the person gets a sentence, rather than
                 * letting save_node() throw. The exception behind it stays,
                 * because this is not the only caller and a duplicate name
                 * produces two units with one key.
                 */
                if (mapping::name_taken($name, $kind === 'sector' ? 'sector' : 'department', $parentid)) {
                    redirect(
                        $returnurl,
                        get_string('manage:nametaken', 'local_reportlms', s($name)),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }

                mapping::save_node($name, $kind, $parentid);
            }
            break;

        case 'deletenode':
            mapping::delete_node(required_param('nodeid', PARAM_INT));
            break;

        case 'place':
            $nodeid = optional_param('nodeid', 0, PARAM_INT);
            $chosen = optional_param_array('userid', [], PARAM_INT);
            $sources = [
                'courses' => optional_param_array('courseids', [], PARAM_INT),
                'categories' => optional_param_array('categoryids', [], PARAM_INT),
                'cohorts' => optional_param_array('cohortids', [], PARAM_INT),
                'groups' => optional_param_array('groupids', [], PARAM_INT),
                'profilevalues' => optional_param_array('profilevalues', [], PARAM_TEXT),
            ];

            /*
             * The Remove link beside a name, which is one person out of a unit.
             *
             * Kept separate from the form below because on a unit of four
             * hundred, deselecting one name from an autocomplete is not how
             * anybody wants to correct a single mistake.
             */
            $single = optional_param('release', 0, PARAM_INT);

            if ($single > 0) {
                mapping::release($single);
                redirect($returnurl);
            }

            if ($nodeid === 0) {
                break;
            }

            /*
             * Two different statements, saved as two different things.
             *
             * The sources are a sentence about the unit: "everybody enrolled on
             * the induction course". They are stored as they were said and
             * resolved every time somebody asks, so a person enrolled tomorrow
             * is in the unit tomorrow.
             *
             * The Person box is a list of names, and it stays a list of names.
             * It used to open holding everybody the unit contained, including
             * the hundreds a course had drawn, which made the two
             * indistinguishable: removing a name that a source had produced put
             * it straight back on the next read, and the box could not be used
             * to say anything the sources did not already say.
             */
            mapping::save_sources($nodeid, $sources);

            /*
             * Compared against every row this unit holds, not only the typed
             * ones.
             *
             * A row the migration left behind is in the box, so it has to be in
             * the comparison too: measured against typed names alone, every one
             * of them looked like a name the person had just added, and saving
             * an untouched form would have rewritten them all.
             *
             * A row that survives is left exactly as it is, keeping whatever
             * rank it had. Only what somebody adds becomes a typed name.
             */
            $wanted = array_flip(array_filter($chosen));
            $current = array_flip(mapping::placed_members_of($nodeid));

            $added = array_diff_key($wanted, $current);
            $removed = array_diff_key($current, $wanted);

            foreach (array_keys($added) as $id) {
                mapping::place((int) $id, $nodeid);
            }

            foreach (array_keys($removed) as $id) {
                mapping::release((int) $id);
            }

            mapping::reset();

            redirect(
                $returnurl,
                get_string('manage:saved', 'local_reportlms', (object) [
                    'people' => count(mapping::members_of($nodeid)),
                    'sources' => count(mapping::sources_of($nodeid)),
                ]),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );

            break;

        case 'removemanager':
            mapping::remove_manager(required_param('id', PARAM_INT));
            break;
    }

    redirect($returnurl);
}

$nodes = mapping::nodes();

$roles = role_fix_names(get_all_roles(), $context, ROLENAME_ORIGINAL, true);

echo $OUTPUT->header();

echo html_writer::div(
    html_writer::tag('h1', branding::lockup(40), ['class' => 'local-reportlms-heading']),
    'local-reportlms-header'
);

/*
 * A person's name, linking to their profile.
 *
 * Every name in this editor answers a question somebody is about to ask about
 * that person: which department are they really in, is this the Paul I meant,
 * did they ever log in. Printing the name and making them go and search for it
 * is the sort of small friction that turns a two-minute correction into a
 * five-minute one.
 *
 * Falls back to plain text for somebody the site no longer has, since a link to
 * a deleted profile is a dead end dressed as an answer.
 *
 * A closure rather than a function: this is a page script, and a named function
 * declared here would sit in the global namespace for the rest of the request.
 */
$personlink = static function ($user, string $fallback): string {
    if (!$user) {
        return $fallback;
    }

    return html_writer::link(
        new moodle_url('/user/profile.php', ['id' => $user->id]),
        fullname($user)
    );
};

/*
 * One unit's people, as a page rather than a panel.
 *
 * It was rendered underneath the units table, which put a second table and a
 * second heading on a page that already carries three sections. A list somebody
 * asked for by clicking a number deserves the screen, and the way back is a
 * button rather than scrolling past everything they did not ask about.
 *
 * Short-circuits here so nothing below is built: no forms, no register, no
 * course list to enumerate.
 */
if ($openunit && isset($nodes[$openunit])) {
    /*
     * Who manages what, as a lookup, built once for this page.
     *
     * Used twice: to say who runs the unit being looked at, and to mark anybody
     * in its list who runs a unit of their own. The second matters because a
     * manager of one unit is an ordinary learner in another, so finding the head
     * of Sales among Flexpool A's people is correct and looks like a mistake
     * unless the page says what they are.
     */
    $managerof = [];

    foreach (mapping::managers() as $row) {
        if ($row->userid && isset($nodes[$row->nodeid])) {
            $managerof[(int) $row->userid][] = format_string($nodes[$row->nodeid]->name);
        }
    }

    echo html_writer::tag('h3', get_string(
        'manage:peoplein',
        'local_reportlms',
        format_string($nodes[$openunit]->name)
    ));

    // Who runs this one, named rather than left to the register three sections
    // down, since it is the first thing somebody looking at a unit asks.
    $runners = [];

    foreach (mapping::managers() as $row) {
        if ((int) $row->nodeid === $openunit && $row->userid) {
            $runners[] = $personlink(core_user::get_user($row->userid), (string) $row->userid);
        }
    }

    echo html_writer::tag(
        'p',
        $runners
            ? get_string('manage:managedby', 'local_reportlms', implode(', ', $runners))
            : get_string('manage:managedbynobody', 'local_reportlms'),
        ['class' => 'text-muted']
    );

    echo html_writer::tag(
        'p',
        get_string('manage:placed_help', 'local_reportlms'),
        ['class' => 'text-muted']
    );

    $inunit = mapping::members_of($openunit);

    if (!$inunit) {
        echo html_writer::tag('p', get_string('manage:nobodyhere', 'local_reportlms'), ['class' => 'text-muted']);
    } else {
        $peopletable = new html_table();
        $peopletable->head = [
            get_string('manage:person', 'local_reportlms'),
            get_string('manage:why', 'local_reportlms'),
            '',
        ];

        // Three ways to be here, and the row has to say which: a name somebody
        // typed, a placement the migration left behind, or a source drawing
        // them. Only the first two are rows, so only they can be removed.
        $named = array_flip(mapping::named_members_of($openunit));
        $adopted = mapping::adopted_membership();

        foreach ($inunit as $userid) {
            $release = new moodle_url($pageurl, [
                'action' => 'place',
                'release' => $userid,
                'people' => $openunit,
                'sesskey' => sesskey(),
            ]);

            $name = $personlink(core_user::get_user($userid), (string) $userid);

            // A manager standing in somebody else's unit is not a mistake, and
            // saying which unit they run is what stops it looking like one.
            if (!empty($managerof[$userid])) {
                $name .= ' ' . html_writer::span(
                    get_string('manage:managerof', 'local_reportlms', implode(', ', $managerof[$userid])),
                    'badge badge-info bg-info'
                );
            }

            /*
             * Why they are here, and whether it can be undone from this row.
             *
             * Somebody a course drew cannot be removed one at a time: the next
             * read would put them straight back, because the unit still says
             * "everybody on that course". The honest answer is to say where
             * they came from and point at the source that would change it.
             */
            $isnamed = isset($named[$userid]);
            $isadopted = !$isnamed && (int) ($adopted[$userid] ?? 0) === $openunit;
            $bysource = !$isnamed && !$isadopted;

            if ($isnamed) {
                $why = get_string('manage:why:named', 'local_reportlms');
            } else if ($isadopted) {
                $why = html_writer::span(get_string('manage:why:adopted', 'local_reportlms'), 'text-muted');
            } else {
                $why = html_writer::span(get_string('manage:why:source', 'local_reportlms'), 'text-muted');
            }

            $peopletable->data[] = [
                $name,
                $why,
                $bysource
                    ? ''
                    : html_writer::link($release, get_string('manage:release', 'local_reportlms')),
            ];
        }

        echo html_writer::table($peopletable);
    }

    /*
     * Adding people happens here, on the unit, rather than in a section at the
     * bottom of the page with a unit dropdown on it.
     *
     * That dropdown was the whole problem: choosing people and choosing which
     * unit they go into were two answers to two questions on one form, and the
     * page they belonged to was three sections away from the unit itself. Here
     * the unit is already known, so the form asks one thing.
     */
    /*
     * The course list for the picker, built once here.
     *
     * get_courses() rather than a hand-rolled query: it applies the visibility
     * rules and returns the site course, which is dropped because "everyone on
     * the site" is not a department.
     */
    $courseoptions = [];

    /*
     * The headcount beside each course, because choosing one is a decision
     * about how many people move.
     *
     * A course on a real site drew 1,200 people into a department in one press,
     * and the picker had said nothing about that beforehand. The number is the
     * warning.
     */
    $participantcounts = mapping::participant_counts();

    foreach (get_courses('all', 'c.shortname ASC', 'c.id, c.shortname, c.fullname') as $course) {
        if ((int) $course->id === SITEID) {
            continue;
        }

        $courseoptions[$course->id] = format_string($course->fullname)
            . ' (' . ($participantcounts[(int) $course->id] ?? 0) . ')';
    }

    /*
     * The other two ways to name a set of people.
     *
     * Categories come from Moodle's own list, which is already indented to show
     * the tree. Cohorts are offered only when the site has any.
     *
     * Groups are deliberately absent. They are the obvious third way and one
     * connected site has 32,024 of them, so a preloaded picker is not an
     * option; that one needs a search endpoint of its own.
     */
    $categoryoptions = \core_course_category::make_categories_list();

    $cohortoptions = [];

    foreach ($DB->get_records('cohort', ['visible' => 1], 'name ASC', 'id, name') as $cohort) {
        $cohortoptions[$cohort->id] = format_string($cohort->name);
    }

    /*
     * Groups, when there are few enough to list.
     *
     * Measured: 2 on one connected site and 32,024 on another. The second is why
     * this is guarded rather than simply built: an autocomplete holding 32,024
     * options is megabytes of markup and a browser that stops responding.
     *
     * Above the limit the form says so and points at courses, which is honest
     * and actionable. Replacing this with a search endpoint is the proper fix
     * and wants an AMD module.
     */
    $groupcount = $DB->count_records('groups');
    $groupoptions = [];
    $toomanygroups = 0;

    if ($groupcount > 0 && $groupcount <= 500) {
        $sql = 'SELECT g.id, g.name, c.fullname
                  FROM {groups} g
                  JOIN {course} c ON c.id = g.courseid
              ORDER BY c.fullname ASC, g.name ASC';

        foreach ($DB->get_records_sql($sql) as $group) {
            // The course name is half the answer: two courses commonly hold a
            // group of the same name, and a picker of bare group names would
            // make somebody guess which.
            $groupoptions[$group->id] = format_string($group->fullname) . ': ' . format_string($group->name);
        }
    } else if ($groupcount > 500) {
        $toomanygroups = $groupcount;
    }

    /*
     * The values in use across every text profile field on the site.
     *
     * Any field, not one nominated in the settings. There used to be a
     * site-wide "department field" because the tree was derived from it and
     * there could only be one answer. A unit draws from whatever describes it,
     * and on a real site that is sometimes the department field and sometimes
     * the office, the contract type or the region.
     *
     * Each option is `fieldid:value`, because the same value in two fields is
     * two different sets of people.
     *
     * Empty on a site whose profile fields say nothing about its organisation,
     * which is exactly the site this picker would be no use to anyway.
     */
    $profilevalues = [];
    $toomanyvalues = false;

    // Same order of magnitude as the group cap above, and for the same reason:
    // past it the markup is the problem, not the query.
    $maxprofilevalues = 500;

    $datacolumn = $DB->sql_compare_text('d.data', 255);

    // Free text and menu-of-choices. The other datatypes are checkboxes, dates
    // and textareas, none of which names a set of people.
    [$typesql, $typeparams] = $DB->get_in_or_equal(['text', 'menu'], SQL_PARAMS_NAMED, 'type');

    /*
     * Never a field the organisation is published into.
     *
     * Drawing a unit from a field write-back writes is a loop: Sales publishes
     * "Sales", Support draws everybody whose value is "Sales", the person moves
     * to Support, Support publishes "Support", and they move back. The
     * function field stays offered, because it is read and never written and
     * "everybody whose function is Driver" is a sentence somebody may mean.
     */
    $published = array_values(array_filter(\local_reportlms\local\writeback::targets()));
    $excludesql = '';

    if ($published) {
        [$excludein, $excludeparams] = $DB->get_in_or_equal($published, SQL_PARAMS_NAMED, 'pub', false);
        $excludesql = " AND f.shortname {$excludein}";
        $typeparams += $excludeparams;
    }

    $sql = "SELECT DISTINCT f.id AS fieldid, f.name AS fieldname, {$datacolumn} AS value
              FROM {user_info_data} d
              JOIN {user_info_field} f ON f.id = d.fieldid
             WHERE f.datatype {$typesql}{$excludesql} AND {$datacolumn} <> :empty
          ORDER BY f.name ASC, value ASC";

    $recordset = $DB->get_recordset_sql($sql, $typeparams + ['empty' => '']);

    foreach ($recordset as $row) {
        if (count($profilevalues) >= $maxprofilevalues) {
            // A select with ten thousand options is not a picker. Said out
            // loud below rather than silently truncated, because a list that
            // quietly stops is how somebody concludes a value does not exist.
            $toomanyvalues = true;

            break;
        }

        $profilevalues[$row->fieldid . ':' . $row->value] =
            format_string($row->fieldname) . ': ' . format_string($row->value);
    }

    $recordset->close();

    /*
     * What this unit already draws from, so the pickers open holding it.
     *
     * Without this the sources were write-only: chosen once, applied, and then
     * invisible, so the only way to see what a unit drew from was to notice
     * people in it nobody had named.
     */
    $selectedsources = [
        'courseids' => [],
        'categoryids' => [],
        'cohortids' => [],
        'groupids' => [],
        'profilevalues' => [],
    ];

    foreach (mapping::sources_of($openunit) as $source) {
        switch ($source->itemtype) {
            case 'course':
                $selectedsources['courseids'][] = (int) $source->itemid;
                break;
            case 'category':
                $selectedsources['categoryids'][] = (int) $source->itemid;
                break;
            case 'cohort':
                $selectedsources['cohortids'][] = (int) $source->itemid;
                break;
            case 'group':
                $selectedsources['groupids'][] = (int) $source->itemid;
                break;
            case 'profilefield':
                $selectedsources['profilevalues'][] = $source->itemid . ':' . $source->itemvalue;
                break;
        }
    }

    (new \local_reportlms\form\place_person($pageurl->out(false), [
        'nodeid' => $openunit,
        'members' => mapping::placed_members_of($openunit),
        'courses' => $courseoptions,
        'categories' => $categoryoptions,
        'cohorts' => $cohortoptions,
        'groups' => $groupoptions,
        'toomanygroups' => $toomanygroups,
        'profilevalues' => $profilevalues,
        'toomanyvalues' => $toomanyvalues,
        'selected' => $selectedsources,
    ]))->display();

    echo html_writer::link(
        $pageurl,
        get_string('manage:backtounits', 'local_reportlms'),
        ['class' => 'btn btn-secondary']
    );

    echo $OUTPUT->footer();

    exit;
}

echo html_writer::tag('p', get_string('manage:intro', 'local_reportlms'), ['class' => 'text-muted']);


// ---------------------------------------------------------------- units ----

echo html_writer::tag('h3', get_string('manage:units', 'local_reportlms'));

if (!$nodes) {
    echo html_writer::tag('p', get_string('manage:nounits', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    /*
     * A page at a time, because a real tree is not a short list.
     *
     * Measured on a connected site: 284 units, each row counting its own
     * members, which is 284 membership lookups and a table nobody scrolls to
     * the end of. Fifty is about a screen and a half.
     *
     * Only the rows are paged. Everything below this table, the sector picker
     * and the manager register, still reads the whole tree: a unit you cannot
     * see on page one is still a unit you may put somebody in.
     */
    $unitsperpage = 50;
    $unitpage = optional_param('page', 0, PARAM_INT);
    $totalunits = count($nodes);

    if ($unitpage * $unitsperpage >= $totalunits) {
        // A stale page number, after deleting the last unit on the last page.
        // Landing on an empty table reads as the tree having vanished.
        $unitpage = 0;
    }

    $shownnodes = array_slice($nodes, $unitpage * $unitsperpage, $unitsperpage, true);

    // Every unit's headcount in one pass, rather than walking the whole site's
    // membership once per row.
    $membercounts = mapping::member_counts();

    $table = new html_table();
    $table->head = [
        get_string('manage:unit', 'local_reportlms'),
        get_string('manage:kind', 'local_reportlms'),
        get_string('manage:parent', 'local_reportlms'),
        get_string('manage:people', 'local_reportlms'),
        '',
    ];

    foreach ($shownnodes as $node) {
        $delete = new moodle_url($pageurl, [
            'action' => 'deletenode',
            'nodeid' => $node->id,
            'sesskey' => sesskey(),
            'page' => $unitpage,
        ]);

        /*
         * The count is the way in to the people behind it.
         *
         * There used to be a second table further down listing every
         * placed person on the site, which answered "who is placed" and
         * not "who is in this unit", and grew a unit column to compensate. The
         * number was already on this row; it just was not clickable.
         */
        $placed = $membercounts[(int) $node->id] ?? 0;
        $peopleurl = new moodle_url($pageurl, ['people' => $node->id]);

        /*
         * An icon on every row, not only on the rows that already have people.
         *
         * The count was the only way in, so a unit with nobody in it offered no
         * way to put anybody in it: the one row that most needed the link was
         * the one row that did not have it.
         */
        $peoplelink = html_writer::link(
            $peopleurl,
            $OUTPUT->pix_icon('i/users', get_string('manage:peoplein', 'local_reportlms', $node->name)),
            [
                'class' => 'btn local-reportlms-btn local-reportlms-btn-icon',
                'title' => get_string('manage:peoplein', 'local_reportlms', $node->name),
            ]
        );

        $table->data[] = [
            s($node->name),
            get_string('manage:kind:' . $node->kind, 'local_reportlms'),
            $node->parentid && isset($nodes[$node->parentid]) ? s($nodes[$node->parentid]->name) : '-',
            $placed ? html_writer::link($peopleurl, $placed) : $placed,
            $peoplelink . ' &nbsp; ' . html_writer::link($delete, get_string('delete'), ['class' => 'text-danger']),
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalunits, $unitpage, $unitsperpage, $pageurl);

    /*
     * Names that appear twice, said once above the table.
     *
     * Legal and confusing, which is exactly the combination worth a sentence:
     * `HR` under Transport and `HR` under Office are two different groups of
     * people, and two identical-looking rows. The Parent column tells them
     * apart once somebody knows to look at it, and this is what makes them
     * know.
     */
    $repeated = mapping::repeated_names();

    if ($repeated) {
        echo html_writer::div(
            get_string('manage:repeatednames', 'local_reportlms', s(implode(', ', $repeated))),
            'text-muted small mt-2'
        );
    }
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
 * duplicate". One person, one row, and one route: being named is a statement
 * somebody made here, and it is the answer whenever it exists.
 *
 * Roles stay on their own rows. A role names a set of people that changes on its
 * own, so it cannot be merged with any individual without saying something
 * untrue about who is in it.
 */

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
        'who' => $personlink($user, get_string('manage:goneuser', 'local_reportlms')),
        'unit' => isset($nodes[$row->nodeid]) ? $nodes[$row->nodeid]->name : '-',
        'nodeid' => (int) $row->nodeid,
        'userid' => (int) $row->userid,
        'mappingid' => (int) $row->id,
        'capability' => false,
    ];
}

/*
 * Everybody who can reach a report without anybody having named them.
 *
 * They belong on this register because it answers "who can see staff training
 * data", and a role grant is one of the ways somebody can. They get no unit:
 * holding local/reportlms:view says nothing about which department is theirs,
 * and the page used to guess one from their profile field and show it as
 * though it were a fact. The Fix in their row is how that becomes one.
 */
foreach (capability_holders::holders() as $userid) {
    $user = core_user::get_user($userid);
    $key = 'u:' . $userid . ':none';

    $register[$key] = $register[$key] ?? (object) [
        'who' => $personlink($user, (string) $userid),
        'unit' => '-',
        'nodeid' => 0,
        'userid' => (int) $userid,
        'mappingid' => 0,
        'capability' => false,
    ];
    $register[$key]->capability = true;
}

/*
 * How many people are named here and also reach this through a role.
 *
 * Counted rather than shown per row. Two routes in one cell described a state
 * to be resolved as though it were a fact about somebody, and invited whoever
 * read it to remove "the duplicate".
 */
$alsogranted = 0;

foreach ($register as $entry) {
    /*
     * One route per row, and being named is the one that wins.
     *
     * A naming is a statement somebody made on this page. A capability is how
     * they happened to get in before anybody stated it. Where both are true the
     * naming is the answer, and the grant underneath is a tidy-up rather than a
     * second kind of manager.
     */
    if ($entry->mappingid) {
        $route = get_string('manage:route:named', 'local_reportlms');

        if ($entry->capability) {
            $alsogranted++;
        }
    } else {
        $route = get_string('manage:route:capability', 'local_reportlms');
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
            get_string('manage:fix', 'local_reportlms'),
            ['title' => get_string('manage:fix_help', 'local_reportlms')]
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
        $route,
        implode(' &nbsp; ', $actions),
    ];
}

if (!$table->data) {
    echo html_writer::tag('p', get_string('manage:nomanagers', 'local_reportlms'), ['class' => 'text-muted']);
} else {
    echo html_writer::table($table);
}

/*
 * The overlap, said once rather than per row.
 *
 * These people are named here and would also get in through a role, so the
 * naming is not yet the only thing holding their access up and removing it
 * would not remove them. Nothing on this page can fix that: a capability lives
 * in a role definition, and taking it off one person would mean stripping a
 * role that carries hundreds of other things. So this points at the place that
 * can, rather than offering a button that would quietly do the wrong amount.
 */
if ($alsogranted) {
    $roleurl = new moodle_url('/admin/roles/manage.php');

    echo html_writer::div(
        get_string('manage:alsogranted', 'local_reportlms', $alsogranted)
        . ' ' . html_writer::link($roleurl, get_string('manage:alsogranted_link', 'local_reportlms')),
        'text-muted small mt-2'
    );
}

if ($nodes) {
    (new \local_reportlms\form\name_manager($pageurl->out(false), [
        'units' => $unitoptions,
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

echo $OUTPUT->footer();
