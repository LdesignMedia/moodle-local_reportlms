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
 * Loads the bridge between this page and the embedded dashboard.
 *
 * The dashboard renders on the Report LMS origin and holds only codes, because
 * codes are all this site ever sends it. It asks this page for the names, this
 * page asks Moodle, and the answer goes straight back into the frame. No name is
 * ever sent to Report LMS, not even in passing.
 *
 * What this file does is fetch that conversation rather than contain it. Report
 * LMS serves the bridge, so a fault in it is fixed for every customer at once
 * instead of waiting for each of them to install a new plugin. The reason that
 * matters was expensive to learn: a site ran a four-day-old copy of the bridge
 * for days, through two plugin upgrades, because a caching rule on its own web
 * server pinned the file for a year. Nothing we shipped could reach it.
 *
 * **Moodle stays the transport, and only for this plugin's services.** The
 * served script runs in this Moodle page, as whoever is viewing it, with their
 * session and everything else a script on this page can reach. That is the
 * trade of serving it rather than shipping it, and it is not something this
 * file can narrow. What this file does decide is the function it hands over:
 * `call` forwards to Moodle's own AJAX endpoint, and only for the services
 * this plugin declares in db/services.php. Anything else is refused before a
 * request is made. A fault in the bridge therefore cannot wander into one of
 * Moodle's own services through the door it was given, which is defence in
 * depth, not a boundary against a script that set out to build its own.
 *
 * **There is no local copy of the bridge, deliberately.** One shipped with the
 * first version of this and it was reasoning that did not survive being
 * finished: it was written to cover Report LMS being unreachable, and in that
 * case the frame never loads either, so there is no dashboard for a bridge to
 * talk to and nothing for it to answer. What it really covered was a policy or
 * an extension that allows the frame but blocks the script, and for that a
 * second copy quietly drifting out of step with the served one is worse than
 * saying plainly that the script did not load. Which is what happens now: a
 * bridge that never arrives never answers the frame's opening message, and the
 * frame says so.
 *
 * A site can still be connected with no script at all. `connect.php` is the
 * plain, scriptless way to do it.
 *
 * **The served script is not pinned to a hash.** Subresource integrity would put
 * us straight back to needing a plugin update for every fix, which is the thing
 * this exists to stop. The trade is stated plainly in the data statement.
 *
 * @module     local_reportlms/bridge_loader
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Where Report LMS serves the bridge for this kind of LMS.
 *
 * Platform first, because the bridge talks to whatever is around it and a Moodle
 * one is not a Workplace one. Version after that, so a breaking change to the
 * message contract becomes a file this plugin never asks for rather than a new
 * body it cannot speak. The platform is passed in rather than written here, so
 * PHP's config::PLATFORM stays the only place that names it.
 *
 * @param {string} platform
 * @returns {string}
 */
const bridgePath = (platform) => '/bridge/' + platform + '/v1.js';

/**
 * The web services the served bridge may call through this page.
 *
 * This plugin's own, exactly as db/services.php declares them, and nothing
 * else. Every one of them does its own capability check as the logged-in
 * viewer; the list only stops the bridge reaching anything that is not ours.
 * A service added there that the bridge needs has to be added here too, and
 * one the bridge does not need should stay off it.
 *
 * @type {string[]}
 */
const BRIDGE_SERVICES = [
    'local_reportlms_connect',
    'local_reportlms_resolve_labels',
    'local_reportlms_resolve_learners',
    'local_reportlms_resolve_learner_emails',
];

/**
 * Call one of this plugin's web services for the bridge, and refuse any other.
 *
 * @param {string} methodname
 * @param {Object} args
 * @returns {Promise} Moodle's answer, or a rejection naming the refused service.
 */
const callForBridge = (methodname, args) => {
    if (!BRIDGE_SERVICES.includes(methodname)) {
        return Promise.reject(new Error('local_reportlms: the bridge may not call ' + methodname));
    }

    return Ajax.call([{methodname: methodname, args: args}])[0];
};

/**
 * Fetch and run the bridge Report LMS serves.
 *
 * @param {string} src
 * @returns {Promise} Resolves once the script has run, rejects if it cannot.
 */
const loadRemote = (src) => {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');

        script.src = src;
        script.async = true;
        // Nothing about this request needs the visitor's cookies for the Report
        // LMS host, and sending them would tie a named LMS session to a script
        // fetch for no gain.
        script.crossOrigin = 'anonymous';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('bridge did not load'));

        document.head.appendChild(script);
    });
};

/**
 * Hand this page's side of the conversation to the bridge Report LMS serves.
 *
 * @param {string} origin Report LMS origin, scheme and host only.
 * @param {string} frameselector
 * @param {number} pluginversion This plugin's version, so the frame can say which one is running.
 * @param {string} platform Which bridge to ask for, as config::PLATFORM names it.
 */
export const init = (origin, frameselector, pluginversion, platform) => {
    if (origin === '') {
        // No configured Report LMS, so there is nothing to fetch and nothing to
        // talk to.
        return;
    }

    loadRemote(origin + bridgePath(platform))
        .then(() => {
            const bridge = window.reportlms && window.reportlms.bridge;

            if (!bridge || typeof bridge.init !== 'function') {
                // Something answered on that path and it was not the bridge.
                // Staying silent is right: the frame's opening message goes
                // unanswered and it tells the reader the script did not load,
                // which is true and actionable.
                return false;
            }

            bridge.init({
                origin: origin,
                frame: document.querySelector(frameselector),
                pluginVersion: pluginversion,
                // One narrow function: it forwards this plugin's own services,
                // as whoever is logged in, and refuses every other. The script
                // itself still runs in this page with the viewer's session, so
                // this narrows what the bridge is given, not what a script on
                // this page could do.
                call: callForBridge,
            });

            return true;
        })
        .catch(() => {
            // Blocked, offline, or refused by a content policy. Nothing to do
            // and nothing to pretend: the frame asks whether anybody is
            // listening, hears nothing, and says so.
            return false;
        });
};
