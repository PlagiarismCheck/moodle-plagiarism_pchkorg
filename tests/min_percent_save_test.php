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
 * Tests for storing the activity's source similarity threshold.
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
 * What saving the activity form does with "Exclude sources below X% similarity".
 *
 * The setting has three states where the others have two, and the distinction
 * between them is the whole of the bug this covers: an empty field defers to
 * the site-wide threshold, while 0 turns filtering off for the activity and has
 * to survive being stored to say so. Whether the stored value then reaches the
 * service is sender_test's job.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class min_percent_save_test extends \advanced_testcase {
    /** @var \stdClass Course the activity under test lives in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /** @var \stdClass A user who may configure the plugin for the activity. */
    private $teacher;

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

        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($this->teacher);

        $this->set_config(0, 'pchkorg_use', '1');
        $this->set_config($this->cm->id, 'pchkorg_module_use', '1');
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * An ordinary threshold is stored.
     */
    public function test_a_threshold_is_stored(): void {
        $this->save(5);

        $this->assertSame('5', $this->stored());
    }

    /**
     * 0 is stored rather than discarded. It is a teacher saying this activity
     * filters nothing, which is not the same as having said nothing.
     */
    public function test_zero_is_stored(): void {
        $this->save(0);

        $this->assertSame('0', $this->stored());
    }

    /**
     * The reported bug, at the point where it starts: a threshold lowered to 0
     * used to delete the record, leaving the activity indistinguishable from
     * one that had never had a threshold at all.
     */
    public function test_lowering_a_threshold_to_zero_keeps_a_record(): void {
        $this->save(5);
        $this->save(0);

        $this->assertSame('0', $this->stored());
    }

    /**
     * An emptied field is the other of the three states: it defers to the
     * site-wide threshold, which is what having no record means.
     */
    public function test_an_emptied_field_removes_the_record(): void {
        $this->save(5);
        $this->save('');

        $this->assertNull($this->stored());
    }

    /**
     * And an empty field on an activity that never had a threshold stores
     * nothing, rather than writing a 0 that would override the site-wide value.
     */
    public function test_an_empty_field_stores_nothing(): void {
        $this->save('');

        $this->assertNull($this->stored());
    }

    /**
     * Raising and lowering between non-zero values, which was never broken.
     */
    public function test_a_threshold_can_be_changed(): void {
        $this->save(5);
        $this->save(3);

        $this->assertSame('3', $this->stored());
    }

    /**
     * Whitespace is not a threshold.
     */
    public function test_a_blank_field_removes_the_record(): void {
        $this->save(5);
        $this->save('   ');

        $this->assertNull($this->stored());
    }

    /**
     * A user who may not change the threshold gets the field disabled, and a
     * disabled field is not posted. Saving the activity for any other reason
     * must leave the stored value alone rather than read the absence as 0.
     */
    public function test_a_user_who_may_not_change_it_cannot_wipe_it(): void {
        $this->save(5);
        $this->deny_changing_the_threshold();

        $data = $this->formdata();
        unset($data->pchkorg_min_percent);
        plagiarism_pchkorg_coursemodule_edit_post_actions($data, $this->course);

        $this->assertSame('5', $this->stored());
    }

    /**
     * Nor set one by posting the field they were never shown.
     */
    public function test_a_user_who_may_not_change_it_cannot_set_it(): void {
        $this->deny_changing_the_threshold();

        $this->save(5);

        $this->assertNull($this->stored());
    }

    /**
     * Nor change one that is already stored.
     */
    public function test_a_user_who_may_not_change_it_cannot_overwrite_it(): void {
        $this->save(5);
        $this->deny_changing_the_threshold();

        $this->save(40);

        $this->assertSame('5', $this->stored());
    }

    /**
     * The other settings still save. The threshold was pulled out of the loop
     * that writes them, so this pins that nothing was left behind.
     */
    public function test_the_other_settings_are_still_saved(): void {
        global $DB;

        $this->save(5, ['pchkorg_student_can_see_report' => 0]);

        $this->assertSame('0', $DB->get_field('plagiarism_pchkorg_config', 'value', [
            'cm' => $this->cm->id,
            'name' => 'pchkorg_student_can_see_report',
        ]));
    }

    /**
     * Save the activity form with a given threshold.
     *
     * @param mixed $minpercent Value posted for the threshold field.
     * @param array $fields Overrides for the settings posted alongside it.
     * @return void
     */
    private function save($minpercent, array $fields = []) {
        plagiarism_pchkorg_coursemodule_edit_post_actions(
            $this->formdata(array_merge(['pchkorg_min_percent' => $minpercent], $fields)),
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
     * The stored threshold for the activity.
     *
     * @return string|null Value stored, or null when there is no record.
     */
    private function stored() {
        global $DB;

        $value = $DB->get_field('plagiarism_pchkorg_config', 'value', [
            'cm' => $this->cm->id,
            'name' => 'pchkorg_min_percent',
        ]);

        return false === $value ? null : $value;
    }

    /**
     * Take the threshold capability away from the acting user.
     *
     * @return void
     */
    private function deny_changing_the_threshold() {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'editingteacher']);
        assign_capability(
            \plagiarism_pchkorg\classes\permissions\capability::CHANGE_MIN_PERCENT_FILTER,
            CAP_PROHIBIT,
            $role->id,
            \context_course::instance($this->course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
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
