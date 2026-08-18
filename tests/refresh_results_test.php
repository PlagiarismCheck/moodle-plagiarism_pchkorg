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
 * Tests for re-queueing finished checks.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/refresh_results.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/refresh_results_form.php');

/**
 * Moving finished checks back into the poll queue.
 *
 * Nothing here contacts the service: refreshing is purely a change of queue
 * state, and the poll which follows it is covered by state_transition_test.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_results_test extends \advanced_testcase {
    /** @var \stdClass Course the activities under test live in. */
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

        $this->set_site_config('pchkorg_use', '1');
        $this->set_module_config('pchkorg_module_use', '1');
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * The point of the feature: a finished check goes back to waiting, so the
     * scheduled poll reads its scores from the service again.
     */
    public function test_checked_records_are_queued_again(): void {
        global $DB;

        $id = $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $this->assertSame(1, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_SENT,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * Every finished check in the activity, not just the first.
     */
    public function test_all_checked_records_are_queued(): void {
        global $DB;

        for ($i = 0; $i < 3; $i++) {
            $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);
        }

        $this->assertSame(3, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
        $this->assertSame(3, $DB->count_records('plagiarism_pchkorg_files', [
            'cm' => $this->cm->id,
            'state' => \plagiarism_pchkorg_state::LOCAL_SENT,
        ]));
    }

    /**
     * Records still moving through the queue are left alone. Re-queueing one
     * that has not finished would either duplicate work already scheduled or,
     * for an errored record, revive a submission deliberately given up on.
     *
     * The cases are looped rather than supplied by a data provider: the
     * dataProvider annotation is deprecated in PHPUnit 11 and removed in 12,
     * while its replacement attribute needs PHP 8.0 and does not exist in the
     * PHPUnit 7 shipped with Moodle 3.9.
     */
    public function test_unfinished_records_are_left_alone(): void {
        global $DB;

        $untouched = [
            'queued to be sent' => \plagiarism_pchkorg_state::LOCAL_QUEUED,
            'already waiting for a result' => \plagiarism_pchkorg_state::LOCAL_SENT,
            'failed' => \plagiarism_pchkorg_state::LOCAL_ERROR,
        ];

        foreach ($untouched as $label => $state) {
            $id = $this->submission(['state' => $state]);

            $this->assertSame(0, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id), $label);
            $this->assertEquals(
                $state,
                $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id]),
                $label
            );

            $DB->delete_records('plagiarism_pchkorg_files', ['id' => $id]);
        }
    }

    /**
     * The button acts on one activity. A teacher refreshing their assignment
     * must not re-queue every check on the site.
     */
    public function test_other_activities_are_left_alone(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $othercm = get_coursemodule_from_instance('assign', $other->id);

        $mine = $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);
        $theirs = $this->submission([
            'state' => \plagiarism_pchkorg_state::LOCAL_CHECKED,
            'cm' => $othercm->id,
        ]);

        $this->assertSame(1, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));

        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_SENT,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $mine])
        );
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $theirs])
        );
    }

    /**
     * A record with no text id cannot be asked about, and re-queueing it would
     * strand it at LOCAL_SENT for good, since the poll would never resolve it.
     */
    public function test_records_without_a_text_id_are_skipped(): void {
        global $DB;

        $id = $this->submission([
            'state' => \plagiarism_pchkorg_state::LOCAL_CHECKED,
            'textid' => null,
        ]);

        $this->assertSame(0, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
        $this->assertEquals(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id])
        );
    }

    /**
     * The previous scores stay in the row until the poll replaces them, so a
     * refresh which never completes does not destroy what was already known.
     */
    public function test_previous_scores_survive_the_refresh(): void {
        global $DB;

        $id = $this->submission([
            'state' => \plagiarism_pchkorg_state::LOCAL_CHECKED,
            'score' => 42.5,
            'scoreai' => 7.25,
            'reportid' => 4321,
        ]);

        \plagiarism_pchkorg_refresh_results::refresh($this->cm->id);

        $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
        $this->assertEquals(42.5, (float) $record->score);
        $this->assertEquals(7.25, (float) $record->scoreai);
        $this->assertEquals(4321, $record->reportid);
    }

    /**
     * An activity whose checks have not finished yet has nothing to refresh.
     */
    public function test_nothing_to_refresh_reports_zero(): void {
        $this->assertSame(0, \plagiarism_pchkorg_refresh_results::count_refreshable($this->cm->id));
        $this->assertSame(0, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
    }

    /**
     * The count offered to the teacher matches what pressing the button does.
     */
    public function test_count_matches_what_is_queued(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_QUEUED]);

        $this->assertSame(2, \plagiarism_pchkorg_refresh_results::count_refreshable($this->cm->id));
        $this->assertSame(2, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
    }

    /**
     * Once queued, they are no longer refreshable, so a second press is a no-op
     * rather than something that would disturb the poll already under way.
     */
    public function test_refreshing_twice_queues_nothing_further(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $this->assertSame(1, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
        $this->assertSame(0, \plagiarism_pchkorg_refresh_results::refresh($this->cm->id));
    }

    /**
     * Offered while editing an activity the plugin is switched on for.
     */
    public function test_is_available_when_editing(): void {
        $this->assertTrue(
            \plagiarism_pchkorg_refresh_results::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->cm->id
            )
        );
    }

    /**
     * Not while creating an activity, which has no submissions to refresh.
     * This is the one place it differs from the ignored-templates section.
     */
    public function test_is_not_available_while_creating(): void {
        $this->assertFalse(
            \plagiarism_pchkorg_refresh_results::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                null
            )
        );
    }

    /**
     * Off when the plugin is off for the site.
     */
    public function test_is_not_available_when_the_site_setting_is_off(): void {
        $this->set_site_config_value('pchkorg_use', '0');

        $this->assertFalse(
            \plagiarism_pchkorg_refresh_results::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->cm->id
            )
        );
    }

    /**
     * Off when the plugin is off for this particular activity.
     */
    public function test_is_not_available_when_the_activity_setting_is_off(): void {
        $this->set_module_config_value('pchkorg_module_use', '0');

        $this->assertFalse(
            \plagiarism_pchkorg_refresh_results::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->cm->id
            )
        );
    }

    /**
     * A student may not refresh anyone's results, including their own.
     */
    public function test_is_not_available_without_the_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->assertFalse(
            \plagiarism_pchkorg_refresh_results::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->cm->id
            )
        );
    }

    /**
     * The message names the number queued, so the teacher can tell a refresh
     * that did something from one that found nothing.
     */
    public function test_result_message_reports_the_count(): void {
        $message = \plagiarism_pchkorg_refresh_results::result_message(3);

        $this->assertNotSame('', $message);
        $this->assertTrue(false !== strpos($message, '3'));
        $this->assertNotSame(
            \plagiarism_pchkorg_refresh_results::result_message(0),
            $message
        );
    }

    /**
     * The box starts unticked even where the form was populated from saved
     * values, so an unrelated save never refreshes anything.
     */
    public function test_the_box_starts_unticked(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $mform = $this->form();

        $this->assertEmpty($mform->getElementValue(\plagiarism_pchkorg_refresh_results_form::FIELD));
    }

    /**
     * Every string the row shows is defined. A missing one renders as
     * [[identifier]] rather than failing, so nothing else would catch it.
     */
    public function test_the_row_has_no_missing_strings(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $this->assertTrue(false === strpos($this->render(), '[['));
    }

    /**
     * The box is never disabled, including where there is nothing to refresh.
     * A control that will not respond and cannot say why reads as broken; the
     * count beside it explains the situation, and ticking it anyway answers
     * with "there were no finished checks in this activity to refresh".
     */
    public function test_the_box_is_never_disabled(): void {
        $this->assertTrue(false === strpos($this->render(), 'disabled'));

        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $this->assertTrue(false === strpos($this->render(), 'disabled'));
    }

    /**
     * The wording beside the box is a label pointing at the checkbox, so that
     * clicking the sentence ticks it. Moodle would otherwise render that text
     * as an inert description span.
     */
    public function test_the_caption_is_a_label_for_the_checkbox(): void {
        $html = $this->render();
        $id = \plagiarism_pchkorg_refresh_results_form::ELEMENT_ID;

        $this->assertTrue(false !== strpos($html, '<label'));
        $this->assertTrue(false !== strpos($html, 'for="' . $id . '"'));
        $this->assertTrue(false !== strpos($html, 'id="' . $id . '"'));
    }

    /**
     * The label is useless if it names an id no input carries, which is what
     * would happen if the field were renamed without the constant following.
     */
    public function test_the_label_target_matches_the_field_name(): void {
        $this->assertSame(
            'id_' . \plagiarism_pchkorg_refresh_results_form::FIELD,
            \plagiarism_pchkorg_refresh_results_form::ELEMENT_ID
        );
    }

    /**
     * The count is what explains an activity with nothing to fetch, so it has
     * to say so in words rather than leaving a bare zero.
     */
    public function test_the_count_states_the_empty_case(): void {
        $this->assertTrue(false !== strpos($this->count_html(), 'nothing to refresh'));
    }

    /**
     * One finished check is not "1 submissions".
     */
    public function test_the_count_reads_correctly_for_one(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $html = $this->count_html();

        $this->assertTrue(false !== strpos($html, '1 submission in this activity'));
        $this->assertTrue(false === strpos($html, '1 submissions'));
    }

    /**
     * And several are counted.
     */
    public function test_the_count_reads_correctly_for_several(): void {
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);
        $this->submission(['state' => \plagiarism_pchkorg_state::LOCAL_CHECKED]);

        $this->assertTrue(false !== strpos($this->count_html(), '2 submissions'));
    }

    /**
     * Build the form with the checkbox on it.
     *
     * @return \MoodleQuickForm
     */
    private function form() {
        $mform = new \MoodleQuickForm('testform', 'post', 'index.php');
        \plagiarism_pchkorg_refresh_results_form::add_elements($mform, $this->cm->id);

        return $mform;
    }

    /**
     * Build the row and return the checkbox markup.
     *
     * @return string HTML.
     */
    private function render() {
        return $this->form()
            ->getElement(\plagiarism_pchkorg_refresh_results_form::FIELD)
            ->toHtml();
    }

    /**
     * Build the row and return the markup of the count beside the checkbox.
     *
     * @return string HTML.
     */
    private function count_html() {
        return $this->form()
            ->getElement(\plagiarism_pchkorg_refresh_results_form::FIELD . '_count')
            ->toHtml();
    }

    /**
     * Create a submission row for the activity under test.
     *
     * @param array $fields Overrides for the defaults.
     * @return int Id of the new record.
     */
    private function submission(array $fields) {
        global $DB;

        $record = (object) array_merge([
            'cm' => $this->cm->id,
            'userid' => $this->teacher->id,
            'fileid' => null,
            'itemid' => 1,
            'signature' => sha1('content' . uniqid('', true)),
            'textid' => 9999,
            'state' => \plagiarism_pchkorg_state::LOCAL_CHECKED,
            'score' => 0,
            'attempt' => 0,
            'created_at' => time(),
        ], $fields);

        return $DB->insert_record('plagiarism_pchkorg_files', $record);
    }

    /**
     * A config model with no stale reads.
     *
     * @return \plagiarism_pchkorg_config_model
     */
    private function configmodel() {
        \plagiarism_pchkorg_config_model::reset_caches();

        return new \plagiarism_pchkorg_config_model();
    }

    /**
     * Set a site-wide setting.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_site_config($name, $value) {
        $this->set_config(0, $name, $value);
    }

    /**
     * Set a setting for the activity under test.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_module_config($name, $value) {
        $this->set_config($this->cm->id, $name, $value);
    }

    /**
     * Change a site-wide setting already present.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_site_config_value($name, $value) {
        $this->set_config_value(0, $name, $value);
    }

    /**
     * Change a setting for the activity under test.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    private function set_module_config_value($name, $value) {
        $this->set_config_value($this->cm->id, $name, $value);
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
