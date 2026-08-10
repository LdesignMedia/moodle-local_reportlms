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
 * Names the coded identifiers the embedded dashboard is about to draw.
 *
 * The dashboard renders on the Report LMS origin and holds only codes, because
 * codes are all this site ever sends it. It asks this page for the labels, this
 * page asks Moodle, and the answer goes straight back into the frame. No name
 * is ever sent to Report LMS, not even in passing.
 *
 * Both directions are origin checked. Without that, any page that managed to
 * frame or be framed by this one could ask a logged-in administrator's browser
 * to enumerate the organisation for it.
 *
 * @module     local_reportlms/label_bridge
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** Message the frame sends to ask for labels. */
const RESOLVE = 'reportlms:resolve';

/** Message this page sends back with them. */
const LABELS = 'reportlms:labels';

/** Message the frame sends to drill through to the people behind a number. */
const DRILLDOWN = 'reportlms:drilldown';

/**
 * Message the getting-started frame sends once the site is licensed and
 * connected. The frame cannot reload the page around it; only this side can.
 */
const CONNECTED = 'reportlms:connected';

/**
 * Message the getting-started frame sends when an administrator confirms they
 * have read what leaves this site. The record is kept here rather than at
 * Report LMS: it names a person, which is precisely what the statement being
 * acknowledged says never leaves.
 */
const LEARNERS = 'reportlms:learners';

/**
 * The names for those learners, going back.
 */
const LEARNER_NAMES = 'reportlms:learner-names';

const ACKNOWLEDGE = 'reportlms:acknowledge';

/** Message this page sends back once that is stored. */
const ACKNOWLEDGED = 'reportlms:acknowledged';

/**
 * Message the getting-started frame sends when an administrator presses
 * connect. The frame cannot post to this site directly: a sesskey guards the
 * change and that token must never leave this origin, so the frame asks and
 * this page does it.
 */
const CONNECT = 'reportlms:connect';

/** Message this page sends back when connecting did not work. */
const CONNECT_FAILED = 'reportlms:connect-failed';

/**
 * Whether a message came from the dashboard we actually embedded.
 *
 * @param {MessageEvent} event
 * @param {string} origin The Report LMS origin, as this page built the iframe src from.
 * @param {HTMLIFrameElement} frame
 * @returns {boolean}
 */
const isFromOurFrame = (event, origin, frame) => {
    return event.origin === origin
        && frame !== null
        && event.source === frame.contentWindow;
};

/**
 * Ask Moodle what these codes are called.
 *
 * @param {Array<string>} codes
 * @returns {Promise<Object>} code => {label, kind, parent}
 */
const resolve = (codes) => {
    return Ajax.call([{
        methodname: 'local_reportlms_resolve_labels',
        args: {codes: codes},
    }])[0].then((response) => {
        const labels = {};

        response.labels.forEach((entry) => {
            labels[entry.code] = {
                label: entry.label,
                kind: entry.kind,
                parent: entry.parent === undefined ? null : entry.parent,
            };
        });

        return labels;
    });
};

/**
 * Record the reading against this site.
 *
 * @returns {Promise<Object>}
 */
const acknowledge = () => {
    return Ajax.call([{
        methodname: 'local_reportlms_acknowledge_privacy',
        args: {},
    }])[0];
};

/**
 * Put names to coded learners.
 *
 * Which of them are answered is decided in Moodle against the people this
 * viewer manages, not here. Asking about somebody else's learner is not an
 * error, it simply comes back without them.
 *
 * @param {Array<string>} codes
 * @returns {Promise<Object>}
 */
const learners = (codes) => {
    return Ajax.call([{
        methodname: 'local_reportlms_resolve_learners',
        args: {codes: codes},
    }])[0];
};

/**
 * Connect this site.
 *
 * @returns {Promise<Object>} {connected, error}
 */
const connect = () => {
    return Ajax.call([{
        methodname: 'local_reportlms_connect',
        args: {},
    }])[0];
};

/**
 * Send the visitor to the people behind a figure they clicked.
 *
 * The filters are codes, which this site can read and Report LMS cannot, so the
 * list of people is assembled here from local data and never leaves.
 *
 * @param {string} peopleurl
 * @param {Object} filters
 */
const drilldown = (peopleurl, filters) => {
    const url = new URL(peopleurl, window.location.origin);

    ['node', 'course', 'fn'].forEach((key) => {
        if (typeof filters[key] === 'string' && filters[key] !== '') {
            url.searchParams.set(key, filters[key]);
        }
    });

    window.location.assign(url.toString());
};

/**
 * Start listening.
 *
 * @param {string} origin Report LMS origin, scheme and host only.
 * @param {string} frameselector
 * @param {string} peopleurl Where a drill through lands.
 */
export const init = (origin, frameselector, peopleurl) => {
    const frame = document.querySelector(frameselector);

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

        if (message.type === DRILLDOWN) {
            drilldown(peopleurl, message.filters || {});
            return;
        }

        if (message.type === ACKNOWLEDGE) {
            acknowledge().then((result) => {
                if (result.connected) {
                    // Reading it was the last thing in the way, so the steps
                    // have nothing left to say. This happens on a site that
                    // connected before the statement existed.
                    window.location.reload();

                    return true;
                }

                // Targeted at the frame's origin, never '*'.
                event.source.postMessage({type: ACKNOWLEDGED, ok: true}, origin);

                return true;
            }).catch((error) => {
                // Tell the frame it failed, so it can leave the step open
                // rather than showing a tick for something nothing recorded.
                //
                // Carry the reason across. Swallowing it left the frame with
                // nothing to say and nothing to log, which is how a broken web
                // service turns into a button that simply never finishes.
                event.source.postMessage({
                    type: ACKNOWLEDGED,
                    ok: false,
                    error: String((error && (error.message || error.errorcode)) || error),
                }, origin);
            });

            return;
        }

        if (message.type === CONNECT) {
            connect().then((result) => {
                if (result.connected) {
                    // The dashboard replaces the steps, so there is nothing to
                    // tell the frame: this page is about to be a different one.
                    // It comes back with welcome=1, which is how the report
                    // knows to say hello rather than just appearing.
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
            // Setup finished while somebody was watching the steps. Reload, and
            // the dashboard is simply there, with nothing to press.
            window.location.reload();
            return;
        }

        if (message.type === LEARNERS && Array.isArray(message.codes)) {
            const wanted = message.codes.filter((code) => typeof code === 'string');

            learners(wanted).then((response) => {
                const names = {};

                response.learners.forEach((entry) => {
                    names[entry.code] = entry.name;
                });

                // Targeted at the frame's origin, never '*'. These are people's
                // names: a wildcard would hand them to whatever happens to be
                // framing this page.
                event.source.postMessage({
                    type: LEARNER_NAMES,
                    requestId: message.requestId,
                    names: names,
                }, origin);

                return true;
            }).catch(() => {
                // Answer with nothing rather than not answering. The caller is
                // waiting on a timeout, and a list showing coded ids is worth
                // more than one that never finishes loading.
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

        resolve(codes).then((labels) => {
            // Targeted at the frame's origin, never '*': a wildcard would post
            // the organisation's structure to whatever happens to be there if
            // the frame navigated away mid-request.
            event.source.postMessage({
                type: LABELS,
                requestId: message.requestId,
                labels: labels,
            }, origin);

            return labels;
        }).catch(() => {
            // A resolver failure is not worth breaking the page over. The
            // dashboard falls back to showing the raw codes after its timeout.
            event.source.postMessage({
                type: LABELS,
                requestId: message.requestId,
                labels: {},
            }, origin);
        });
    });
};
