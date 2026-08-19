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
$string['privacy:metadata:member'] = 'Which part of the organisation somebody was placed in.';
$string['privacy:metadata:member:userid'] = 'The person placed.';
$string['privacy:metadata:member:nodeid'] = 'The unit they were placed in.';
$string['privacy:metadata:member:source'] = 'Whether this person was named here or excluded from the unit.';
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

// The mapping editor, where somebody states the organisation.
$string['manage:open'] = 'Open the organisation editor';
$string['manage:title'] = 'Report LMS: organisation';
$string['manage:intro'] = 'Your company tree, as you state it. Name the units, say who runs each one, and choose the people in them by course, category, cohort, group or department. Nothing here is guessed from your data.';
$string['manage:units'] = 'Units';
$string['manage:unit'] = 'Unit';
$string['manage:unitname'] = 'Name';
$string['manage:kind'] = 'Level';
$string['manage:kind:sector'] = 'Sector';
$string['manage:kind:department'] = 'Department';
$string['manage:parent'] = 'Sector above it';
$string['manage:nosector'] = 'No sector above it';
$string['manage:noparent'] = 'No department above it';
$string['manage:addunit'] = 'Add a unit';
$string['manage:addmanager'] = 'Put someone in charge';
// The parent, named beside a unit only in a filtered list, where the indent
// that normally says it cannot be trusted.
$string['manage:insideof'] = 'in {$a}';
// Shown after the rolled-up headcount, and only where the two differ: a unit
// holding nobody itself but several departments' worth of people underneath.
$string['manage:peopledirect'] = '· {$a} direct';
$string['manage:people'] = 'People';
$string['manage:nounits'] = 'No units have been typed in. The tree is entirely worked out from profile fields.';
$string['manage:search'] = 'Search units';
$string['manage:anymanager'] = 'Any manager';
$string['manage:nomanager'] = 'Nobody yet';
$string['manage:showall'] = 'Show every unit';
$string['manage:nomatch'] = 'No unit matches that.';
$string['manage:managers'] = 'Managers';
$string['manage:managers_help'] = 'Naming somebody here gives them the report for that unit. No role assignment and no capability to grant. Naming a role instead means everybody holding it manages the unit, which saves editing this list every time somebody joins or leaves.';
$string['manage:manager'] = 'Manager';
$string['manage:route'] = 'How';
$string['manage:route:named'] = 'Named here';
$string['manage:route:capability'] = 'Holds the capability';
$string['manage:everyoneholding'] = 'Everyone holding {$a}';
$string['manage:goneuser'] = 'A user who no longer exists';
$string['manage:nomanagers'] = 'Nobody manages anything yet, so nobody but an administrator sees a report.';
$string['manage:placed'] = 'People';
$string['manage:peoplein'] = 'People in {$a}';
$string['manage:why'] = 'Origin';
$string['manage:why:named'] = 'Named here';
$string['manage:why:source'] = 'Drawn by a course, cohort or group';
$string['manage:why:drawnby'] = 'Drawn by {$a}';
$string['manage:source:course'] = 'course "{$a}"';
$string['manage:source:category'] = 'category "{$a}"';
$string['manage:source:cohort'] = 'cohort "{$a}"';
$string['manage:source:group'] = 'group "{$a}"';
$string['manage:source:profilefield'] = 'profile value "{$a}"';
$string['manage:why:adopted'] = 'Manual';
$string['manage:saved'] = 'Saved. This unit now holds {$a->people} people, drawn from {$a->sources} source(s) plus anybody named by hand.';
$string['manage:nametaken'] = 'There is already a unit called "{$a}" in the same place. Two units with one name under one parent are one unit to Report LMS, so their people would be merged. Rename one of them, or put this one somewhere else.';
$string['manage:repeatednames'] = 'These names are used by more than one unit: {$a}. That is allowed, because a unit in one sector is a different group of people from one with the same name in another, but the table below cannot tell them apart at a glance.';
$string['manage:backtounits'] = 'Back to the organisation';
$string['manage:nobodyhere'] = 'Nobody has been placed in this unit yet.';
$string['manage:managedby'] = 'Managed by {$a}.';
$string['manage:managedbynobody'] = 'Nobody manages this unit yet, so nobody but an administrator sees its report.';
$string['manage:managerof'] = 'Manages {$a}';
$string['manage:placed_help'] = 'The people in this unit. Remove somebody to take them out of it; they are then in no unit until you put them in one.';
$string['manage:person'] = 'Person';
$string['manage:release'] = 'Remove';
$string['manage:removenaming'] = 'Remove naming';
$string['manage:placeconfirm'] = 'Add {$a->count} people to {$a->unit}?<br /><br />They stay in this unit until somebody releases them, and their own profile field stops moving them. Check the number: a manager who is an ordinary participant on a site-wide course brings everybody on it.';
$string['manage:fromcourses'] = 'Or everyone enrolled on these courses';
$string['manage:fromcategories'] = 'Or everyone in these categories';
$string['manage:fromcohorts'] = 'Or everyone in these cohorts';
$string['manage:fromgroups'] = 'Or everyone in these groups';
$string['manage:fromprofile'] = 'Or everyone whose department is';
$string['manage:toomanygroups'] = 'This site has {$a} groups, too many to list here. Point at the course instead, or name people one at a time.';
$string['manage:pickoneorother'] = 'Name at least one: a person, a group, some courses, a cohort, some categories, or a department value.';
$string['manage:placedpeople'] = 'Added {$a->added}, removed {$a->removed}.';
$string['manage:fix'] = 'Fix';
$string['manage:fix_help'] = 'Write this down here, so who manages whom is stated rather than inherited from a role.';
$string['manage:alsogranted'] = '{$a} of the people above would also get in through a role, so removing their naming would not remove their access.';
$string['manage:alsogranted_link'] = 'Check the roles that grant it';
$string['manage:needunitformanager'] = 'Name a unit above first. A manager manages something, so there is nothing to name one against yet.';

$string['warn:heading'] = 'Worth checking in your organisation structure:';
$string['warn:unplaced'] = '{$a} people are not in any unit. They are still counted in the site totals and appear under Unassigned, but they will not show up under a department until somebody puts them in one.';
$string['warn:unplaced_link'] = 'Open the organisation structure';
$string['warn:footnote'] = 'This count is not sent to Report LMS. It is worked out on this site, from this site\'s own data.';

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
$string['block:settings'] = 'Report LMS organisation and settings';
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
$string['settings:orgfieldsheading'] = 'Profile fields';
$string['settings:orgfieldsintro'] = 'Which user profile field names each person\'s function. Departments are not read from a profile field: they are set in the organisation structure. The plugin created its own field at install; map a field your site already maintains instead if you have one.';
$string['settings:orgfield_function'] = 'Function field';
$string['settings:orgfield_function_desc'] = 'The profile field that names each person\'s function or role.';
$string['settings:writeback_off'] = 'Do not write back';
$string['settings:writeback_sector'] = 'Publish the sector into';
$string['settings:writeback_sector_desc'] = 'The profile field each person\'s sector is written into on every sync. The organisation structure is the source and this field is a copy of it, so anything else written here is replaced. The plugin\'s own field is hidden from learners; if you point this at a field your HR import also writes, the two will overwrite each other.';
$string['settings:writeback_department'] = 'Publish the department into';
$string['settings:writeback_department_desc'] = 'The profile field each person\'s department is written into on every sync. The organisation structure is the source and this field is a copy of it, so anything else written here is replaced. The plugin\'s own field is hidden from learners; if you point this at a field your HR import also writes, the two will overwrite each other.';
$string['settings:writebackrefused'] = 'A field chosen for publishing the organisation is being ignored: {$a}. Either it no longer exists, or it is already the function field, or both levels name the same field. Nothing is written to it.';
$string['settings:orgfield_plugin'] = 'Report LMS field ({$a})';
$string['settings:orgfield_missing'] = '{$a} (missing on this site)';
$string['settings:orgfieldmissingwarning'] = 'The mapped function field does not exist on this site: {$a}. Nobody will have a function in the report, so fix the mapping under Advanced settings.';
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
$string['dashboard:manage'] = 'Organisation and managers';

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
