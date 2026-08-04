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
$string['connect:reconnectbutton'] = 'Reconnect';
$string['connect:disconnectbutton'] = 'Disconnect';
$string['connect:statuslabel'] = 'Status:';
$string['connect:statusconnected'] = 'Connected';
$string['connect:statusdisconnected'] = 'Not connected';
$string['connect:intro'] = 'Connect this site to Report LMS to start sending reporting data. Your site must already be registered in the Report LMS licence system.';
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
$string['dashboard:gotosettings'] = 'Go to the Report LMS settings';
$string['dashboard:back'] = 'Back';
$string['dashboard:openbutton'] = 'Open the dashboard';

// Labels resolved locally for the embedded dashboard.
$string['label:unassigned'] = 'Unassigned';
$string['cachedef_labels'] = 'Coded identifier to label map for the embedded dashboard';

// Dashboard block.
$string['block:title'] = 'Report LMS';
$string['block:intro'] = 'See how your teams are progressing through their training.';
$string['block:button'] = 'Open the report';
$string['block:addbutton'] = 'Add a block to the front page';
$string['block:removebutton'] = 'Remove the front page block';
$string['block:added'] = 'A Report LMS block has been added to the site front page.';
$string['block:removed'] = 'The Report LMS block has been removed from the site front page.';
$string['block:present'] = 'A Report LMS block is on the site front page.';

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
