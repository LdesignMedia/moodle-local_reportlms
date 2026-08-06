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
$string['privacy:metadata'] = 'The Report LMS plugin stores no personal data. It holds only the site connection settings.';

// Connect flow.
$string['connect:button'] = 'Connect to Report LMS';
$string['connect:disconnectbutton'] = 'Disconnect';
$string['connect:statuslabel'] = 'Status:';
$string['connect:statusconnected'] = 'Connected';
$string['connect:statusdisconnected'] = 'Not connected';
// Cron health. Named for Moodle's own scheduled tasks rather than for this
// plugin: the fault is not ours, and an administrator sent to our settings page
// would find nothing wrong there.
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

// Advanced settings.
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

// Labels resolved locally for the embedded dashboard.
$string['label:unassigned'] = 'Unassigned';
$string['cachedef_labels'] = 'Coded identifier to label map for the embedded dashboard';

// The people behind a figure, and the export of them.
$string['people:title'] = 'Report LMS: who is not finished';
$string['people:notcompleted'] = 'Not yet completed';
$string['people:scope'] = 'Showing: {$a}';
$string['people:everyone'] = 'the whole organisation';
$string['people:none'] = 'Nobody in this selection has an unfinished training.';
$string['people:backtoreport'] = 'Back to the report';
$string['people:export'] = 'Download as CSV';
$string['people:truncated'] = 'Only the first {$a} rows are shown. Narrow the selection to see the rest.';
$string['people:unresolved'] = 'Part of this selection no longer matches anything on this site, so the list below may be wider than the figure you clicked. It usually means a department, job title or course was renamed since the report was last updated.';
$string['people:col:name'] = 'Name';
$string['people:col:email'] = 'E-mail';
$string['people:col:course'] = 'Training';
$string['people:col:enrolled'] = 'Enrolled since';
$string['people:col:status'] = 'Status';
$string['people:status:notstarted'] = 'Not started';
$string['people:status:inprogress'] = 'In progress';

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
$string['error:outsidescope'] = 'That part of the organisation is not yours to see.';
$string['error:nosalt'] = 'This site has no Report LMS coding key yet. Reconnect from the Report LMS settings.';
$string['error:connectfailed'] = 'Could not reach Report LMS: {$a}';
$string['error:connectrejected'] = 'Report LMS refused the connection: {$a}';
$string['error:pushfailed'] = 'Could not reach Report LMS for the push: {$a}';
$string['error:pushrejected'] = 'Report LMS refused the push: {$a}';
