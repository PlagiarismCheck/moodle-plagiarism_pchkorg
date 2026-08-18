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
 * Tests for what saving the activity form does with the refresh checkbox.
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
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/refresh_results_form.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Applying the refresh checkbox when the activity settings form is saved.
 *
 * The queue changes themselves are covered by refresh_results_test. What
 * matters here is that the box is acted on exactly once, only by someone
 * entitled to, and is never stored.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_results_save_test extends \advanced_testcase {
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
     * A ticked box queues the activity's finished checks.
     */
    public function test_ticked_box_queues_the_finished_checks(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        $this->assertSame(1, \plagiarism_pchkorg_refresh_results_form::save($this->formdata(1)));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_SENT,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * An unticked box is the ordinary case: saving the activity for any other
     * reason must not disturb submissions which already have their scores.
     */
    public function test_unticked_box_changes_nothing(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        $this->assertSame(0, \plagiarism_pchkorg_refresh_results_form::save($this->formdata(0)));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * A form posted without the field at all, as an activity type the section
     * is not offered for would be.
     */
    public function test_absent_field_changes_nothing(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        $data = new \stdClass();
        $data->coursemodule = $this->cm->id;

        $this->assertSame(0, \plagiarism_pchkorg_refresh_results_form::save($data));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * The instruction is never written to the config table. A stored value
     * would re-run the refresh on every later save of the activity.
     */
    public function test_the_box_is_not_stored(): void {
        global $DB;

        $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        plagiarism_pchkorg_coursemodule_edit_post_actions($this->formdata(1), $this->course);

        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_config', [
            'cm' => $this->cm->id,
            'name' => \plagiarism_pchkorg_refresh_results_form::FIELD,
        ]));
    }

    /**
     * Saving the form is what triggers the refresh, so the whole hook is
     * exercised rather than save() alone.
     */
    public function test_post_actions_applies_the_box(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        plagiarism_pchkorg_coursemodule_edit_post_actions($this->formdata(1), $this->course);

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_SENT,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * Saving again without re-ticking does nothing further, which is the point
     * of not storing the value.
     */
    public function test_a_later_save_does_not_refresh_again(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);

        plagiarism_pchkorg_coursemodule_edit_post_actions($this->formdata(1), $this->course);
        $DB->set_field('plagiarism_pchkorg_files', 'state', \plagiarism_pchkorg_state::LOCAL_CHECKED, ['id' => $id]);

        plagiarism_pchkorg_coursemodule_edit_post_actions($this->formdata(0), $this->course);

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * A student may not refresh results by posting the field, whatever the
     * activity settings say. The box is never rendered for them.
     */
    public function test_student_cannot_refresh_by_posting_the_field(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $this->setUser($student);

        $this->assertSame(0, \plagiarism_pchkorg_refresh_results_form::save($this->formdata(1)));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * With the plugin off for the activity there is nothing to refresh into.
     */
    public function test_nothing_happens_when_the_activity_setting_is_off(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $this->set_config_value($this->cm->id, 'pchkorg_module_use', '0');

        $this->assertSame(0, \plagiarism_pchkorg_refresh_results_form::save($this->formdata(1)));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * Switching the plugin off for the activity in the same save wins over the
     * tick. The refresh is applied after the settings, so it sees the activity
     * as the teacher has just left it rather than as it was.
     */
    public function test_switching_the_plugin_off_in_the_same_save_wins(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $this->warm_config_cache();

        plagiarism_pchkorg_coursemodule_edit_post_actions(
            $this->formdata(1, ['pchkorg_module_use' => 0]),
            $this->course
        );

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * And switching it on in the same save lets the refresh through.
     *
     * Both this and the case above turn on the settings being read as the save
     * leaves them. The config model caches per request and post_actions writes
     * through $DB behind its back, so without an explicit reset these read the
     * values from before the save.
     */
    public function test_switching_the_plugin_on_in_the_same_save_allows_it(): void {
        global $DB;

        $this->set_config_value($this->cm->id, 'pchkorg_module_use', '0');
        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $this->warm_config_cache();

        plagiarism_pchkorg_coursemodule_edit_post_actions($this->formdata(1), $this->course);

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_SENT,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * Populate the config caches the way defining the form does, so that a
     * post_actions() call in a test faces the same stale caches it would in a
     * real request rather than a conveniently empty one.
     *
     * @return void
     */
    private function warm_config_cache() {
        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->get_system_config('pchkorg_use');
        $configmodel->is_enabled_for_module($this->cm->id);
    }

    /**
     * The whole point, end to end: a stored score the service has since revised
     * is replaced by the current one after a refresh and the next poll.
     */
    public function test_refreshed_record_picks_up_the_revised_score(): void {
        global $DB;

        $id = $this->submission(\plagiarism_pchkorg_state::LOCAL_CHECKED, [
            'score' => 12.0,
            'scoreai' => 1.0,
        ]);

        \plagiarism_pchkorg_refresh_results_form::save($this->formdata(1));

        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode([
                'success' => true,
                'data' => [
                    'state' => \plagiarism_pchkorg_state::REMOTE_CHECKED,
                    'report' => ['id' => 4321, 'percent' => '55.00'],
                    'ai_report' => ['processed_percent' => '9.00'],
                ],
            ]),
        ]);
        $plugin = new \plagiarism_plugin_pchkorg();
        $plugin->cron_update_reports(new \plagiarism_pchkorg_api_provider(
            'personal-token',
            'https://service.example',
            $transport
        ));

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_CHECKED, $record->state);
        $this->assertEquals(55.0, (float) $record->score);
        $this->assertEquals(9.0, (float) $record->scoreai);
    }

    /**
     * Submitted form data with the box in a given position.
     *
     * Carries the plugin's own settings as the real form does. They matter:
     * plagiarism_pchkorg_coursemodule_edit_post_actions() reads a field absent
     * from the data as 0, so a sparse payload would switch the plugin off for
     * the activity before the checkbox is ever looked at.
     *
     * @param int $ticked 1 when the box was ticked.
     * @param array $fields Overrides for the settings posted alongside it.
     * @return \stdClass
     */
    private function formdata($ticked, array $fields = []) {
        $data = (object) array_merge([
            'coursemodule' => $this->cm->id,
            'pchkorg_module_use' => 1,
            'pchkorg_student_can_see_widget' => 1,
            'pchkorg_student_can_see_report' => 1,
            'pchkorg_check_ai' => 1,
        ], $fields);
        $data->{\plagiarism_pchkorg_refresh_results_form::FIELD} = $ticked;

        return $data;
    }

    /**
     * Create a submission row for the activity under test.
     *
     * @param int $state State the record starts in.
     * @param array $fields Further overrides.
     * @return int Id of the new record.
     */
    private function submission($state, array $fields = []) {
        global $DB;

        $record = (object) array_merge([
            'cm' => $this->cm->id,
            'userid' => $this->teacher->id,
            'fileid' => null,
            'itemid' => 1,
            'signature' => sha1('content' . uniqid('', true)),
            'textid' => 9999,
            'state' => $state,
            'score' => 0,
            'attempt' => 0,
            'created_at' => time(),
        ], $fields);

        return $DB->insert_record('plagiarism_pchkorg_files', $record);
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

    /**
     * Update a config row already inserted.
     *
     * @param int $cm Course module id, or 0 for a site-wide setting.
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_config_value($cm, $name, $value) {
        global $DB;

        $record = $DB->get_record('plagiarism_pchkorg_config', ['cm' => $cm, 'name' => $name]);
        $record->value = $value;
        $DB->update_record('plagiarism_pchkorg_config', $record);

        \plagiarism_pchkorg_config_model::reset_caches();
    }
}
