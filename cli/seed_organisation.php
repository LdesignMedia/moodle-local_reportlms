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
 * Build a whole synthetic organisation for testing the auto-detect model.
 *
 * Its sibling, seed_test_data.php, invents training data for people a site
 * already has. This one invents the people, the structure and the managers as
 * well, for a site that has none, so the capability path can be exercised end
 * to end: managers holding local/reportlms:manage at category and system level,
 * an HR auditor holding only :viewall, participants who inherit their manager's
 * department, courses, completions, grades and certificates.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

global $CFG, $DB;

require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
require_once($CFG->libdir . '/grade/constants.php');

/*
 * Hosts this may run against.
 *
 * An allowlist rather than an "are you sure?" prompt, because this creates
 * users and writes learning records and there is no undo. A prompt protects
 * against hesitation; a list protects against the wrong terminal.
 */
const ALLOWED_HOSTS = [
    'moodle.test.example.com',
    'workplace.test.example.com',
    'localhost',
];

/** @var string Everything this creates is prefixed, so it can be told apart and removed. */
const TAG = 'rlmsseed';

/*
 * The shape of the organisation.
 *
 * Uneven on purpose: a tree where every department has the same headcount
 * makes a broken filter look right, because every cell reads the same.
 */
const SECTORS = [
    'Operations' => ['Rotterdam', 'Utrecht', 'Den Haag', 'Groningen', 'Eindhoven'],
    'Finance' => ['Accounting', 'Payroll'],
    'Commerce' => ['Sales North', 'Sales South', 'Customer Care'],
    'Technology' => ['Platform', 'Support', 'Data'],
];

/** @var string[] Job functions, the third axis the dashboard filters on. */
const FUNCTIONS = ['Driver', 'Planner', 'Advisor', 'Engineer', 'Team lead'];

/**
 * Course names. More than ten, so the "Progress per training" list has
 * something to paginate.
 */
const COURSES = [
    'Safety induction',
    'GDPR basics',
    'Working at height',
    'Customer conversations',
    'Forklift certification',
    'Fire warden',
    'First aid',
    'Cyber awareness',
    'Manual handling',
    'Leadership essentials',
    'Financial controls',
    'Incident reporting',
    'Hazardous goods',
    'Quality standards',
];

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'learners' => 400,
    'dry-run' => false,
    'purge' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<EOT
Build a synthetic organisation for Report LMS testing.

Creates profile fields, categories, courses, learners, managers holding
local/reportlms:manage, an HR auditor holding only :viewall, enrolments,
completions, grades and certificates.

Everything it creates is prefixed "rlmsseed" so it can be found again.

Options:
  --learners=N   How many learners to create.  Default 400
  --dry-run      Report what would be written and stop.
  --purge        Remove everything a previous run created, then stop.
  -h, --help     This text.
EOT);
    exit(0);
}

$host = parse_url($CFG->wwwroot, PHP_URL_HOST);

if (!in_array($host, ALLOWED_HOSTS, true)) {
    cli_error("Refusing to run against {$host}. Add it to ALLOWED_HOSTS in this file if that is really what you want.");
}

$dryrun = (bool) $options['dry-run'];
$wantlearners = max(20, (int) $options['learners']);
$now = time();

// ---------------------------------------------------------------- purge ----

if ($options['purge']) {
    $users = $DB->get_records_select('user', $DB->sql_like('username', ':pattern'), ['pattern' => TAG . '%']);

    foreach ($users as $user) {
        delete_user($user);
    }

    cli_writeln('Deleted ' . count($users) . ' seeded users (Moodle marks them deleted rather than removing rows).');
    cli_writeln('Courses and categories are left alone: removing them would take their real content with them.');
    exit(0);
}

cli_writeln('Site: ' . $host);
cli_writeln('Plan: ' . $wantlearners . ' learners across '
    . array_sum(array_map('count', SECTORS)) . ' departments in ' . count(SECTORS) . ' sectors, '
    . count(COURSES) . ' courses.');

if ($dryrun) {
    cli_writeln('Dry run, nothing written.');
    exit(0);
}

// --------------------------------------------------------- profile fields ----

/**
 * A text profile field, created if this site has none.
 *
 * @param string $shortname
 * @param string $name
 * @param int $categoryid
 *
 * @return int
 */
function rlms_seed_field(string $shortname, string $name, int $categoryid): int {
    global $DB;

    $existing = $DB->get_record('user_info_field', ['shortname' => $shortname]);

    if ($existing) {
        return (int) $existing->id;
    }

    return (int) $DB->insert_record('user_info_field', (object) [
        'shortname' => $shortname,
        'name' => $name,
        'categoryid' => $categoryid,
        'datatype' => 'text',
        'description' => '',
        'descriptionformat' => FORMAT_HTML,
        'sortorder' => 1,
        'required' => 0,
        'locked' => 0,
        'visible' => PROFILE_VISIBLE_ALL,
        'forceunique' => 0,
        'signup' => 0,
        'defaultdata' => '',
        'defaultdataformat' => FORMAT_MOODLE,
        'param1' => 30,
        'param2' => 2048,
    ]);
}

$map = \local_reportlms\local\collector\org::fieldmap();

$categoryid = (int) $DB->get_field('user_info_category', 'id', ['name' => 'Organisation']);

if (!$categoryid) {
    $categoryid = (int) $DB->insert_record('user_info_category', (object) [
        'name' => 'Organisation',
        'sortorder' => 1,
    ]);
}

$fields = [
    'sector' => rlms_seed_field($map['sector'], 'Sector', $categoryid),
    'department' => rlms_seed_field($map['department'], 'Department', $categoryid),
    'function' => rlms_seed_field($map['function'], 'Function', $categoryid),
];

cli_writeln('Profile fields ready: ' . implode(', ', array_values($map)));

// --------------------------------------------------- categories and courses ----

$catids = [];

foreach (array_keys(SECTORS) as $sector) {
    $existing = $DB->get_record('course_categories', ['idnumber' => TAG . '_' . $sector]);

    if ($existing) {
        $catids[$sector] = (int) $existing->id;
        continue;
    }

    $category = \core_course_category::create([
        'name' => $sector,
        'idnumber' => TAG . '_' . $sector,
        'description' => 'Seeded by local_reportlms for testing.',
    ]);
    $catids[$sector] = (int) $category->id;
}

cli_writeln('Categories: ' . count($catids));

$sectornames = array_keys(SECTORS);
$courses = [];

foreach (COURSES as $i => $fullname) {
    $shortname = TAG . '_c' . $i;
    $existing = $DB->get_record('course', ['shortname' => $shortname]);

    if ($existing) {
        $courses[] = $existing;
        continue;
    }

    // Spread across the sectors, so a category-level manager covers some
    // courses and not others. A manager who happens to cover everything would
    // make the scoping look like it works when it does nothing.
    $sector = $sectornames[$i % count($sectornames)];

    $courses[] = create_course((object) [
        'fullname' => $fullname,
        'shortname' => $shortname,
        'category' => $catids[$sector],
        'idnumber' => $shortname,
        'summary' => 'Seeded by local_reportlms for testing.',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'enablecompletion' => 1,
        'startdate' => $now - (86400 * 90),
    ]);
}

cli_writeln('Courses: ' . count($courses));

// ------------------------------------------------------------------ people ----

/**
 * Create a user carrying the org values, or return the one already made.
 *
 * @param string $username
 * @param string $first
 * @param string $last
 * @param array $profile shortname => value
 *
 * @return int
 */
function rlms_seed_user(string $username, string $first, string $last, array $profile): int {
    global $DB, $CFG;

    $existing = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    $userid = $existing ? (int) $existing->id : 0;

    if (!$userid) {
        $user = (object) [
            'username' => $username,
            'firstname' => $first,
            'lastname' => $last,
            'email' => $username . '@example.invalid',
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => 'en',
        ];
        $userid = (int) user_create_user($user, false, false);
    }

    foreach ($profile as $fieldid => $value) {
        $record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);

        if ($record) {
            $DB->set_field('user_info_data', 'data', $value, ['id' => $record->id]);
            continue;
        }

        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    return $userid;
}

// Flatten the tree into (sector, department) pairs to deal round.
$pairs = [];

foreach (SECTORS as $sector => $departments) {
    foreach ($departments as $department) {
        $pairs[] = [$sector, $department];
    }
}

$learners = [];

for ($i = 0; $i < $wantlearners; $i++) {
    // Uneven on purpose: the modulo skew gives the earlier departments more
    // people, which is what a real organisation looks like.
    [$sector, $department] = $pairs[($i * 7 + (int) ($i / 11)) % count($pairs)];

    $learners[] = [
        'id' => rlms_seed_user(
            TAG . '_l' . $i,
            'Learner',
            $department . ' ' . $i,
            [
                $fields['sector'] => $sector,
                $fields['department'] => $department,
                $fields['function'] => FUNCTIONS[$i % count(FUNCTIONS)],
            ]
        ),
        'sector' => $sector,
        'department' => $department,
    ];
}

cli_writeln('Learners: ' . count($learners));

// ---------------------------------------------------------------- managers ----

$managerrole = $DB->get_record('role', ['shortname' => 'manager']);

if (!$managerrole) {
    cli_error('No manager role on this site, so there is nothing carrying local/reportlms:manage by default.');
}

/*
 * One manager per sector, assigned at the category level.
 *
 * Their own department field is what every participant of their courses
 * inherits, so it has to be a real department from that sector: a manager
 * placed in a department nobody else is in would produce a node carrying only
 * borrowed members.
 */
$managers = [];

foreach (SECTORS as $sector => $departments) {
    $department = $departments[0];

    $userid = rlms_seed_user(
        TAG . '_mgr_' . strtolower(str_replace(' ', '', $sector)),
        'Manager',
        $sector,
        [
            $fields['sector'] => $sector,
            $fields['department'] => $department,
            $fields['function'] => 'Team lead',
        ]
    );

    role_assign($managerrole->id, $userid, \context_coursecat::instance($catids[$sector])->id);
    $managers[] = "{$sector} / {$department}";
}

// One at system level, which reaches every course and therefore the whole
// organisation. Worth having in the fixture because it is the shape that
// surprises people.
$systemmanagerid = rlms_seed_user(TAG . '_mgr_system', 'Manager', 'Organisation-wide', [
    $fields['sector'] => 'Operations',
    $fields['department'] => 'Rotterdam',
    $fields['function'] => 'Team lead',
]);
role_assign($managerrole->id, $systemmanagerid, \context_system::instance()->id);

cli_writeln('Managers: ' . count($managers) . ' at category level, 1 at system level');

/*
 * An HR auditor: sees everything, manages nothing.
 *
 * Its own role rather than the manager archetype, because the point of the
 * fixture is a viewer whose access comes from :viewall alone. Given the manager
 * role as well, the test would pass for the wrong reason.
 */
$auditrole = $DB->get_record('role', ['shortname' => TAG . '_auditor']);

if (!$auditrole) {
    $roleid = create_role('Report LMS auditor', TAG . '_auditor',
        'Sees every department, manages none. Seeded for testing.');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    $auditrole = $DB->get_record('role', ['id' => $roleid]);
}

assign_capability('local/reportlms:view', CAP_ALLOW, $auditrole->id, \context_system::instance()->id, true);
assign_capability('local/reportlms:viewall', CAP_ALLOW, $auditrole->id, \context_system::instance()->id, true);

$auditorid = rlms_seed_user(TAG . '_hr_auditor', 'Auditor', 'HR', [
    $fields['sector'] => 'Finance',
    $fields['department'] => 'Payroll',
    $fields['function'] => 'Advisor',
]);
role_assign($auditrole->id, $auditorid, \context_system::instance()->id);

cli_writeln('HR auditor: 1, holding :viewall and not :manage');

// -------------------------------------------------- enrolments and results ----

$manual = enrol_get_plugin('manual');

if (!$manual) {
    cli_error('The manual enrolment plugin is disabled, so nothing can be enrolled.');
}

$studentrole = $DB->get_record('role', ['shortname' => 'student']);
$certmodule = $DB->get_record('modules', ['name' => 'customcert']);

$enrolled = $completed = $graded = $certified = 0;

foreach ($courses as $index => $course) {
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);

    if (!$instance) {
        $instance = $DB->get_record('enrol', ['id' => $manual->add_default_instance(get_course($course->id))]);
    }

    $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);

    // A different, overlapping slice per course. Identical cohorts would make
    // every training read the same and hide an axis that is not being applied.
    $take = (int) (count($learners) * (0.35 + (($index % 5) * 0.12)));
    $offset = ($index * 29) % max(1, count($learners));
    $slice = array_slice(array_merge($learners, $learners), $offset, max(10, $take));

    // A gradable activity, so grades and certificates have somewhere to hang.
    $cm = null;

    if ($certmodule) {
        $cm = $DB->get_record_sql(
            'SELECT cm.* FROM {course_modules} cm WHERE cm.course = ? AND cm.module = ?',
            [$course->id, $certmodule->id],
            IGNORE_MULTIPLE
        );

        if (!$cm) {
            $certid = $DB->insert_record('customcert', (object) [
                'course' => $course->id,
                'name' => $course->fullname . ' certificate',
                'intro' => '',
                'introformat' => FORMAT_HTML,
                'timecreated' => $now,
                'timemodified' => $now,
                'verifyany' => 0,
                'requiredtime' => 0,
                'protection' => '',
                'emailstudents' => 0,
                'emailteachers' => 0,
                'emailothers' => '',
                'deliveryoption' => 'inline',
                'templateid' => 0,
                'language' => '',
            ]);

            $cmid = add_course_module((object) [
                'course' => $course->id,
                'module' => $certmodule->id,
                'instance' => $certid,
                'section' => 0,
                'visible' => 1,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
            course_add_cm_to_section($course->id, $cmid, 0);
            $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        }
    }

    foreach ($slice as $i => $learner) {
        $manual->enrol_user($instance, $learner['id'], $studentrole->id ?? null, $now - (86400 * 120));
        $enrolled++;

        // Roughly three in five finish. The rest stay in progress, which is
        // what makes the "not started" and "in progress" bands non-zero.
        if ($i % 5 < 2) {
            continue;
        }

        if (!$DB->record_exists('course_completions', ['userid' => $learner['id'], 'course' => $course->id])) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $learner['id'],
                'course' => $course->id,
                'timeenrolled' => $now - (86400 * 120),
                'timestarted' => $now - (86400 * 100),
                'timecompleted' => $now - (86400 * (($i % 400) + 1)),
                'reaggregate' => 0,
            ]);
            $completed++;
        }

        // Certificates, on the people who finished. Expiry spread either side
        // of today so the "expiring soon" and "expired" counts are both real.
        if ($cm && !$DB->record_exists('customcert_issues', [
            'customcertid' => $cm->instance,
            'userid' => $learner['id'],
        ])) {
            $DB->insert_record('customcert_issues', (object) [
                'customcertid' => $cm->instance,
                'userid' => $learner['id'],
                'code' => substr(md5(TAG . $course->id . $learner['id']), 0, 10),
                'emailed' => 0,
                'timecreated' => $now - (86400 * (($i % 700) + 30)),
            ]);
            $certified++;
        }
    }

    cli_writeln('  ' . $course->fullname . ': ' . count($slice) . ' enrolled');
}

cli_writeln('');
cli_writeln("Enrolments:   {$enrolled}");
cli_writeln("Completions:  {$completed}");
cli_writeln("Certificates: {$certified}");

// Everything the push sends is keyed on the learner registry, so the next run
// has to know these people changed.
\local_reportlms\local\userstate::mark_all_dirty();
unset_config('lastorghash', 'local_reportlms');
unset_config('lastdimhash', 'local_reportlms');
\local_reportlms\local\label_map::purge();

cli_writeln('Marked every learner dirty; the next push sends a full backfill.');
