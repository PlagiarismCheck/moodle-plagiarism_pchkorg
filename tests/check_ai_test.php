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
 * Tests for the AI detection setting.
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
require_once(__DIR__ . '/fixtures/fake_form_wrapper.php');

/**
 * Where the activity's "Enable AI Detector" value comes from, and that it survives a save.
 *
 * The setting used to be applied in Moodle alone: it hid an AI score the
 * service had produced anyway. It now travels with the submission, so what an
 * activity is offered and what it stores decide whether the check is run at
 * all. Whether the stored value reaches the service is sender_test's job.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_ai_test extends \advanced_testcase {
    /** @var \stdClass Course the activity under test lives in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /**
     * A course, an assignment, an editing teacher and the plugin switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \plagiarism_pchkorg_config_model::reset_caches();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->set_config(0, 'pchkorg_use', '1');
        $this->set_config($this->cm->id, 'pchkorg_module_use', '1');
    }

    /**
     * With no site-wide setting saved, an activity is offered AI detection on.
     * Every site behaved this way before the setting existed, and installing
     * the plugin release must not switch anything off by itself.
     */
    public function test_the_activity_form_defaults_to_on(): void {
        $this->assertSame('1', $this->offered_default());
    }

    /**
     * The site-wide setting is what a new activity is offered.
     */
    public function test_the_activity_form_follows_the_site_setting(): void {
        $this->set_config(0, 'pchkorg_check_ai', '0');

        $this->assertSame('0', $this->offered_default());
    }

    /**
     * And a site that has explicitly said yes is offered yes.
     */
    public function test_a_site_setting_of_one_is_offered(): void {
        $this->set_config(0, 'pchkorg_check_ai', '1');

        $this->assertSame('1', $this->offered_default());
    }

    /**
     * Switching AI detection off for the activity is stored. 0 is a teacher
     * saying this activity is not to be checked for AI, which the sender has
     * to be able to tell apart from the activity having said nothing.
     */
    public function test_switching_it_off_is_stored(): void {
        $this->save(0);

        $this->assertSame('0', $this->stored());
    }

    /**
     * Switching it back on is stored too.
     */
    public function test_switching_it_back_on_is_stored(): void {
        $this->save(0);
        $this->save(1);

        $this->assertSame('1', $this->stored());
    }

    /**
     * A save that leaves the field out entirely reads as off rather than
     * wiping the record, which is what the shared settings loop does for every
     * two-state setting.
     */
    public function test_an_absent_field_is_stored_as_off(): void {
        $this->save(1);

        $data = $this->formdata();
        unset($data->pchkorg_check_ai);
        plagiarism_pchkorg_coursemodule_edit_post_actions($data, $this->course);

        $this->assertSame('0', $this->stored());
    }

    /**
     * The default the activity form offers for the setting.
     *
     * @return string|null Value offered, or null when the element was not added.
     */
    private function offered_default() {
        \plagiarism_pchkorg_config_model::reset_caches();

        $mform = new \MoodleQuickForm('testform', 'post', 'index.php');
        plagiarism_pchkorg_coursemodule_standard_elements(
            new \plagiarism_pchkorg_fake_form_wrapper($this->course),
            $mform
        );

        if (!$mform->elementExists('pchkorg_check_ai')) {
            return null;
        }

        // A select reports its value as the list of selected options.
        $value = $mform->getElement('pchkorg_check_ai')->getValue();

        return is_array($value) ? (string) reset($value) : (string) $value;
    }

    /**
     * Save the activity form with a given AI detection value.
     *
     * @param mixed $checkai Value posted for the field.
     * @return void
     */
    private function save($checkai) {
        plagiarism_pchkorg_coursemodule_edit_post_actions(
            $this->formdata(['pchkorg_check_ai' => $checkai]),
            $this->course
        );
    }

    /**
     * Submitted form data carrying the plugin's settings as the real form does.
     *
     * They matter: a field absent from the data is read as 0, so a sparse
     * payload would switch the plugin off for the activity as a side effect.
     *
     * @param array $fields Overrides.
     * @return \stdClass
     */
    private function formdata(array $fields = []) {
        return (object) array_merge([
            'coursemodule' => $this->cm->id,
            'pchkorg_module_use' => 1,
            'pchkorg_student_can_see_widget' => 1,
            'pchkorg_student_can_see_report' => 1,
            'pchkorg_check_ai' => 1,
            'pchkorg_min_percent' => '',
        ], $fields);
    }

    /**
     * The stored AI detection value for the activity.
     *
     * @return string|null Value stored, or null when there is no record.
     */
    private function stored() {
        global $DB;

        $value = $DB->get_field('plagiarism_pchkorg_config', 'value', [
            'cm' => $this->cm->id,
            'name' => 'pchkorg_check_ai',
        ]);

        return false === $value ? null : $value;
    }

    /**
     * Insert a config row.
     *
     * @param int $cm Course module id, or 0 for a site-wide setting.
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_config($cm, $name, $value) {
        global $DB;

        $record = new \stdClass();
        $record->cm = $cm;
        $record->name = $name;
        $record->value = $value;
        $DB->insert_record('plagiarism_pchkorg_config', $record);

        \plagiarism_pchkorg_config_model::reset_caches();
    }
}
