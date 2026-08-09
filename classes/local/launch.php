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
     * The URL to put in the iframe.
     *
     * @return string
     */
    /**
     * @param bool $welcome Whether to greet the viewer, just after connecting.
     *
     * @return string
     */
    public static function embed_url(bool $welcome = false): string {
        $environmentid = (string) get_config('local_reportlms', 'environmentid');
        $embedpath = (string) get_config('local_reportlms', 'embedpath');

        if ($embedpath === '') {
            $embedpath = config::DEFAULT_EMBED_PATH;
        }

        // The separator is passed explicitly. Moodle sets PHP's
        // arg_separator.output to &amp;, so the default would return entities,
        // html_writer would escape them again, and the parameter would arrive
        // named "amp;token".
        return config::baseurl() . $embedpath . '?' . http_build_query([
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
     * that subtree. Depends on the manager definition in 86cay1v20.
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

        // Otherwise the viewer is a manager, and their scope is the nodes their
        // own department resolves to, crossed with the courses they manage. An
        // empty scope here means nothing rather than everything, which is why
        // the two cases are kept apart above.
        $manager = manager_resolver::managers()[(int) $USER->id] ?? null;

        return [
            'sub' => pseudonym::learner((int) $USER->id),
            'role' => 'department',
            'scope' => $manager ? self::scope_of($manager) : [],
            // The courses they manage, so the SaaS can refuse a cell for a
            // training they have nothing to do with.
            'courses' => $manager ? array_map(
                static fn(int $courseid): string => pseudonym::course($courseid),
                $manager->courseids
            ) : [],
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
        global $USER;

        $manager = manager_resolver::managers()[(int) $USER->id] ?? null;

        if (!$manager) {
            return null;
        }

        $nodes = self::scope_of($manager);

        return $nodes ?: null;
    }

    /**
     * The coded nodes this viewer may have named, scope plus its ancestors.
     *
     * Kept apart from {@see viewer_scope}, which is what rides the token and
     * decides which cells the SaaS will serve. This is a wider and weaker
     * thing: which codes the label service will translate. A manager is served
     * their own department's numbers and no other, and is told the name of the
     * sector above it, because a department drawn under an unnamed parent is a
     * picker nobody can use.
     *
     * Derived from the manager record rather than by walking the label map,
     * because the map holds the tree as it currently stands and a manager whose
     * own department has no members yet is simply absent from it. A viewer must
     * not lose the ability to name their own department because nobody has
     * been put in it.
     *
     * @return array<int, string>|null Null for a viewer who manages nothing.
     */
    public static function viewer_nameable(): ?array {
        global $USER;

        $manager = manager_resolver::managers()[(int) $USER->id] ?? null;

        if (!$manager || !$manager->departments) {
            return null;
        }

        $sector = self::sector_of($manager->userid);
        $codes = [];

        foreach ($manager->departments as $department) {
            $codes[] = pseudonym::node(
                'department',
                ($sector !== null ? $sector . '/' : '') . $department
            );
        }

        if ($sector !== null) {
            $codes[] = pseudonym::node('sector', $sector);
        }

        return array_values(array_unique($codes));
    }

    /**
     * The coded org nodes a manager may see.
     *
     * Built with the same key rule the org collector uses, because the SaaS
     * matches these against the node ids that collector pushed. A department's
     * identity includes its sector, so a manager with no sector value lands on
     * a different node than one with the same department under a sector.
     *
     * @param \stdClass $manager As returned by {@see manager_resolver::managers}.
     *
     * @return array<int, string>
     */
    protected static function scope_of(\stdClass $manager): array {
        if (!$manager->departments) {
            return [];
        }

        $department = reset($manager->departments);
        $sector = self::sector_of($manager->userid);

        return [
            pseudonym::node('department', ($sector !== null ? $sector . '/' : '') . $department),
        ];
    }

    /**
     * A user's mapped sector value, or null.
     *
     * @param int $userid
     *
     * @return string|null
     */
    protected static function sector_of(int $userid): ?string {
        global $DB;

        $shortname = collector\org::fieldmap()['sector'];

        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE f.shortname = :shortname AND d.userid = :userid";

        $value = trim((string) $DB->get_field_sql($sql, ['shortname' => $shortname, 'userid' => $userid]));

        return $value === '' ? null : $value;
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
