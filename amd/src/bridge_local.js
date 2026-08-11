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
 * The bridge, as this site holds it. The fallback copy.
 *
 * Report LMS serves the bridge it wants the page to run, so a fix reaches every
 * customer without any of them installing anything. This copy is what runs when
 * that script does not arrive: a blocked host, an outage, a policy that will not
 * load third-party script. See {@link module:local_reportlms/label_bridge}.
 *
 * It is deliberately a frozen fallback, not a second implementation kept in
 * lockstep. It was correct on the day it shipped and it stays here so the report
 * degrades to "what you installed" rather than to nothing. Do not add features
 * to it; add them to the served bridge, and refresh this from that only when the
 * message contract itself changes.
 *
 * Every behaviour here is explained where the served copy defines it. What is
 * worth repeating: both directions are origin checked, and no name is ever sent
 * to Report LMS, not even in passing.
 *
 * @module     local_reportlms/bridge_local
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Message the frame sends to ask for labels. */
const RESOLVE = 'reportlms:resolve';

/** Message this page sends back with them. */
const LABELS = 'reportlms:labels';

/** Message the frame sends to ask for the names behind coded learner ids. */
const LEARNERS = 'reportlms:learners';

/** The names for those learners, going back. */
const LEARNER_NAMES = 'reportlms:learner-names';

/** Message the frame sends to find out whether anybody is listening. */
const HELLO = 'reportlms:hello';

/** The answer, carrying what this page can actually do. */
const READY = 'reportlms:ready';

/** Message the getting-started frame sends once the site is connected. */
const CONNECTED = 'reportlms:connected';

/** Message the frame sends when an administrator presses connect. */
const CONNECT = 'reportlms:connect';

/** Message this page sends back when connecting did not work. */
const CONNECT_FAILED = 'reportlms:connect-failed';

/**
 * Whether a message came from the dashboard we actually embedded.
 *
 * @param {MessageEvent} event
 * @param {string} origin The Report LMS origin.
 * @param {HTMLElement} frame
 * @returns {boolean}
 */
const isFromOurFrame = (event, origin, frame) => {
    return event.origin === origin
        && frame !== null
        && event.source === frame.contentWindow;
};

/**
 * Start listening.
 *
 * @param {Object} options
 * @param {string} options.origin Report LMS origin, scheme and host only.
 * @param {HTMLElement} options.frame The embedded dashboard.
 * @param {number} options.pluginVersion This plugin's version.
 * @param {Function} options.call Calls a Moodle web service, returning a promise.
 */
export const init = (options) => {
    const {origin, frame, pluginVersion, call} = options;

    // The welcome flag is spent the moment the frame is built with it. Take it
    // out of the address so a refresh is a refresh and not a second connection
    // being celebrated.
    if (window.history && window.history.replaceState) {
        const here = new URL(window.location.href);

        if (here.searchParams.has('welcome')) {
            here.searchParams.delete('welcome');
            window.history.replaceState(null, '', here.toString());
        }
    }

    window.addEventListener('message', (event) => {
        if (!isFromOurFrame(event, origin, frame)) {
            return;
        }

        const message = event.data;

        if (!message || typeof message !== 'object') {
            return;
        }

        if (message.type === HELLO) {
            event.source.postMessage({
                type: READY,
                pluginVersion: pluginVersion,
                // So the frame can say which bridge answered. A fallback that
                // is answering means the served one did not arrive, and that is
                // worth knowing before anybody debugs the wrong thing.
                bridge: 'local',
            }, origin);

            return;
        }

        if (message.type === CONNECT) {
            call('local_reportlms_connect', {}).then((result) => {
                if (result.connected) {
                    const next = new URL(window.location.href);
                    next.searchParams.set('welcome', '1');
                    window.location.replace(next.toString());

                    return true;
                }

                event.source.postMessage({
                    type: CONNECT_FAILED,
                    error: result.error,
                }, origin);

                return false;
            }).catch((error) => {
                event.source.postMessage({
                    type: CONNECT_FAILED,
                    error: String((error && (error.message || error.errorcode)) || error),
                }, origin);
            });

            return;
        }

        if (message.type === CONNECTED) {
            window.location.reload();
            return;
        }

        if (message.type === LEARNERS && Array.isArray(message.codes)) {
            const wanted = message.codes.filter((code) => typeof code === 'string');

            call('local_reportlms_resolve_learners', {codes: wanted}).then((response) => {
                const names = {};

                response.learners.forEach((entry) => {
                    names[entry.code] = entry.name;
                });

                event.source.postMessage({
                    type: LEARNER_NAMES,
                    requestId: message.requestId,
                    names: names,
                }, origin);

                return true;
            }).catch(() => {
                event.source.postMessage({
                    type: LEARNER_NAMES,
                    requestId: message.requestId,
                    names: {},
                }, origin);
            });

            return;
        }

        if (message.type !== RESOLVE || !Array.isArray(message.codes)) {
            return;
        }

        const codes = message.codes.filter((code) => typeof code === 'string');

        call('local_reportlms_resolve_labels', {codes: codes}).then((response) => {
            const labels = {};

            response.labels.forEach((entry) => {
                labels[entry.code] = {
                    label: entry.label,
                    kind: entry.kind,
                    parent: entry.parent === undefined ? null : entry.parent,
                };
            });

            event.source.postMessage({
                type: LABELS,
                requestId: message.requestId,
                labels: labels,
            }, origin);

            return labels;
        }).catch(() => {
            event.source.postMessage({
                type: LABELS,
                requestId: message.requestId,
                labels: {},
            }, origin);
        });
    });
};
