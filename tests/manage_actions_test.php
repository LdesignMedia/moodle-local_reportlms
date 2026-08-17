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
 * Every control on the organisation editor reaches something that acts on it.
 *
 * @package    local_reportlms
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportlms;

/**
 * The editor posts an action, the page switches on it, and those two lists have
 * to agree.
 *
 * They stopped agreeing once. A rewrite of one case took three of its
 * neighbours with it, and the page carried on offering Add manager, the unit
 * editor and the Fix link: each one posted, matched no case, fell through to
 * the redirect at the bottom, and came back looking precisely like a page that
 * had just saved. Nothing threw, nothing logged, and no test failed, because
 * every method the missing cases called still worked perfectly when called.
 *
 * So this reads the source rather than the behaviour. The tests around mapping
 * prove the actions do the right thing; what none of them could prove is that
 * anything still calls them, and that is a property of the text of the file.
 *
 * @coversNothing
 */
final class manage_actions_test extends \basic_testcase {
    /**
     * The page script, read off disk.
     *
     * @return string
     */
    private function page(): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/local/reportlms/view/manage.php');
    }

    /**
     * Every action the editor and its forms can post.
     *
     * Three ways to say it: a moodle_url parameter, a hidden input built by
     * hand, and a hidden element on a moodleform. Only quoted literals count,
     * which is what keeps the form tag's own action attribute out of this.
     *
     * @return string[]
     */
    private function actions_posted(): array {
        $sources = [$this->page()];

        foreach (glob(__DIR__ . '/../classes/form/*.php') as $form) {
            $sources[] = file_get_contents($form);
        }

        $found = [];

        foreach ($sources as $source) {
            $patterns = [
                "/'action'\s*=>\s*'([a-z]+)'/",
                "/'name'\s*=>\s*'action',\s*'value'\s*=>\s*'([a-z]+)'/",
                "/addElement\(\s*'hidden',\s*'action',\s*'([a-z]+)'/",
            ];

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                $found = array_merge($found, $matches[1]);
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * Every action the switch answers to.
     *
     * Scoped to the one switch on $action by counting braces from its opening
     * one. The page has other switches further down, over source kinds, and
     * reading their cases as well would let an unrelated `case 'course'` vouch
     * for an action nothing acts on.
     *
     * @return string[]
     */
    private function actions_handled(): array {
        $page = $this->page();

        $start = strpos($page, 'switch ($action) {');
        $this->assertNotFalse($start, 'view/manage.php no longer switches on $action.');

        $open = strpos($page, '{', $start);
        $depth = 0;
        $end = $open;

        for ($i = $open, $len = strlen($page); $i < $len; $i++) {
            if ($page[$i] === '{') {
                $depth++;
            } else if ($page[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        preg_match_all("/^\s*case '([a-z]+)':/m", substr($page, $open, $end - $open), $matches);

        $found = $matches[1];
        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * Nothing is offered that the page will quietly ignore.
     */
    public function test_every_action_the_editor_posts_is_handled(): void {
        $unhandled = array_values(array_diff($this->actions_posted(), $this->actions_handled()));

        $this->assertSame(
            [],
            $unhandled,
            'The editor posts these actions and view/manage.php has no case for them, so clicking '
            . 'them does nothing and says nothing: ' . implode(', ', $unhandled)
        );
    }

    /**
     * The manager actions by name, so losing one fails with a name on it rather
     * than an empty diff.
     */
    public function test_the_manager_actions_are_all_present(): void {
        $handled = $this->actions_handled();

        foreach (['addmanager', 'movemanager', 'namehere', 'removemanager'] as $action) {
            $this->assertContains(
                $action,
                $handled,
                "view/manage.php no longer handles '{$action}', so that control is dead."
            );
        }
    }

    /**
     * An action nobody wrote a case for is loud.
     *
     * The silence is what made the original fault expensive, so the default arm
     * is part of the fix rather than decoration on it.
     */
    public function test_an_unknown_action_is_not_swallowed(): void {
        $this->assertMatchesRegularExpression(
            '/default:\s*\n\s*throw new coding_exception/',
            $this->page(),
            'view/manage.php has no default arm, so an action with no case falls through to the '
            . 'redirect and looks like it worked.'
        );
    }
}
