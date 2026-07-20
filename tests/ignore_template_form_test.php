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
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/ignore_template_form.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * The ignored-templates section of the activity settings form.
 *
 * Every API call goes through the fake transport; nothing here reaches
 * plagiarismcheck.org.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_template_form_test extends \advanced_testcase {
    /** @var \stdClass Course the activities under test live in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /** @var \stdClass A user who may edit activities. */
    private $teacher;

    /**
     * A course, an assignment, an editing teacher and the feature switched on.
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

        $this->set_site_config('pchkorg_enable_ignore_templates', '1');
        $this->set_site_config('pchkorg_token', 'G-institutional-token');
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * The section is offered while editing an existing activity.
     */
    public function test_is_available_when_editing(): void {
        $this->assertTrue(
            \plagiarism_pchkorg_ignore_template_form::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->provider()
            )
        );
    }

    /**
     * And while creating one, where the context is the course. This is the
     * whole point of not requiring a course module id.
     */
    public function test_is_available_while_creating(): void {
        $this->assertTrue(
            \plagiarism_pchkorg_ignore_template_form::is_available(
                $this->configmodel(),
                \context_course::instance($this->course->id),
                $this->provider()
            )
        );
    }

    /**
     * Off unless the site setting says otherwise.
     */
    public function test_is_not_available_when_the_setting_is_off(): void {
        $this->set_site_config_value('pchkorg_enable_ignore_templates', '0');

        $this->assertFalse(
            \plagiarism_pchkorg_ignore_template_form::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->provider()
            )
        );
    }

    /**
     * Templates are institutional data, and a personal token has no
     * institution to attach them to.
     */
    public function test_is_not_available_on_a_personal_token(): void {
        $provider = new \plagiarism_pchkorg_api_provider(
            'personal-token',
            'https://service.example',
            new \plagiarism_pchkorg_fake_transport()
        );

        $this->assertFalse(
            \plagiarism_pchkorg_ignore_template_form::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $provider
            )
        );
    }

    /**
     * A student may not manage templates.
     */
    public function test_is_not_available_without_the_capability(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);

        $this->assertFalse(
            \plagiarism_pchkorg_ignore_template_form::is_available(
                $this->configmodel(),
                \context_module::instance($this->cm->id),
                $this->provider()
            )
        );
    }

    /**
     * Creating an activity asks the service for nothing: the key would name a
     * course module that does not exist, at the cost of a request on every
     * activity creation form on the site.
     */
    public function test_creation_form_makes_no_request(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();
        $mform = $this->mform();

        \plagiarism_pchkorg_ignore_template_form::add_elements(
            $mform,
            null,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame(0, $transport->request_count());
    }

    /**
     * The creation form offers the upload and paste fields, and nothing that
     * needs an existing template.
     */
    public function test_creation_form_offers_upload_and_paste_only(): void {
        $mform = $this->mform();

        \plagiarism_pchkorg_ignore_template_form::add_elements(
            $mform,
            null,
            $this->configmodel(),
            $this->provider()
        );

        $this->assertTrue($mform->elementExists(\plagiarism_pchkorg_ignore_template_form::FIELD_FILES));
        $this->assertTrue($mform->elementExists(\plagiarism_pchkorg_ignore_template_form::FIELD_TEXT));
        $this->assertTrue($mform->elementExists('pchkorg_ignore_template_create_note'));
        $this->assertFalse($mform->elementExists('pchkorg_ignore_template_list'));
    }

    /**
     * Editing lists what is attached, each row with a delete box.
     */
    public function test_edit_form_lists_existing_templates(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            $this->templatelist([
                ['id' => 7, 'filename' => 'rubric.docx', 'size' => 2048],
                ['id' => 9, 'filename' => 'coversheet.pdf', 'size' => 4096],
            ]),
        ]);
        $mform = $this->mform();

        \plagiarism_pchkorg_ignore_template_form::add_elements(
            $mform,
            $this->cm->id,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertTrue($mform->elementExists('pchkorg_ignore_template_delete_7'));
        $this->assertTrue($mform->elementExists('pchkorg_ignore_template_delete_9'));

        $html = $mform->getElement('pchkorg_ignore_template_delete_7')->toHtml();
        $this->assertStringContainsString('rubric.docx', $html);
        $this->assertStringContainsString('ignoretemplate.php', $html);
        $this->assertStringContainsString('id=7', $html);
    }

    /**
     * Nothing is saved for a form that did not touch the section.
     */
    public function test_save_is_a_no_op_when_nothing_was_submitted(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * Nothing is saved while the feature is off, whatever was posted.
     */
    public function test_save_does_nothing_while_the_feature_is_off(): void {
        $this->set_site_config_value('pchkorg_enable_ignore_templates', '0');
        $transport = new \plagiarism_pchkorg_fake_transport();

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => str_repeat('word ', 30),
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A form with no course module id cannot attach anything.
     */
    public function test_save_does_nothing_without_a_course_module(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();
        $data = $this->submission([
            \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => str_repeat('word ', 30),
        ]);
        unset($data->coursemodule);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $data,
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * Files and pasted text in one save are refused before any request.
     */
    public function test_save_refuses_files_and_text_together(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();
        $draftid = $this->draft_area_with_file('rubric.txt', 'Some template wording.');

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_FILES => $draftid,
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => str_repeat('word ', 30),
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame('files_and_text_conflict', $result);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * Pasted text is sent as the template text.
     */
    public function test_save_sends_pasted_text(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => 'The wording students repeat.',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="template_text"', $body);
        $this->assertStringContainsString('The wording students repeat.', $body);
        $this->assertStringContainsString(
            \plagiarism_pchkorg_assignment_key::for_cmid($this->cm->id),
            $body
        );
    }

    /**
     * A file a teacher just uploaded is read out of their draft area and sent
     * with its name and content.
     */
    public function test_save_sends_uploaded_draft_files(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);
        $draftid = $this->draft_area_with_file('rubric.txt', 'Marking criteria in full.');

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_FILES => $draftid,
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="templates[0]"', $body);
        $this->assertStringContainsString('rubric.txt', $body);
        $this->assertStringContainsString('Marking criteria in full.', $body);
    }

    /**
     * Ticked delete boxes become deletions; unticked ones do not.
     *
     * The ids are recovered from the field names by string prefix, which is the
     * kind of thing that fails silently and loses a teacher's deletions.
     */
    public function test_save_sends_only_ticked_deletions(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);
        $prefix = \plagiarism_pchkorg_ignore_template_form::FIELD_DELETE;

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                $prefix . '7' => '1',
                $prefix . '9' => '0',
                $prefix . '11' => '1',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="delete[0]"', $body);
        $this->assertStringContainsString('name="delete[1]"', $body);
        $this->assertStringNotContainsString('name="delete[2]"', $body);
        $this->assert_matches_regular_expression_compat('/name="delete\[0\]".*?\r\n\r\n7\r\n/s', $body);
        $this->assert_matches_regular_expression_compat('/name="delete\[1\]".*?\r\n\r\n11\r\n/s', $body);
    }

    /**
     * A field that only looks like a delete box is not one.
     */
    public function test_save_ignores_fields_that_carry_no_template_id(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();
        $prefix = \plagiarism_pchkorg_ignore_template_form::FIELD_DELETE;

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                $prefix . 'all' => '1',
                $prefix . '0' => '1',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result);
        $this->assertSame(0, $transport->request_count(), 'nothing identifiable was ticked');
    }

    /**
     * The service's error code is handed back for translation.
     */
    public function test_save_returns_the_service_error_code(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode(['success' => false, 'code' => 'template_limit_exceeded']),
        ]);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => 'Some wording.',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame('template_limit_exceeded', $result);
    }

    /**
     * Form data as the activity form hands it over.
     *
     * @param array $fields
     * @return \stdClass
     */
    private function submission(array $fields) {
        $data = new \stdClass();
        $data->coursemodule = $this->cm->id;
        foreach ($fields as $name => $value) {
            $data->{$name} = $value;
        }

        return $data;
    }

    /**
     * A draft area holding one file, as an upload leaves behind.
     *
     * @param string $filename
     * @param string $content
     * @return int Draft item id.
     */
    private function draft_area_with_file($filename, $content) {
        global $USER;

        $draftid = file_get_unused_draft_itemid();
        $record = [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        get_file_storage()->create_file_from_string($record, $content);

        return $draftid;
    }

    /**
     * An API provider wired to a fake transport.
     *
     * @param \plagiarism_pchkorg_fake_transport|null $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($transport = null) {
        if (null === $transport) {
            $transport = new \plagiarism_pchkorg_fake_transport();
        }

        return new \plagiarism_pchkorg_api_provider(
            'G-institutional-token',
            'https://service.example',
            $transport
        );
    }

    /**
     * Config model.
     *
     * @return \plagiarism_pchkorg_config_model
     */
    private function configmodel() {
        return new \plagiarism_pchkorg_config_model();
    }

    /**
     * A bare quick form to add the section to.
     *
     * @return \MoodleQuickForm
     */
    private function mform() {
        return new \MoodleQuickForm('testform', 'post', 'index.php');
    }

    /**
     * A successful, empty service response.
     *
     * @return string
     */
    private function success() {
        return json_encode(['success' => true, 'data' => []]);
    }

    /**
     * A template list response.
     *
     * @param array $templates
     * @return string
     */
    private function templatelist(array $templates) {
        return json_encode([
            'success' => true,
            'data' => ['templates' => $templates],
        ]);
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
     * Change a site config value already set up.
     *
     * @param string $name
     * @param string $value
     */
    private function set_site_config_value($name, $value) {
        global $DB;

        $DB->set_field('plagiarism_pchkorg_config', 'value', $value, ['cm' => 0, 'name' => $name]);
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * Regular expression assertion that works on PHPUnit 7 through 11.
     *
     * @param string $pattern
     * @param string $subject
     */
    private function assert_matches_regular_expression_compat($pattern, $subject) {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $subject);

            return;
        }

        $this->assertRegExp($pattern, $subject);
    }
}
