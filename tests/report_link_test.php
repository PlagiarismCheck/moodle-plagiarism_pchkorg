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
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');

/**
 * Tests that the service credential never reaches the browser.
 *
 * The widget used to embed the API token in every submission's JSON payload,
 * including for viewers who were not allowed to open a report at all. These
 * tests pin the replacement behaviour.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_link_test extends \advanced_testcase {
    /**
     * Configuration is cached for the life of the request, which outlives the
     * per-test database reset, so it must be cleared explicitly.
     */
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
    }

    /** @var string Site API token used throughout. Personal, so no HTTP happens. */
    const TOKEN = 'personal-test-token';

    /** @var string Submitted online text. */
    const CONTENT = 'a submitted essay';

    /**
     * A teacher gets a link into this plugin, never the raw credential.
     */
    public function test_teacher_link_points_at_the_plugin(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_submission();

        $this->setUser($data->teacher);
        $output = $this->render($data);

        $this->assertStringContainsString('pchkorg', $output);
        $this->assertStringContainsString('report.php', $output);
        $this->assertStringContainsString('sesskey', $output);
    }

    /**
     * The token must not appear in rendered output for anyone.
     */
    public function test_token_never_appears_in_output(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_submission();

        foreach ([$data->teacher, $data->student] as $user) {
            $this->setUser($user);
            $this->assertStringNotContainsString(self::TOKEN, $this->render($data));
        }
    }

    /**
     * A student allowed to see the score but not the report gets no link.
     */
    public function test_student_without_report_permission_gets_no_link(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_submission(['pchkorg_student_can_see_report' => '0']);

        $this->setUser($data->student);
        $output = $this->render($data);

        $this->assertStringNotContainsString('report.php', $output);
    }

    /**
     * A student allowed to open reports does get one.
     */
    public function test_student_with_report_permission_gets_a_link(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_submission(['pchkorg_student_can_see_report' => '1']);

        $this->setUser($data->student);
        $output = $this->render($data);

        $this->assertStringContainsString('pchkorg', $output);
        $this->assertStringContainsString('report.php', $output);
    }

    /**
     * Build a course with an assignment, an enrolled teacher and student, plugin
     * configuration, and one checked submission belonging to the student.
     *
     * @param array $moduleconfig Extra per-activity settings.
     * @return \stdClass
     */
    private function setup_submission(array $moduleconfig = []) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->set_site_config('pchkorg_use', '1');
        $this->set_site_config('pchkorg_token', self::TOKEN);

        $config = array_merge(
            [
                'pchkorg_module_use' => '1',
                'pchkorg_student_can_see_widget' => '1',
                'pchkorg_student_can_see_report' => '1',
            ],
            $moduleconfig
        );
        foreach ($config as $name => $value) {
            $record = new \stdClass();
            $record->cm = $cm->id;
            $record->name = $name;
            $record->value = $value;
            $DB->insert_record('plagiarism_pchkorg_config', $record);
        }

        $filerecord = new \stdClass();
        $filerecord->cm = $cm->id;
        $filerecord->userid = $student->id;
        $filerecord->fileid = null;
        $filerecord->itemid = 0;
        $filerecord->signature = sha1(self::CONTENT);
        $filerecord->textid = 9999;
        $filerecord->state = \plagiarism_pchkorg_state::LOCAL_CHECKED;
        $filerecord->score = 12;
        $filerecord->attempt = 0;
        $filerecord->reportid = 4321;
        $filerecord->created_at = time();
        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);

        // Creating the course module runs plugin hooks which read and therefore
        // cache configuration before the rows above existed.
        \plagiarism_pchkorg_config_model::reset_caches();

        $data = new \stdClass();
        $data->cm = $cm;
        $data->teacher = $teacher;
        $data->student = $student;

        return $data;
    }

    /**
     * Set site config.
     *
     * @param string $name
     * @param string $value
     */
    private function set_site_config($name, $value) {
        global $DB;

        $record = new \stdClass();
        $record->cm = 0;
        $record->name = $name;
        $record->value = $value;
        $DB->insert_record('plagiarism_pchkorg_config', $record);
    }

    /**
     * Render the widget for the current user.
     *
     * @param \stdClass $data
     * @return string
     */
    private function render($data) {
        global $PAGE;

        // get_links() returns only the placeholder span; the per-submission
        // payload, including the report URL, is queued as page JavaScript. Use
        // a fresh page each time so the queued code can be read back more than
        // once in a single test.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_module::instance($data->cm->id));
        $PAGE->set_url('/mod/assign/view.php', ['id' => $data->cm->id]);

        $plugin = new \plagiarism_plugin_pchkorg();
        $markup = $plugin->get_links([
            'cmid' => $data->cm->id,
            'userid' => $data->student->id,
            'content' => self::CONTENT,
        ]);

        return $markup . $PAGE->requires->get_end_code();
    }
}
