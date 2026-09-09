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
     * Exactly the extensions the service parses are offered: anything else is
     * refused on upload, and anything missing cannot be attached at all.
     */
    public function test_accepted_types_match_the_service(): void {
        $types = \plagiarism_pchkorg_ignore_template_filemanager::accepted_types();

        sort($types);
        $this->assertSame(
            ['.doc', '.docx', '.odp', '.odt', '.pdf', '.ppt', '.pptx', '.rtf', '.txt'],
            $types
        );
    }

    /**
     * The upload element carries a size cap rather than core's "Unlimited",
     * and never a cap above what the service accepts.
     */
    public function test_upload_element_caps_the_file_size(): void {
        $mform = $this->mform();

        \plagiarism_pchkorg_ignore_template_form::add_elements(
            $mform,
            null,
            $this->configmodel(),
            $this->provider()
        );

        $maxbytes = $mform->getElement(\plagiarism_pchkorg_ignore_template_form::FIELD_FILES)->getMaxbytes();

        // Core lowers the cap further to the site and PHP limits, so this is
        // an upper bound, not an equality. What matters is that it is neither
        // unlimited (-1) nor larger than the service allows.
        $this->assertGreaterThan(0, $maxbytes);
        $this->assertLessThanOrEqual(
            \plagiarism_pchkorg_ignore_template_filemanager::MAX_FILESIZE_BYTES,
            $maxbytes
        );
    }

    /**
     * An administrator may ignore Moodle's file size limits, so core prints
     * "Maximum file size: Unlimited" however the element is configured. The
     * service's limit applies to them all the same, and the rendered element
     * says so.
     */
    public function test_rendered_element_states_the_limit_to_an_administrator(): void {
        $this->setAdminUser();

        $html = $this->rendered_upload_element();

        $this->assertStringContainsString(
            \plagiarism_pchkorg_ignore_template_filemanager::max_filesize_label(),
            $html
        );
        $this->assertStringNotContainsString(get_string('unlimited'), $html);
    }

    /**
     * A site limit below the service's is stricter, and is what the teacher is
     * actually held to, so core's own figure is left in place. Overwriting it
     * with the service's larger one would invite an upload the site refuses.
     */
    public function test_rendered_element_keeps_a_stricter_site_limit(): void {
        global $CFG;

        $CFG->maxbytes = 1048576;

        $html = $this->rendered_upload_element();

        $this->assertStringContainsString(display_size(1048576, 0), $html);
        $this->assertStringNotContainsString(
            \plagiarism_pchkorg_ignore_template_filemanager::max_filesize_label(),
            $html
        );
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

        $this->assertNull($result->error);
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

        $this->assertNull($result->error);
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

        $this->assertNull($result->error);
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

        $this->assertSame('files_and_text_conflict', $result->error);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A file over the service's limit is refused here, without being posted.
     *
     * The upload element caps the size, but core lifts that cap for anyone
     * holding moodle/course:ignorefilesizelimits, so an oversized file can
     * still arrive in the draft area.
     */
    public function test_save_refuses_a_file_over_the_size_limit(): void {
        $transport = new \plagiarism_pchkorg_fake_transport();
        $draftid = $this->draft_area_with_file(
            'huge.txt',
            str_repeat('a', \plagiarism_pchkorg_ignore_template_filemanager::MAX_FILESIZE_BYTES + 1)
        );

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_FILES => $draftid,
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame('file_too_large', $result->error);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A file exactly on the limit is accepted: the service refuses what is
     * larger than the limit, not what equals it.
     */
    public function test_save_accepts_a_file_on_the_size_limit(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);
        $draftid = $this->draft_area_with_file(
            'exact.txt',
            str_repeat('a', \plagiarism_pchkorg_ignore_template_filemanager::MAX_FILESIZE_BYTES)
        );

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_FILES => $draftid,
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result->error);
        $this->assertSame(1, $transport->request_count());
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

        $this->assertNull($result->error);
        $params = $transport->request()['params'];
        $this->assertSame('The wording students repeat.', $params['template_text']);
        $this->assertSame(
            \plagiarism_pchkorg_assignment_key::for_cmid($this->cm->id),
            $params['assignment_key']
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

        $this->assertNull($result->error);
        $params = $transport->request()['params'];
        $this->assertInstanceOf('CURLFile', $params['templates[0]']);
        $this->assertSame('rubric.txt', $params['templates[0]']->getPostFilename());
        $this->assertSame(
            'Marking criteria in full.',
            file_get_contents($params['templates[0]']->getFilename())
        );

        // Uploaded from the draft area's own pool file. A save can carry
        // several 25 MB templates, so reading them into strings first would
        // cost their combined size before the first byte is sent.
        $draft = $this->only_draft_file($draftid);
        $this->assertSame(
            get_file_storage()->get_file_system()->get_local_path_from_storedfile($draft, true),
            $params['templates[0]']->getFilename()
        );
    }

    /**
     * The single file in a draft area.
     *
     * @param int $draftitemid
     * @return \stored_file
     */
    private function only_draft_file($draftitemid) {
        global $USER;

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        return reset($files);
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

        $this->assertNull($result->error);
        $params = $transport->request()['params'];
        $this->assertSame(7, $params['delete[0]']);
        $this->assertSame(11, $params['delete[1]']);
        $this->assertArrayNotHasKey('delete[2]', $params);
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

        $this->assertNull($result->error);
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

        $this->assertSame('template_limit_exceeded', $result->error);
    }

    /**
     * Attaching a template queues this activity's finished checks, so their
     * scores are read again under the templates as they now stand.
     */
    public function test_save_queues_finished_checks_under_the_new_templates(): void {
        $checked = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => 'The wording students repeat.',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result->error);
        $this->assertSame(1, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_SENT, $this->state_of($checked));
    }

    /**
     * Deleting a template queues them too: a report taken while the template
     * applied is as wrong as one taken before it existed.
     */
    public function test_save_queues_finished_checks_when_a_template_is_deleted(): void {
        $checked = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);
        $prefix = \plagiarism_pchkorg_ignore_template_form::FIELD_DELETE;

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([$prefix . '7' => 1]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertNull($result->error);
        $this->assertSame(1, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_SENT, $this->state_of($checked));
    }

    /**
     * A save that left the templates alone queues nothing. Every activity save
     * reaches this code, and most of them have nothing to do with templates.
     */
    public function test_a_save_that_touched_no_template_queues_nothing(): void {
        $checked = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $transport = new \plagiarism_pchkorg_fake_transport();

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame(0, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_CHECKED, $this->state_of($checked));
    }

    /**
     * A save the service refused changed no templates, so the stored reports
     * still match the ones in force and must be left on screen.
     */
    public function test_a_refused_save_queues_nothing(): void {
        $checked = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_CHECKED);
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

        $this->assertSame('template_limit_exceeded', $result->error);
        $this->assertSame(0, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_CHECKED, $this->state_of($checked));
    }

    /**
     * Only finished checks are queued. A submission still working its way
     * towards a result would be dragged backwards by a move to LOCAL_SENT, and
     * one that failed has no report to bring into line.
     */
    public function test_unfinished_submissions_are_left_where_they_are(): void {
        $queued = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_QUEUED);
        $failed = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_ERROR);
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => 'The wording students repeat.',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame(0, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_QUEUED, $this->state_of($queued));
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_ERROR, $this->state_of($failed));
    }

    /**
     * Templates belong to one activity, and so does the queueing they cause.
     */
    public function test_another_activity_is_not_queued(): void {
        $other = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $othercm = get_coursemodule_from_instance('assign', $other->id);

        $mine = $this->submission_record(\plagiarism_pchkorg_state::LOCAL_CHECKED);
        $theirs = $this->submission_record(
            \plagiarism_pchkorg_state::LOCAL_CHECKED,
            ['cm' => $othercm->id]
        );
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success()]);

        $result = \plagiarism_pchkorg_ignore_template_form::save(
            $this->submission([
                \plagiarism_pchkorg_ignore_template_form::FIELD_TEXT => 'The wording students repeat.',
            ]),
            $this->configmodel(),
            $this->provider($transport)
        );

        $this->assertSame(1, $result->requeued);
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_SENT, $this->state_of($mine));
        $this->assertSame(\plagiarism_pchkorg_state::LOCAL_CHECKED, $this->state_of($theirs));
    }

    /**
     * The message names the template change that caused the queueing, and reads
     * for a single submission as well as for several.
     */
    public function test_the_requeue_message_names_its_cause_and_counts(): void {
        $one = \plagiarism_pchkorg_ignore_template_form::requeue_message(1);
        $many = \plagiarism_pchkorg_ignore_template_form::requeue_message(12);

        $this->assertStringContainsString('Templates updated', $one);
        $this->assertStringContainsString('1 submission ', $one);
        $this->assertStringContainsString('12 submissions', $many);
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
     * A row in the plugin's own submission table, as a check leaves behind.
     *
     * @param int $state One of the plagiarism_pchkorg_state LOCAL_ constants.
     * @param array $fields Overrides for the defaults.
     * @return int Id of the new record.
     */
    private function submission_record($state, array $fields = []) {
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
     * The stored state of a submission row.
     *
     * @param int $id
     * @return int
     */
    private function state_of($id) {
        global $DB;

        return (int) $DB->get_field('plagiarism_pchkorg_files', 'state', ['id' => $id]);
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
     * The upload element as it reaches a teacher's browser.
     *
     * @return string HTML.
     */
    private function rendered_upload_element() {
        global $PAGE;

        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_context(\context_module::instance($this->cm->id));

        $mform = $this->mform();
        \plagiarism_pchkorg_ignore_template_form::add_elements(
            $mform,
            null,
            $this->configmodel(),
            $this->provider()
        );

        $element = $mform->getElement(\plagiarism_pchkorg_ignore_template_form::FIELD_FILES);
        // A rendered form assigns this; nothing here has rendered one.
        $element->updateAttributes(['id' => 'id_' . $element->getName()]);

        return $element->toHtml();
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
}
