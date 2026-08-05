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

        if (message.type === CONNECTED) {
            // Setup finished while somebody was watching the steps. Reload, and
            // the dashboard is simply there, with nothing to press.
            window.location.reload();
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
