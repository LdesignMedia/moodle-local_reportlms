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
 * Mints the short-lived token that lets this site embed the dashboard.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * Builds the embed URL for the person looking at the page.
 *
 * The token is the whole authorisation envelope. Report LMS has no user
 * accounts, so what this says about the viewer is exactly what they get to
 * see, which is why the scope is decided here, inside Moodle, where the
 * organisation structure actually lives.
 */
class launch {
    /** @var int Seconds a token stays valid. It only has to survive one page load. */
    public const TTL = 300;

    /**
     * The URL to put in the iframe, signed for the current viewer.
     *
     * @param bool $welcome Whether to greet the viewer, just after connecting.
     *
     * @return string
     */
    public static function embed_url(bool $welcome = false): string {
        $environmentid = (string) get_config('local_reportlms', 'environmentid');
        // The separator is passed explicitly. Moodle sets PHP's
        // arg_separator.output to &amp;, so the default would return entities,
        // html_writer would escape them again, and the parameter would arrive
        // named "amp;token".
        return config::baseurl() . config::embedpath() . '?' . http_build_query([
            'env' => $environmentid,
            'token' => self::mint(),
            // The iframe shares no cookies with the SaaS, so the viewer's
            // language travels with the launch. The SaaS normalises pack
            // codes (nl_kids) to the primary subtag itself.
            'lang' => current_language(),
            // Said inside the frame rather than as a Moodle notification above
            // it: the report is what the administrator just switched on, so
            // the confirmation belongs on the report, not in a bar over it.
            'welcome' => $welcome ? 1 : 0,
        ], '', '&');
    }

    /**
     * Build and sign the token.
     *
     * @return string
     */
    protected static function mint(): string {
        global $CFG;

        $viewer = self::viewer();
        $now = time();

        $claims = [
            'iss' => $CFG->wwwroot,
            'env' => (string) get_config('local_reportlms', 'environmentid'),
            'sub' => $viewer['sub'],
            'role' => $viewer['role'],
            'scope' => $viewer['scope'],
            // Absent for an unscoped viewer, which is how the SaaS tells "every
            // course" from "these courses and no others".
            'courses' => $viewer['courses'] ?? null,
            /*
             * When this site's cron last ran, and when the push task last ran.
             *
             * Carried on the token rather than on the push, because a site
             * whose cron is dead cannot use the push to report that its cron is
             * dead. The token is minted whenever somebody opens the report,
             * which is exactly the moment the answer becomes interesting.
             *
             * Timestamps about a server, not about a person: the same class of
             * thing as the plugin version, so nothing about the privacy
             * position changes.
             */
            'cron_last_run_at' => cron_health::cron_last_run(),
            'push_task_last_run_at' => cron_health::push_last_run(),
            // Whether this viewer may configure the site-wide card deck. Always
            // present, never omitted: the SaaS defaults an absent claim to
            // false for older plugins, and an explicit false keeps that default
            // from doing the deciding.
            'configure' => access::can_configure(),
            /*
             * Whether the dashboard may offer "Export people (Excel)": names,
             * e-mail addresses and phone numbers. The name is older than the
             * export; it was given for the copy button the export replaced.
             *
             * The same rule the lookup service applies, from the same method,
             * so the button is only shown to somebody whose click the service
             * will answer. Always present, like configure: an older plugin
             * sends nothing, the SaaS reads that as false, and so the button
             * cannot appear on a site that has no service to call.
             *
             * A yes or no about the viewer, not an address. Nothing about the
             * people in the report travels on the token.
             */
            'copy_emails' => access::can_copy_emails(),
            'iat' => $now,
            'exp' => $now + self::TTL,
            // One-time id. A launch URL ends up in browser history, in referrer
            // headers and in any proxy log on the way, so it must not be
            // reusable once it has been spent.
            'jti' => random_string(32),
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $input = self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims));

        // Signed with the launch secret, never the push signing secret. This
        // one travels through a browser, so a leak must not also grant the
        // ability to push data.
        $secret = (string) get_config('local_reportlms', 'launchsecret');
        $signature = self::b64(hash_hmac('sha256', $input, $secret, true));

        return $input . '.' . $signature;
    }

    /**
     * Who is looking, and what they are allowed to see.
     *
     * For now every viewer is treated as the site administrator with the whole
     * organisation in scope, which is why view.php only lets administrators in
     * at all. Widening the page before this method is finished would hand every
     * viewer the entire organisation.
     *
     * TODO: derive the role and scope from the org tree, so a manager's token
     * carries only the nodes they manage and the SaaS scopes every query to
     * that subtree. Depends on the manager definition.
     *
     * @return array{sub: string, role: string, scope: array}
     */
    protected static function viewer(): array {
        global $USER;

        // The whole organisation, for site administrators and anyone holding
        // viewall. An empty scope on the organisation role is what the contract
        // already means by "everything", so nothing special is needed to say it.
        if (access::can_view_all()) {
            return [
                'sub' => pseudonym::learner((int) $USER->id),
                'role' => 'organisation',
                'scope' => [],
            ];
        }

        /*
         * Otherwise the viewer is a manager, and their scope is the units they
         * were named against. An empty scope here means nothing rather than
         * everything, which is why the two cases are kept apart above.
         *
         * No course list rides along any more. It used to, worked out from the
         * courses the viewer held :view on, so that the Report LMS side could
         * refuse a cell for a training they had nothing to do with. Naming a
         * manager says which people are theirs, not which trainings, and their
         * unit is already the boundary.
         */
        return [
            'sub' => pseudonym::learner((int) $USER->id),
            'role' => 'department',
            'scope' => self::named_nodes(),
            'courses' => null,
        ];
    }

    /**
     * The coded org nodes the current viewer may see, subtree inclusive.
     *
     * Public because the people list and the export need the same answer the
     * token carries. Null means unscoped, which only reaches here for someone
     * who is not a manager at all, since can_view_all is checked first.
     *
     * @return array<int, string>|null
     */
    public static function viewer_scope(): ?array {
        $named = self::named_nodes();

        return $named ?: null;
    }

    /**
     * The coded nodes whose names this viewer may be told.
     *
     * Wider and weaker than the scope, and the difference is load-bearing.
     *
     * A manager is served their own unit's numbers and no other, and is still
     * told the *name* of the sector above it, because a department drawn under
     * an unnamed parent is a picker nobody can use. Knowing what a sector is
     * called is not permission to read it.
     *
     * These were briefly the same method. A sector code in the token is not a
     * label, it is a grant: Report LMS expands every scope node to its subtree
     * before serving anything, so naming the parent handed the manager every
     * department in it. Seen live, on a manager of one department who could
     * suddenly read the whole sector.
     *
     * @return array<int, string>|null Null for a viewer who manages nothing.
     */
    public static function viewer_nameable(): ?array {
        $named = self::named_nodes();

        if (!$named) {
            return null;
        }

        return array_values(array_unique(array_merge($named, self::parent_node_codes())));
    }

    /**
     * The coded units directly above the ones this viewer manages.
     *
     * Names only, never scope. See {@see viewer_nameable}.
     *
     * The parent's own kind, not an assumed one. This read `'sector'` outright,
     * from when a parent could only be a sector, and a code is built from the
     * kind and the name together, so once a department could sit under another
     * department, the code minted for such a parent matched nothing the SaaS
     * held. It failed silently, the way a wrong hash does: the parent was simply
     * never nameable, with nothing logged and nothing to see but a code.
     *
     * Sites still have sectors, so this is not the removal of a concept; it is
     * the difference between reading the parent's kind and guessing it.
     *
     * @return array<int, string>
     */
    protected static function parent_node_codes(): array {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return [];
        }

        $nodes = mapping::nodes();
        $codes = [];

        foreach (mapping::managed_nodes((int) $USER->id) as $nodeid) {
            $parentid = $nodes[$nodeid]->parentid ?? null;

            if ($parentid === null || !isset($nodes[$parentid])) {
                continue;
            }

            $parent = $nodes[$parentid];

            // A node with no kind is not guessed at either: an unnameable
            // parent is a cosmetic loss, and a wrong code is a silent one.
            if (($parent->kind ?? '') === '') {
                continue;
            }

            $codes[] = pseudonym::node($parent->kind, $parent->name);
        }

        return $codes;
    }

    /**
     * The coded units this viewer was named the manager of. Exactly those.
     *
     * The whole of a manager's scope, and nothing above it. Report LMS expands
     * each of these to its subtree, so naming a sector here would hand over
     * every department inside it: that is right when somebody was named against
     * the sector, and a leak when the sector is merely the parent of the one
     * department they run.
     *
     * Holding local/reportlms:view on a course puts nothing here either. It
     * lets somebody open a report, and what they see in it is the units they
     * were named against, which may be none.
     *
     * @return array<int, string>
     */
    protected static function named_nodes(): array {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return [];
        }

        $codes = [];

        foreach (mapping::managed_nodes((int) $USER->id) as $nodeid) {
            $key = mapping::nodekey($nodeid);

            if ($key === null) {
                continue;
            }

            [$level, $value] = explode(':', $key, 2);
            $codes[] = pseudonym::node($level, $value);
        }

        return array_values(array_unique($codes));
    }

    /**
     * Base64url, as JWT uses.
     *
     * @param string $raw
     * @return string
     */
    protected static function b64(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
