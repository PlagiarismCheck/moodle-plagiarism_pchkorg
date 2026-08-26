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
 * Tests for which hook Moodle applies a saved activity form through.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');

/**
 * A saved activity form must reach this plugin through exactly one hook.
 *
 * Moodle 3.9 to 4.1 apply a save through two: course/modlib.php calls the
 * deprecated plagiarism_save_form_elements(), and then
 * plugin_extend_coursemodule_edit_post_actions(). A plugin offering both is
 * run twice for one save, which the settings survive and the instructions do
 * not -- most visibly the ignored templates, whose second posting the service
 * refuses as a duplicate of the one the first posting has just attached.
 *
 * Nothing in a single Moodle can catch that: the tests run on one version, and
 * the version they run on decides whether the deprecated call exists at all.
 * What is checked here instead is the condition core itself tests before making
 * that call, which is the same on every version in the supported range.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_hook_test extends \basic_testcase {
    /**
     * The plugin does not implement the deprecated save hook.
     *
     * Written as the reflection test plagiarism_save_form_elements() performs
     * on 3.9 to 4.1, where a method declared by the plugin class rather than
     * inherited is both applied and reported as deprecated. Inheriting core's
     * empty one, or having no such method at all from 4.2 on, leaves the
     * deprecated call with nothing to do.
     */
    public function test_the_deprecated_save_hook_is_not_implemented(): void {
        $class = \plagiarism_plugin_pchkorg::class;

        $declared = method_exists($class, 'save_form_elements')
            && (new \ReflectionMethod($class, 'save_form_elements'))
            ->getDeclaringClass()->getName() === $class;

        $this->assertFalse(
            $declared,
            $class . ' must not declare save_form_elements(): Moodle 3.9 to 4.1 would then'
            . ' apply every activity save twice, re-posting the ignored templates of the save'
            . ' before it. plagiarism_pchkorg_coursemodule_edit_post_actions() is the one hook.'
        );
    }

    /**
     * And the hook it is supposed to arrive through is there.
     *
     * The other side of the same contract: with the deprecated method gone,
     * losing this callback would leave a saved form with no hook at all, and
     * the plugin's activity settings would silently stop being saved.
     */
    public function test_the_current_save_hook_is_present(): void {
        $this->assertTrue(function_exists('plagiarism_pchkorg_coursemodule_edit_post_actions'));
    }
}
