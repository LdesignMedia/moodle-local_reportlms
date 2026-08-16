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
 * Publishing the organisation back into user profile fields.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Copies each person's unit into a profile field, so the site agrees with itself.
 *
 * The organisation lives in the mapping tables and nowhere else, which leaves
 * Moodle's own user profile knowing nothing about it. A site then has two
 * answers to "which department is Anna in", and staff have to know which screen
 * to trust: the customer's Moodle reports, their exports and every profile page
 * disagree with the dashboard.
 *
 * So the resolved unit is published. Two levels, because a unit's identity is a
 * path: somebody in `Operations/Rotterdam` gets `Operations` in one field and
 * `Rotterdam` in the other. Writing only the leaf would throw away the half
 * that makes "HR under Transport" a different group of people from "HR under
 * Office".
 *
 * This is the one place the plugin writes into data a customer can see, so the
 * direction is worth stating plainly: the mapping editor is the source, the
 * profile field is a copy, and the copy is overwritten on every sync. Anything
 * written into it by anybody else is replaced.
 *
 * By default it publishes into `rlms_sector` and `rlms_department`, which the
 * plugin creates hidden. Nobody can see them, nobody can edit them, and nothing
 * else reads them, so the default carries no risk at all. An administrator who
 * would rather publish into a field their own site maintains re-points either
 * level in the advanced settings, and that is the case {@see follow_locks}
 * exists for.
 */
class writeback {
    /** @var string Config key holding the field the sector is published into. */
    public const SETTING_SECTOR = 'writeback_sector';

    /** @var string Config key holding the field the department is published into. */
    public const SETTING_DEPARTMENT = 'writeback_department';

    /** @var array<string, string> Level to the plugin's own field for it. */
    public const OWN_FIELDS = [
        'sector' => 'rlms_sector',
        'department' => 'rlms_department',
    ];

    /** @var int Users compared per query. Large enough to be few queries, small enough to hold. */
    protected const CHUNK = 500;

    /**
     * The field each level publishes into, or null where it publishes nowhere.
     *
     * Three ways a level ends up switched off, and all three are silent by
     * design: nobody chose a field, the field they chose has since been
     * deleted, or the field they chose is one this class must refuse.
     *
     * @return array{sector: string|null, department: string|null}
     */
    public static function targets(): array {
        global $DB;

        $chosen = [
            'sector' => (string) get_config('local_reportlms', self::SETTING_SECTOR),
            'department' => (string) get_config('local_reportlms', self::SETTING_DEPARTMENT),
        ];

        /*
         * An unset config is the default, not "off".
         *
         * get_config returns false for a setting nobody has saved, which is the
         * state every existing site is in the moment this ships. Reading that as
         * off would mean the feature only starts working once somebody opens the
         * settings page and presses Save, which is not what "on by default"
         * means to anybody.
         */
        foreach ($chosen as $level => $value) {
            if (get_config('local_reportlms', self::setting_for($level)) === false) {
                $chosen[$level] = self::OWN_FIELDS[$level];
            }
        }

        $functionfield = collector\org::function_field();
        $targets = ['sector' => null, 'department' => null];

        foreach ($chosen as $level => $shortname) {
            if ($shortname === '') {
                continue;
            }

            /*
             * Never the function field.
             *
             * Publishing a department into it would overwrite everybody's job
             * title, and the aggregation slices by that field, so the damage
             * would show up as a report that looks plausible and is wrong.
             * Refused here as well as in the settings page, because the setting
             * can be saved before the function mapping moves onto it.
             */
            if ($shortname === $functionfield) {
                continue;
            }

            if (!$DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }

            $targets[$level] = $shortname;
        }

        /*
         * Never the same field twice.
         *
         * The two levels would overwrite each other on every run and the value
         * would flicker between a sector and a department, which reads as the
         * plugin being broken rather than as a setting being wrong. The
         * department wins because it is the level every site has; a site with
         * no sectors still has departments.
         */
        if ($targets['sector'] !== null && $targets['sector'] === $targets['department']) {
            $targets['sector'] = null;
        }

        return $targets;
    }

    /**
     * The config key for one level.
     *
     * @param string $level sector|department.
     *
     * @return string
     */
    public static function setting_for(string $level): string {
        return $level === 'sector' ? self::SETTING_SECTOR : self::SETTING_DEPARTMENT;
    }

    /**
     * Publish every learner's unit, and return how many values changed.
     *
     * @return int
     */
    public static function apply(): int {
        global $DB;

        $targets = self::targets();

        if ($targets['sector'] === null && $targets['department'] === null) {
            return 0;
        }

        $fieldids = [];

        foreach ($targets as $level => $shortname) {
            if ($shortname !== null) {
                $fieldids[$level] = (int) $DB->get_field(
                    'user_info_field',
                    'id',
                    ['shortname' => $shortname],
                    IGNORE_MULTIPLE
                );
            }
        }

        $wanted = self::wanted_values();
        $written = 0;

        foreach ($fieldids as $level => $fieldid) {
            if (!$fieldid) {
                continue;
            }

            $written += self::publish($fieldid, array_column($wanted, $level, 'userid'));
        }

        return $written;
    }

    /**
     * What each learner's two fields should say.
     *
     * @return array<int, array{userid: int, sector: string, department: string}>
     */
    protected static function wanted_values(): array {
        global $DB;

        $nodes = mapping::nodes();
        $placed = mapping::resolved_membership();

        $wanted = [];
        $userids = $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 2');

        foreach ($userids as $userid) {
            $userid = (int) $userid;
            $sector = '';
            $department = '';
            $node = isset($placed[$userid]) ? ($nodes[$placed[$userid]] ?? null) : null;

            if ($node !== null) {
                /*
                 * The name, never the key.
                 *
                 * `department:Operations/Rotterdam` is an identifier this
                 * plugin and Report LMS agree on, and it is not a thing to show
                 * a person. Their profile says Rotterdam, and the sector above
                 * it says Operations.
                 */
                if ($node->kind === 'sector') {
                    // Placed on a sector directly, which is a real if unusual
                    // shape: a unit that has people and no departments yet.
                    $sector = $node->name;
                } else {
                    $department = $node->name;

                    if ($node->parentid !== null && isset($nodes[$node->parentid])) {
                        $sector = $nodes[$node->parentid]->name;
                    }
                }
            }

            $wanted[] = [
                'userid' => $userid,
                'sector' => $sector,
                'department' => $department,
            ];
        }

        return $wanted;
    }

    /**
     * Write one field for everybody whose stored value differs.
     *
     * **Straight to the table, never through profile_save_data().**
     *
     * The profile API raises \core\event\user_updated, which db/events.php
     * observes and turns into a dirty learner. Publishing to 13,000 people
     * through it would mark 13,000 learners dirty, forcing a push, which
     * publishes again, which marks them dirty again. The site would push
     * forever and never settle. A direct write raises nothing, and it is what
     * an HR upload does anyway.
     *
     * @param int $fieldid
     * @param array<int, string> $wanted Value keyed by user id.
     *
     * @return int Rows written.
     */
    protected static function publish(int $fieldid, array $wanted): int {
        global $DB;

        $written = 0;

        foreach (array_chunk($wanted, self::CHUNK, true) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($chunk), SQL_PARAMS_NAMED);
            $params['fieldid'] = $fieldid;

            $existing = $DB->get_records_select(
                'user_info_data',
                "fieldid = :fieldid AND userid {$insql}",
                $params,
                '',
                'userid, id, data'
            );

            foreach ($chunk as $userid => $value) {
                $row = $existing[$userid] ?? null;

                if ($row === null) {
                    // Nothing stored and nothing to store. Writing an empty row
                    // for every unplaced person on the site would be thousands
                    // of rows saying the same as their absence.
                    if ($value === '') {
                        continue;
                    }

                    $DB->insert_record('user_info_data', (object) [
                        'userid' => $userid,
                        'fieldid' => $fieldid,
                        'data' => $value,
                        'dataformat' => 0,
                    ]);
                    $written++;

                    continue;
                }

                if ((string) $row->data === $value) {
                    continue;
                }

                /*
                 * Blanked rather than deleted when somebody leaves a unit.
                 *
                 * A field still naming the department they left is worse than
                 * an empty one, and deleting the row instead would be
                 * indistinguishable from never having published, which matters
                 * the day somebody asks why a value disappeared.
                 */
                $DB->set_field('user_info_data', 'data', $value, ['id' => $row->id]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * Keep a chosen field locked, and give it back when it stops being chosen.
     *
     * The plugin's own fields are hidden, so a learner cannot reach them and a
     * lock would protect nothing. A field the administrator picked is
     * different: it is visible, a learner may edit it, and the edit is silently
     * replaced on the next sync. Locking says out loud what would otherwise be
     * a confusing overwrite.
     *
     * Ownership is recorded per level, so a lock this plugin applied can be
     * undone exactly. A lock found already in place is never claimed and never
     * released: we cannot tell the customer's from ours, and
     * {@see profile_fields::ensure} creates unlocked precisely so that ours is
     * always ours.
     *
     * @return void
     */
    public static function follow_locks(): void {
        global $DB;

        $targets = self::targets();

        foreach (['sector', 'department'] as $level) {
            $configkey = 'lockedfield_' . $level;
            $ours = (string) get_config('local_reportlms', $configkey);
            $target = $targets[$level];

            // The plugin's own field is hidden, so it is never locked and never
            // recorded, whatever it was before.
            if ($target === self::OWN_FIELDS[$level]) {
                $target = null;
            }

            if ($ours !== '' && $ours !== (string) $target) {
                $old = $DB->get_record('user_info_field', ['shortname' => $ours], 'id, locked');

                if ($old && !empty($old->locked)) {
                    $DB->set_field('user_info_field', 'locked', 0, ['id' => $old->id]);
                }

                unset_config($configkey, 'local_reportlms');
            }

            if ($target === null) {
                continue;
            }

            $field = $DB->get_record('user_info_field', ['shortname' => $target], 'id, locked');

            if ($field && empty($field->locked)) {
                $DB->set_field('user_info_field', 'locked', 1, ['id' => $field->id]);
                set_config($configkey, $target, 'local_reportlms');
            }
        }
    }
}
