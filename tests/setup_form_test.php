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

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/form/plagiarism_pchkorg_setup_form.php');

/**
 * Validation of the site settings form.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_form_test extends \advanced_testcase {
    /**
     * Shortnames, in any case and with any separator, are accepted.
     */
    public function test_a_valid_list_is_accepted(): void {
        $this->assertSame([], $this->validate("Tutor\nta, head-of_dept\n\n"));
    }

    /**
     * Emptying the field is how a site says it has no custom roles.
     */
    public function test_an_empty_list_is_accepted(): void {
        $this->assertSame([], $this->validate(''));
    }

    /**
     * A character no shortname can hold means the entry can never match a role.
     */
    public function test_an_invalid_shortname_is_rejected(): void {
        $errors = $this->validate("tutor\nhead of dept!");

        $this->assertArrayHasKey(\plagiarism_pchkorg_roles::CONFIG_NAME, $errors);
        $this->assertStringContainsString('dept!', $errors[\plagiarism_pchkorg_roles::CONFIG_NAME]);
    }

    /**
     * The stored value has to fit plagiarism_pchkorg_config.value.
     */
    public function test_a_list_too_long_to_store_is_rejected(): void {
        $toolong = [];
        for ($i = 0; $i < 30; $i++) {
            $toolong[] = 'customrole' . $i;
        }

        $errors = $this->validate(implode("\n", $toolong));

        $this->assertArrayHasKey(\plagiarism_pchkorg_roles::CONFIG_NAME, $errors);
    }

    /**
     * The limit applies to what is stored, not to what was typed: duplicates,
     * capitals and padding are dropped first.
     */
    public function test_the_limit_is_measured_after_normalising(): void {
        $padded = str_repeat("  TUTOR , tutor\n", 40);

        $this->assertGreaterThan(\plagiarism_pchkorg_roles::CONFIG_MAX_LENGTH, strlen($padded));
        $this->assertSame([], $this->validate($padded));
    }

    /**
     * Run the form's validation on a submission.
     *
     * @param string $roles The custom teaching roles field as typed.
     * @return array Errors keyed by element name.
     */
    private function validate($roles) {
        $form = new \plagiarism_pchkorg_setup_form(
            new \moodle_url('/plagiarism/pchkorg/settings.php')
        );

        return $form->validation([\plagiarism_pchkorg_roles::CONFIG_NAME => $roles], []);
    }
}
