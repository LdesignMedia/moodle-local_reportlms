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
 * Turns Moodle ids into the coded identifiers the contract carries.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

/**
 * The one place a Moodle id becomes a pseudonym.
 *
 * Every prefix and every source is fixed by the contract, and the SaaS refuses
 * anything that is not a coded identifier, so this exists to make it impossible
 * to get the format wrong in one caller and right in another.
 *
 * The salt never leaves this site. Without it the SaaS cannot reverse any of
 * these back to a person, which is the whole basis of the privacy model.
 */
class pseudonym {
    /**
     * A learner or a manager, from user.id.
     *
     * @param int $userid
     * @return string
     */
    public static function learner(int $userid): string {
        return self::code('p', 'learner', $userid);
    }

    /**
     * A course, from course.id.
     *
     * @param int $courseid
     * @return string
     */
    public static function course(int $courseid): string {
        return self::code('c', 'course', $courseid);
    }

    /**
     * An activity, from course_modules.id.
     *
     * The course module id, not the module instance id: quiz id 5 and course
     * module id 5 are different rows, and picking the wrong one still produces
     * valid looking hashes while reporting the wrong thing.
     *
     * @param int $cmid
     * @return string
     */
    public static function activity(int $cmid): string {
        return self::code('a', 'activity', $cmid);
    }

    /**
     * An organisation node, from the profile field value at a level.
     *
     * @param string $level
     * @param string $value
     * @return string
     */
    public static function node(string $level, string $value): string {
        return self::code('n', 'org', $level . ':' . $value);
    }

    /**
     * A function, from the function profile field value.
     *
     * @param string $value
     * @return string
     */
    public static function functionref(string $value): string {
        return self::code('f', 'function', $value);
    }

    /**
     * Hash one id.
     *
     * Truncated to 32 hex characters. The full 64 carries no more meaning here
     * and only makes every payload larger, while 32 hex is still far beyond
     * anything brute forceable without the salt.
     *
     * @param string $prefix
     * @param string $kind
     * @param string|int $id
     * @return string
     */
    protected static function code(string $prefix, string $kind, $id): string {
        $salt = (string) get_config('local_reportlms', 'salt');

        if ($salt === '') {
            throw new \moodle_exception('error:nosalt', 'local_reportlms');
        }

        return $prefix . '_' . substr(hash_hmac('sha256', $kind . ':' . $id, $salt), 0, 32);
    }
}
