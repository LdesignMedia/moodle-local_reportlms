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
 * Generate learning data on a test instance, so the dashboard has something to
 * report on.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');

/*
 * Hosts this may run against.
 *
 * A allowlist rather than a "are you sure?" prompt, because this writes
 * enrolments and completions for thousands of people and there is no undo. A
 * prompt protects against hesitation; a list protects against the wrong
 * terminal.
 */
const ALLOWED_HOSTS = [
    'workplace.test.example.com',
    'moodle.test.example.com',
    'reportlms-dev.test.example.com',
    'localhost',
];

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'learners' => 2000,
    'courses' => 8,
    'dry-run' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<EOT
Generate learning data for Report LMS testing.

Only touches users who already carry a mapped organisation value, so the org
tree stays the site's real one and only the training data is invented.

Options:
  --learners=N   How many learners to enrol.       Default 2000
  --courses=N    How many courses to spread over.  Default 8
  --dry-run      Report what would be written and stop.
  -h, --help     This text.
EOT);
    exit(0);
}

$host = parse_url($CFG->wwwroot, PHP_URL_HOST);

if (!in_array($host, ALLOWED_HOSTS, true)) {
    cli_error("Refusing to run against {$host}. Add it to ALLOWED_HOSTS in this file if that is really what you want.");
}

$dryrun = (bool) $options['dry-run'];
$wantlearners = max(1, (int) $options['learners']);
$wantcourses = max(1, (int) $options['courses']);

// Only people the org tree already places. Inventing profile values as well
// would mean testing a shape no customer has.
$map = \local_reportlms\local\collector\org::fieldmap();
$field = $DB->get_record('user_info_field', ['shortname' => $map['department']]);

if (!$field) {
    cli_error("No '{$map['department']}' profile field on this site. Map orgfield_department first.");
}

$sql = "SELECT u.id
          FROM {user} u
          JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid
         WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
           AND " . $DB->sql_compare_text('d.data', 255) . " <> :empty
      ORDER BY u.id";

$learnerids = $DB->get_fieldset_sql($sql, ['fieldid' => $field->id, 'empty' => '']);
$learnerids = array_slice($learnerids, 0, $wantlearners);

if (!$learnerids) {
    cli_error('No users carry a department value, so there is nobody to enrol.');
}

$courses = $DB->get_records_select('course', 'id > 1 AND visible = 1', null, 'id', 'id, fullname', 0, $wantcourses);

if (!$courses) {
    cli_error('No visible courses to enrol into.');
}

cli_writeln('Site:     ' . $host);
cli_writeln('Learners: ' . count($learnerids));
cli_writeln('Courses:  ' . count($courses));

if ($dryrun) {
    cli_writeln('Dry run, nothing written.');
    exit(0);
}

$manual = enrol_get_plugin('manual');

if (!$manual) {
    cli_error('The manual enrolment plugin is disabled, so nothing can be enrolled.');
}

$studentrole = $DB->get_record('role', ['shortname' => 'student']);
$now = time();
$enrolled = $completed = $graded = 0;

foreach ($courses as $course) {
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);

    if (!$instance) {
        $instanceid = $manual->add_default_instance(get_course($course->id));
        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
    }

    // Course completion has to be switched on, or course_completions never
    // gets a row and the completion collector reports nothing.
    $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);

    // A different slice per course, so the departments do not all come out with
    // identical numbers and a filter that does nothing still looks right.
    $offset = ($course->id * 37) % max(1, count($learnerids));
    $slice = array_slice($learnerids, $offset, (int) (count($learnerids) * 0.6));

    if (!$slice) {
        $slice = $learnerids;
    }

    foreach ($slice as $i => $learnerid) {
        $manual->enrol_user($instance, $learnerid, $studentrole->id ?? null, $now - (86400 * 60));
        $enrolled++;

        // Roughly two in three finish, and the rest stay in progress, so the
        // completion split is not a straight line.
        if ($i % 3 === 0) {
            continue;
        }

        $existing = $DB->get_record('course_completions', ['userid' => $learnerid, 'course' => $course->id]);

        if (!$existing) {
            $DB->insert_record('course_completions', (object) [
                'userid' => $learnerid,
                'course' => $course->id,
                'timeenrolled' => $now - (86400 * 60),
                'timestarted' => $now - (86400 * 45),
                'timecompleted' => $now - (86400 * (int) (($i % 30) + 1)),
                'reaggregate' => 0,
            ]);
            $completed++;
        }
    }

    // One grade item per course, so the score distribution card has something.
    $item = $DB->get_record('grade_items', ['courseid' => $course->id, 'itemtype' => 'course']);

    if ($item) {
        foreach (array_slice($slice, 0, 400) as $i => $learnerid) {
            if ($DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $learnerid])) {
                continue;
            }

            // Spread across the four bands the dashboard buckets into, so none
            // of them reads zero.
            $percent = 45 + (($i * 7) % 55);

            $DB->insert_record('grade_grades', (object) [
                'itemid' => $item->id,
                'userid' => $learnerid,
                'rawgrademax' => 100,
                'rawgrademin' => 0,
                'finalgrade' => $percent,
                'rawgrade' => $percent,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $graded++;
        }
    }

    cli_writeln('  ' . $course->fullname . ': ' . count($slice) . ' enrolled');
}

cli_writeln('');
cli_writeln("Enrolments written: {$enrolled}");
cli_writeln("Completions written: {$completed}");
cli_writeln("Grades written: {$graded}");

// Everything the push sends is keyed on the learner registry, so the next run
// has to know these people changed.
\local_reportlms\local\userstate::mark_all_dirty();
unset_config('lastorghash', 'local_reportlms');
unset_config('lastdimhash', 'local_reportlms');

cli_writeln('Marked every learner dirty; the next push sends a full backfill.');
