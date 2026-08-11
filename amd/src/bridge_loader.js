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
 * matters was expensive to learn: a site ran a four-day-old copy of this script
 * for days, through two plugin upgrades, because a caching rule on its own web
 * server pinned the file for a year. Nothing we shipped could reach it.
 *
 * Three properties are kept deliberately.
 *
 * **Moodle stays the transport.** The served script is handed a `call` function
 * and never builds a request itself, so the sesskey that guards every change
 * stays inside code the customer installed. It cannot be read out of here.
 *
 * **The site keeps a copy.** {@link module:local_reportlms/bridge_local} runs
 * when the served one does not arrive, so a blocked host or an outage costs the
 * page nothing. The report degrades to what the customer installed rather than
 * to a dashboard of hashes.
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
import * as local from './bridge_local';

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
 * How long to wait for the served bridge before running our own.
 *
 * The page is useful without it and useless while it waits, so this is short.
 * A slow answer that lands later is ignored: whichever bridge starts first is
 * the one that runs, and two of them listening would answer every message twice.
 */
const LOAD_TIMEOUT_MS = 5000;

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

        window.setTimeout(() => reject(new Error('bridge timed out')), LOAD_TIMEOUT_MS);
        document.head.appendChild(script);
    });
};

/**
 * Start listening, with whichever bridge is available.
 *
 * @param {string} origin Report LMS origin, scheme and host only.
 * @param {string} frameselector
 * @param {number} pluginversion This plugin's version, so the frame can say which one is running.
 * @param {string} platform Which bridge to ask for, as config::PLATFORM names it.
 */
export const init = (origin, frameselector, pluginversion, platform) => {
    const options = {
        origin: origin,
        frame: document.querySelector(frameselector),
        pluginVersion: pluginversion,
        // The whole of this page's authority, handed over as one narrow
        // function. It can only reach the services db/services.php declares
        // ajax, and only as whoever is logged in.
        call: (methodname, args) => Ajax.call([{methodname: methodname, args: args}])[0],
    };

    let started = false;

    const start = (bridge) => {
        if (started) {
            return;
        }

        started = true;
        bridge.init(options);
    };

    if (origin === '') {
        // No configured Report LMS, so there is nothing to fetch and nothing to
        // talk to. Run ours so the page behaves the same as it always did.
        start(local);

        return;
    }

    loadRemote(origin + bridgePath(platform))
        .then(() => {
            const served = window.reportlms && window.reportlms.bridge;

            // Loaded but did not register: something answered on that path that
            // was not the bridge. Ours is the honest thing to run.
            start(served && typeof served.init === 'function' ? served : local);

            return true;
        })
        .catch(() => {
            start(local);
        });
};
