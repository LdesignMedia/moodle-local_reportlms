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
$string['privacy:metadata:reportlms'] = 'Training results are sent to Report LMS. No name, e-mail address or phone number is ever sent. People, organisation units and course categories are replaced with coded identifiers before they leave this site, using a key that stays here. Course and activity titles are sent as they are, so the report is readable. Names, and e-mail addresses and phone numbers for viewers this site lets see them, reach the dashboard only by this site answering the viewer\'s browser directly. Report LMS never holds them. The contact e-mail address you give is sent to Report LMS and used only for licence and service messages. It is never linked to the figures.';
$string['privacy:metadata:reportlms:learner_pid'] = 'A coded identifier standing for one learner.';
$string['privacy:metadata:reportlms:course_ref'] = 'A coded identifier standing for one course.';
$string['privacy:metadata:reportlms:status'] = 'Whether a course was completed, is in progress, or has not been started.';
$string['privacy:metadata:reportlms:grade'] = 'The grade on the main test of the course, as a percentage.';
$string['privacy:metadata:reportlms:attempts'] = 'How many attempts were taken at that test, and when the last one was finished.';
$string['privacy:metadata:reportlms:last_attempt_at'] = 'When the last of those attempts was finished.';
$string['privacy:metadata:reportlms:contact_email'] = 'The contact e-mail address given for this site, used only for licence and service messages and never linked to the figures.';
$string['privacy:metadata:reportlms:held'] = 'Whether a person is outside the site\'s plan. For such a person only a coded identifier, the login method, whether the account is active and any unit they manage are sent, never results.';
$string['privacy:metadata:reportlms:limit_applied'] = 'How many people the site\'s plan covers, as this site applied it when it listed its people.';
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
$string['manage:unmeasurable'] = '{$a} training(s) are not in the report';
$string['manage:unmeasurable_help'] = 'These courses have no completion rule, so Moodle never marks anybody as having finished them. Nothing about them can be reported: no completions, no grades, no attempts. They are left out of the report entirely until a rule is set.';
$string['manage:setcompletion'] = 'Set a completion rule';
$string['manage:units'] = 'Units';
$string['manage:unit'] = 'Unit';
$string['manage:unitname'] = 'Name';
$string['manage:kind'] = 'Level';
$string['manage:kind:sector'] = 'Sector';
$string['manage:kind:department'] = 'Department';
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
$string['manage:managers_help'] = 'Naming somebody here gives them the report for that unit. No role assignment and no capability to grant.';
$string['manage:manager'] = 'Manager';
$string['manage:route'] = 'How';
$string['manage:route:named'] = 'Named here';
$string['manage:route:capability'] = 'Holds the capability';
$string['manage:everyoneholding'] = 'Everyone holding {$a}';
$string['manage:goneuser'] = 'A user who no longer exists';
$string['manage:nomanagers'] = 'Nobody manages anything yet, so nobody but an administrator sees a report.';
$string['manage:placed'] = 'People';
$string['manage:deleteunit'] = 'Delete {$a}';
$string['manage:deleteunitconfirm'] = 'Delete {$a}? Its people, its managers and the sources it drew from are forgotten. No account is deleted, and any units underneath it move to the top of the tree.';
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
$string['manage:nameslash'] = 'A unit name cannot contain a slash. The slash is what joins a unit to the one above it.';
$string['manage:namekeyclash'] = 'The unit "{$a}" would end up with the same key as another unit, and Report LMS would treat them as one. Rename it.';
$string['manage:parentgone'] = 'The unit above this one no longer exists. Pick another place for it.';
$string['manage:deleteclash'] = 'Not deleted. The unit "{$a}" would move to the top level, where a unit with that name already exists. Rename one of them first.';
$string['manage:toomany'] = 'Nothing was saved. A list can hold at most {$a} entries, so choose fewer or split them over several units.';
$string['manage:repeatednames'] = 'These names are used by more than one unit: {$a}. That is allowed, because a unit in one sector is a different group of people from one with the same name in another, but the table below cannot tell them apart at a glance.';
$string['manage:backtounits'] = 'Back to the organisation';
$string['manage:nobodyhere'] = 'Nobody has been placed in this unit yet.';
$string['manage:managedby'] = 'Managed by {$a}.';
$string['manage:managedbynobody'] = 'Nobody manages this unit yet, so nobody but an administrator sees its report.';
$string['manage:managerof'] = 'Manages {$a}';
$string['manage:placed_help'] = 'The people in this unit. A person can be in several units: everybody a source of this unit includes, and everybody named into it. Remove takes somebody out of this unit only; people a source includes stay until the source changes.';
$string['manage:person'] = 'Person';
$string['manage:release'] = 'Remove';
$string['manage:removenaming'] = 'Remove naming';
$string['manage:removemanagerconfirm'] = 'Remove {$a->who} as manager of {$a->unit}? They lose the report for that unit. No role assignment changes and no account is touched.';
$string['manage:removenamingconfirm'] = 'Remove the naming of {$a->who} on {$a->unit}? They hold the capability as well, so they keep the report and stay on this list with that as their only route.';
$string['manage:placeconfirm'] = 'Add {$a->count} people to {$a->unit}?<br /><br />They stay in this unit until somebody releases them, and their own profile field stops moving them. Check the number: a manager who is an ordinary participant on a site-wide course brings everybody on it.';
$string['manage:sources'] = 'Who is in this unit';
$string['manage:sources_help'] = 'Everything below answers one question: which people belong to this unit. Courses, categories, cohorts, groups and profile values are only ways of naming a set of people. Choose two courses and everybody enrolled on either one joins the unit. It does not narrow the report to those courses: once somebody is in the unit, everything they do on every course they are on is reported.';
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
$string['claim:button'] = 'Enter a claim code';
$string['claim:title'] = 'Claim code';
$string['claim:intro'] = 'Your account page on Report LMS shows a claim code. Enter it here to prove that your account runs this site. Your account needs this before it can buy, move or cancel the licence of this site online. Connecting does not need it.';
$string['claim:code'] = 'Claim code';
$string['claim:submit'] = 'Send the code';
$string['claim:success'] = 'Report LMS accepted the claim code.';
$string['claim:invalid'] = 'Enter the claim code from your account page on Report LMS, for example K7QF-29XM.';
$string['claim:notconnected'] = 'Connect this site to Report LMS first. The claim code is sent over that connection.';
$string['claim:failed'] = 'The claim code could not be sent. Try again in a minute.';
$string['limit:covers'] = 'Your plan covers {$a} people.';
$string['limit:covers_one'] = 'Your plan covers 1 person.';
$string['limit:sitehas'] = 'This site has {$a} people.';
$string['limit:sitehas_one'] = 'This site has 1 person.';
$string['limit:shown'] = 'Reports show the first {$a}.';
$string['limit:shown_one'] = 'Reports show the first person.';
$string['limit:shown_none'] = 'Reports show nobody.';
$string['limit:held'] = 'For the other {$a} only a coded id, the account status and login method (and any unit they manage) are sent, no learning data.';
$string['limit:held_one'] = 'For the other person only a coded id, the account status and login method (and any unit they manage) are sent, no learning data.';
$string['limit:held_all'] = 'For everybody only a coded id, the account status and login method (and any unit they manage) are sent, no learning data.';
$string['limit:upgrade'] = 'Upgrade at {$a}.';

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
$string['settings:deadlineheading'] = 'What counts as late';
$string['settings:deadlineintro'] = 'The report marks somebody overdue when a training of theirs is past its deadline and not finished. This is where you say what that deadline is. A change here reaches the report at the next sync.';
$string['settings:deadline_source'] = 'Deadline for finishing a training';
$string['settings:deadline_source_desc'] = 'The enrolment end date is the deadline your LMS already holds, but be aware of what that field does: past it the enrolment ends and the learner can no longer open the course. A period after enrolment has no effect on access and needs nothing entered per learner.';
$string['settings:deadline_enrolment_end'] = 'The enrolment end date';
$string['settings:deadline_period'] = 'A period after enrolment';
$string['settings:deadline_days'] = 'Days to finish in';
$string['settings:deadline_days_desc'] = 'Used only with the period above, and counted from the date somebody was enrolled. Thirty days for an onboarding module, 365 for a yearly refresher. It applies to enrolments already in place, so on a site with older enrolments this can mark a great many people overdue at once.';
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
$string['settings:contactemail'] = 'Contact email';
$string['settings:contactemail_desc'] = 'Report LMS writes to this address about the licence and service. Sent as soon as it is saved. An address set by Report LMS staff is kept.';
$string['settings:baseurl'] = 'Report LMS URL';
$string['settings:baseurl_desc'] = 'The Report LMS instance this site connects to. It must start with https://.';
$string['settings:pushpath'] = 'Push path';
$string['settings:pushpath_desc'] = 'The endpoint on the Report LMS host that receives data batches.';
$string['settings:enabled'] = 'Send data';
$string['settings:enabled_desc'] = 'Turn off to keep the connection but stop sending data.';

// Dashboard.
$string['dashboard:title'] = 'Report LMS dashboard';
$string['dashboard:notconnected'] = 'This site is not connected to Report LMS yet, so there is no report to show.';
$string['dashboard:back'] = 'Back';
$string['dashboard:settings'] = 'Report LMS settings';
$string['dashboard:manage'] = 'Organisation structure';

// Capabilities.
$string['reportlms:view'] = 'Open the Report LMS dashboard';
$string['reportlms:manage'] = 'Manage the training of a course\'s participants';
$string['reportlms:viewall'] = 'See the whole organisation in Report LMS';
$string['reportlms:configure'] = 'Choose which cards the Report LMS dashboard shows';

// Labels resolved locally for the embedded dashboard.
$string['label:unassigned'] = 'Unassigned';
$string['cachedef_labels'] = 'Coded identifier to label map for the embedded dashboard';

// The people behind a figure, and the export of them.

// Contact address, asked for after an upgrade when Report LMS has none.
$string['contact:field'] = 'Contact email';
$string['contact:help'] = 'Used only for licence and service messages. Only site administrators see this question.';
$string['contact:intro'] = 'Report LMS has no contact for this site yet. Add one address and your report opens right after.';
$string['contact:invalid'] = 'That does not look like an e-mail address. Check it and try again.';
$string['contact:save'] = 'Save and open the report';
$string['contact:title'] = 'Who should we write to about your licence?';

// Dashboard block.
$string['block:title'] = 'Report LMS';
$string['block:intro'] = 'See how your teams are progressing through their training.';
$string['block:button'] = 'Open the report';
$string['block:addbutton'] = 'Add a block to the front page';
$string['block:removebutton'] = 'Remove the front page block';
$string['block:added'] = 'A Report LMS block has been added to the site front page.';
$string['block:removed'] = 'The Report LMS block has been removed from the site front page.';

// Events.
$string['event:learneremailsresolved'] = 'Learner e-mail addresses given to the Report LMS dashboard';
$string['event:learnercontactsresolved'] = 'Learner e-mail addresses and phone numbers given to the Report LMS dashboard';

// Scheduled tasks.
$string['task:push'] = 'Report LMS: push changed learners to the SaaS';
$string['task:reconcile'] = 'Report LMS: find learners changed outside the normal events';

// Errors.
$string['error:nohtmlblock'] = 'The HTML block is not available on this site, so the Dashboard block cannot be created.';
$string['error:nosalt'] = 'This site has no Report LMS coding key yet. Reconnect from the Report LMS settings.';
$string['error:connectfailed'] = 'Could not reach Report LMS: {$a}';
$string['error:insecurebaseurl'] = 'The Report LMS address must start with https://. Nothing is sent to Report LMS until it does. Change it in the plugin settings, or in config.php if it is set there.';
$string['error:connectrejected'] = 'Report LMS refused the connection: {$a}';
$string['error:claimanswer'] = '{$a}';
$string['error:claimrefused'] = 'Report LMS did not accept the claim code: {$a}';
$string['error:claimtoomany'] = 'Too many tries with a claim code. Wait a while, then try again.';
$string['error:claimunavailable'] = 'Report LMS could not answer right now ({$a}). Try again in a minute.';
$string['error:pushfailed'] = 'Could not reach Report LMS for the push: {$a}';
$string['error:pushrejected'] = 'Report LMS refused the push: {$a}';
$string['error:verifynoncerequired'] = 'A nonce is required.';
$string['error:verifynopending'] = 'No connection attempt is waiting to be verified.';
