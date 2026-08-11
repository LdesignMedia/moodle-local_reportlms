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
 * English strings for local_reportlms.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Report LMS';
$string['privacy:metadata:userstate'] = 'Which learners have changes not yet accepted by Report LMS, so an unchanged person is not sent again.';
$string['privacy:metadata:userstate:userid'] = 'The learner this row tracks.';
$string['privacy:metadata:userstate:timechanged'] = 'When a change was last noticed.';
$string['privacy:metadata:userstate:timepushed'] = 'When Report LMS last confirmed this learner.';
$string['privacy:metadata:member'] = 'Which part of the organisation somebody was placed in by hand.';
$string['privacy:metadata:member:userid'] = 'The person placed.';
$string['privacy:metadata:member:nodeid'] = 'The unit they were placed in.';
$string['privacy:metadata:member:source'] = 'Whether a person typed this in or it was worked out automatically.';
$string['privacy:metadata:manager'] = 'Who was named as the manager of a part of the organisation.';
$string['privacy:metadata:manager:userid'] = 'The person named.';
$string['privacy:metadata:manager:nodeid'] = 'The unit they manage.';
$string['privacy:metadata:reportlms'] = 'Training results are sent to Report LMS. No name, e-mail address or free text is ever sent: people, courses, activities and organisation units are replaced with coded identifiers before they leave this site, using a key that stays here. Names are shown on the dashboard by this site answering the browser directly, never by Report LMS holding them.';
$string['privacy:metadata:reportlms:learner_pid'] = 'A coded identifier standing for one learner.';
$string['privacy:metadata:reportlms:course_ref'] = 'A coded identifier standing for one course.';
$string['privacy:metadata:reportlms:status'] = 'Whether a course was completed, is in progress, or has not been started.';
$string['privacy:metadata:reportlms:grade'] = 'The grade on the main test of the course, as a percentage.';
$string['privacy:metadata:reportlms:attempts'] = 'How many attempts were taken at that test.';
$string['privacy:metadata:reportlms:node_id'] = 'A coded identifier standing for a sector or department.';

// Connect flow.
$string['connect:button'] = 'Connect to Report LMS';
$string['connect:disconnectbutton'] = 'Disconnect';
$string['connect:statuslabel'] = 'Status:';
$string['connect:statusconnected'] = 'Connected';
$string['connect:statusdisconnected'] = 'Not connected';
// What the organisation tree is not sure about. Shown on this site because
// this is where the fix is: a profile field or a role assignment, both of them
// here. The same counts on the Report LMS side would be counts nobody could act
// on, which is why they are not sent there.
// Naming a node after its manager when they have no department of their own.
$string['settings:managerfallback'] = 'Name a group after its manager when no department is set';
$string['settings:managerfallback_desc'] = 'A manager with the Report LMS manage permission but no department on their own profile normally places nobody, and their people fall back to their own department or to Unassigned. Turn this on to put them in a group named after that manager instead, so they stay visible. A group named after a person is not a department, so leave this off unless that reads as the truth on your site.';
$string['label:managerteam'] = '{$a} (no department set)';

// The mapping editor, where somebody types the organisation in by hand.
$string['manage:open'] = 'Open the organisation editor';
$string['manage:title'] = 'Report LMS: organisation';
$string['manage:intro'] = 'The company tree is worked out from profile fields and course participation. Where that gets it wrong, or where your profile fields do not describe your organisation at all, type it in here. Anything set here wins over what is worked out automatically.';
$string['manage:units'] = 'Units';
$string['manage:unit'] = 'Unit';
$string['manage:unitname'] = 'Name';
$string['manage:kind'] = 'Level';
$string['manage:kind:sector'] = 'Sector';
$string['manage:kind:department'] = 'Department';
$string['manage:parent'] = 'Sector above it';
$string['manage:nosector'] = 'No sector above it';
$string['manage:people'] = 'People';
$string['manage:nounits'] = 'No units have been typed in. The tree is entirely worked out from profile fields.';
$string['manage:managers'] = 'Managers';
$string['manage:managers_help'] = 'Naming somebody here gives them the report for that unit. No role assignment and no capability to grant. Naming a role instead means everybody holding it manages the unit, which saves editing this list every time somebody joins or leaves.';
$string['manage:manager'] = 'Manager';
$string['manage:route'] = 'How';
$string['manage:route:named'] = 'Named here';
$string['manage:route:capability'] = 'Holds the capability';
$string['manage:everyoneholding'] = 'Everyone holding {$a}';
$string['manage:goneuser'] = 'A user who no longer exists';
$string['manage:nomanagers'] = 'Nobody manages anything yet, so nobody but an administrator sees a report.';
$string['manage:norole'] = 'One person, not a role';
$string['manage:orrole'] = 'Or everyone holding this role';
$string['manage:placed'] = 'People placed by hand';
$string['manage:placed_help'] = 'These people stay where you put them. Their profile field is ignored, so a later HR upload cannot move them. Release somebody to hand them back to the automatic tree, which may not put them back where they were.';
$string['manage:person'] = 'Person';
$string['manage:release'] = 'Back to automatic';
$string['manage:noplaced'] = 'Nobody has been placed by hand.';

$string['warn:heading'] = 'Worth checking in your organisation structure:';
$string['warn:unplaceable'] = '{$a} managers hold the Report LMS manage permission but have no department set on their own profile, so they place nobody.';
$string['warn:contested'] = '{$a} people are managed by two managers in different departments. Each of them is counted under one of the two, and which one is not meaningful.';
$string['warn:overridden'] = '{$a} people are counted under their manager\'s department rather than the one on their own profile. That is how the report is designed to work, but a large number here usually means a manager runs more than one department.';
$string['warn:footnote'] = 'These counts are not sent to Report LMS. They are worked out on this site, from this site\'s own data.';

// Cron health. Named for Moodle's own scheduled tasks rather than for this
// plugin: the fault is not ours, and an administrator sent to our settings page
// would find nothing wrong there.
$string['cron:stalereport'] = 'These figures may be out of date.';
$string['cron:heading'] = 'Scheduled tasks:';
$string['cron:never'] = 'never run on this site.';
$string['cron:ago'] = 'last ran {$a} ago.';
$string['cron:nevercron'] = 'Moodle\'s scheduled tasks have never run on this site, so nothing has been sent to Report LMS and nothing will be. Ask whoever manages the server to set up cron, then check Site administration > Server > Scheduled tasks.';
$string['cron:cronstale'] = 'Moodle\'s scheduled tasks have not run for some hours, so the report is not being updated. Check Site administration > Server > Scheduled tasks.';
$string['cron:taskdisabled'] = 'The Report LMS send task is switched off, so nothing is being sent. Enable it in Site administration > Server > Scheduled tasks.';
$string['cron:tasknever'] = 'This site is connected but the Report LMS send task has never run, so no data has reached the report yet.';
$string['cron:taskstale'] = 'The Report LMS send task has not run for some hours, so the report is showing older figures than this site has.';
$string['connect:gotosetup'] = 'Go to setup';
$string['connect:setupintro'] = 'Setup runs on the Report LMS page in this site. It shows which steps are done, what is left, and makes the connection when you are ready. This page is for changing the connection afterwards.';
$string['block:settings'] = 'Report LMS settings';
$string['connect:success'] = 'This site is now connected to Report LMS.';
$string['connect:disconnected'] = 'This site is no longer connected to Report LMS.';
$string['connect:environmentid'] = 'Environment: {$a}';
$string['connect:tier'] = 'Licence tier: {$a}';
$string['connect:connectedat'] = 'Connected since {$a}';
$string['connect:keyexpires'] = 'Licence and key expire on {$a}';
$string['connect:lastpush'] = 'Last data sent {$a}';

// Profile fields the plugin creates at install. The display names are
// written into the field records once, so a later language pack change does
// not rename them; an administrator may rename them freely, since ensure()
// never overwrites an existing field.
$string['profilefield:category'] = 'Report LMS';
$string['profilefield:sector'] = 'Sector';
$string['profilefield:department'] = 'Department';
$string['profilefield:function'] = 'Function';

// Advanced settings.
$string['settings:advancedbutton'] = 'Advanced settings';
$string['settings:orgfieldsheading'] = 'Organisation fields';
$string['settings:orgfieldsintro'] = 'Which user profile fields hold each person\'s place in the organisation. The plugin created its own fields at install; map a field your site already maintains instead if you have one. Changing a mapping rebuilds the organisation tree from the newly mapped field on the next sync.';
$string['settings:orgfield_sector'] = 'Sector field';
$string['settings:orgfield_sector_desc'] = 'The profile field that names each person\'s sector.';
$string['settings:orgfield_department'] = 'Department field';
$string['settings:orgfield_department_desc'] = 'The profile field that names each person\'s department. The mapped field is locked against learners editing their own placement; administrators and HR imports keep writing normally.';
$string['settings:orgfield_function'] = 'Function field';
$string['settings:orgfield_function_desc'] = 'The profile field that names each person\'s function or role.';
$string['settings:orgfield_plugin'] = 'Report LMS field ({$a})';
$string['settings:orgfield_missing'] = '{$a} (missing on this site)';
$string['settings:orgfieldmissingwarning'] = 'A mapped organisation field does not exist on this site: {$a}. The organisation tree cannot be built from it — fix the mapping under Advanced settings.';
$string['settings:advancedheading'] = 'Advanced connection settings';
$string['settings:advancedwarning'] = 'These settings are managed by the connect process. Change them only if Report LMS support asks you to.';
$string['settings:baseurl'] = 'Report LMS URL';
$string['settings:baseurl_desc'] = 'The Report LMS instance this site connects to.';
$string['settings:pushpath'] = 'Push path';
$string['settings:pushpath_desc'] = 'The endpoint on the Report LMS host that receives data batches.';
$string['settings:enabled'] = 'Send data';
$string['settings:enabled_desc'] = 'Turn off to keep the connection but stop sending data.';

// Dashboard.
$string['dashboard:title'] = 'Report LMS dashboard';
$string['dashboard:notconnected'] = 'This site is not connected to Report LMS yet, so there is no report to show.';
$string['dashboard:back'] = 'Back';
$string['dashboard:settings'] = 'Report LMS settings';

// Capabilities.
$string['reportlms:view'] = 'Open the Report LMS dashboard';
$string['reportlms:manage'] = 'Manage the training of a course\'s participants';
$string['reportlms:viewall'] = 'See the whole organisation in Report LMS';
$string['reportlms:configure'] = 'Choose which cards the Report LMS dashboard shows';

// Labels resolved locally for the embedded dashboard.
$string['label:unassigned'] = 'Unassigned';
$string['cachedef_labels'] = 'Coded identifier to label map for the embedded dashboard';

// The people behind a figure, and the export of them.

// Dashboard block.
$string['block:title'] = 'Report LMS';
$string['block:intro'] = 'See how your teams are progressing through their training.';
$string['block:button'] = 'Open the report';
$string['block:addbutton'] = 'Add a block to the front page';
$string['block:removebutton'] = 'Remove the front page block';
$string['block:added'] = 'A Report LMS block has been added to the site front page.';
$string['block:removed'] = 'The Report LMS block has been removed from the site front page.';

// Scheduled tasks.
$string['task:push'] = 'Report LMS: push changed learners to the SaaS';
$string['task:reconcile'] = 'Report LMS: find learners changed outside the normal events';

// Errors.
$string['error:nohtmlblock'] = 'The HTML block is not available on this site, so the Dashboard block cannot be created.';
$string['error:nosalt'] = 'This site has no Report LMS coding key yet. Reconnect from the Report LMS settings.';
$string['error:connectfailed'] = 'Could not reach Report LMS: {$a}';
$string['error:connectrejected'] = 'Report LMS refused the connection: {$a}';
$string['error:pushfailed'] = 'Could not reach Report LMS for the push: {$a}';
$string['error:pushrejected'] = 'Report LMS refused the push: {$a}';
