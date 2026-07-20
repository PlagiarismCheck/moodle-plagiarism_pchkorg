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
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Tests for the shared send path.
 *
 * The three activity types previously had near-identical copies of this logic
 * in cron_send_submissions(). These tests pin the behaviour that was folded
 * into one place.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sender_test extends \advanced_testcase {
    /**
     * Configuration caches outlive the per-test database reset.
     */
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * Assignment online text is located and sent as plain text.
     */
    public function test_assign_online_text_is_sent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $data = $this->setup_assign('An essay about turtles.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(77)]);

        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_SENT, $result->status);
        $this->assertSame(77, $result->textid);

        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="text"', $body);
        $this->assertStringContainsString('text/plain', $body);
        $this->assertStringContainsString('-submission.txt', $body);
    }

    /**
     * A record whose text has since been deleted stays queued rather than
     * failing, because the content may reappear.
     */
    public function test_missing_online_text_is_retried(): void {
        global $DB;
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $DB->delete_records('assignsubmission_onlinetext');

        $transport = new \plagiarism_pchkorg_fake_transport();
        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_RETRY, $result->status);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A record pointing at a file that no longer exists can never succeed.
     */
    public function test_missing_file_fails_permanently(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $data->filedb->fileid = 999999;

        $transport = new \plagiarism_pchkorg_fake_transport();
        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_FAILED, $result->status);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * An activity type with no resolver cannot ever be sent.
     */
    public function test_unsupported_activity_fails_permanently(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $data->cm->modname = 'workshop';

        $transport = new \plagiarism_pchkorg_fake_transport();
        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_FAILED, $result->status);
    }

    /**
     * A rejected send stays queued so the retry counter can advance.
     */
    public function test_rejected_send_is_retried(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport(
            [json_encode(['message' => 'Group is disabled'])]
        );

        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_RETRY, $result->status);
    }

    /**
     * The per-activity threshold is forwarded, and beats the site-wide one.
     */
    public function test_module_threshold_overrides_site_threshold(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.', ['pchkorg_min_percent' => '42']);
        $this->set_site_config('pchkorg_min_percent', '7');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="source_min_percent"', $body);
        $this->assertStringContainsString('42', $body);
    }

    /**
     * Filters are looked up once per activity even across several records.
     */
    public function test_filters_are_cached_per_activity(): void {
        global $DB;
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport(
            [$this->success(1), $this->success(2)]
        );
        $sender = $this->sender($transport);

        $before = $DB->perf_get_reads();
        $sender->send($data->filedb, $data->cm, $data->user);
        $afterfirst = $DB->perf_get_reads();
        $sender->send($data->filedb, $data->cm, $data->user);
        $aftersecond = $DB->perf_get_reads();

        $this->assertLessThan(
            $afterfirst - $before,
            $aftersecond - $afterfirst,
            'second send should read less, filters are cached'
        );
    }

    /**
     * The activity key is attached to an assignment send.
     */
    public function test_activity_key_is_sent_for_assign(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assert_activity_key_sent($transport, $data->cm->id);
    }

    /**
     * And to a quiz send. The key is built from a course module id, so it is
     * not specific to assignments; ignored templates work for a quiz too.
     */
    public function test_activity_key_is_sent_for_quiz(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_quiz();
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_SENT, $result->status);
        $this->assert_activity_key_sent($transport, $data->cm->id);
    }

    /**
     * And to a forum send.
     */
    public function test_activity_key_is_sent_for_forum(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_forum('A post about turtles, at some length.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_SENT, $result->status);
        $this->assert_activity_key_sent($transport, $data->cm->id);
    }

    /**
     * The key travels even with the feature off, so that switching it on later
     * applies templates to work checked in the meantime. Only the flag changes.
     */
    public function test_activity_key_is_sent_while_the_feature_is_off(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $body = $transport->request()['params'];
        $this->assertStringContainsString(
            \plagiarism_pchkorg_assignment_key::for_cmid($data->cm->id),
            $body
        );
        $this->assertStringContainsString('name="ignore_templates_enabled"', $body);
        $this->assertStringContainsString("\r\n\r\n0\r\n", $body);
    }

    /**
     * Turning the site setting on flips the flag that travels with the key.
     */
    public function test_ignore_templates_flag_follows_the_site_setting(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $this->set_site_config('pchkorg_enable_ignore_templates', '1');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $body = $transport->request()['params'];
        $this->assertStringContainsString('name="ignore_templates_enabled"', $body);
        $this->assertStringContainsString("\r\n\r\n1\r\n", $body);
    }

    /**
     * Assert the multipart body carries the key for a course module.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @param int $cmid
     */
    private function assert_activity_key_sent($transport, $cmid) {
        $body = $transport->request()['params'];

        $this->assertStringContainsString('name="assignment_key"', $body);
        $this->assertStringContainsString(
            \plagiarism_pchkorg_assignment_key::for_cmid($cmid),
            $body
        );
    }

    /**
     * Build a course, assignment, enrolled user, online text submission and a
     * queued record pointing at it.
     *
     * @param string $text
     * @param array $moduleconfig
     * @return \stdClass
     */
    private function setup_assign($text, array $moduleconfig = []) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $submission = new \stdClass();
        $submission->assignment = $assign->id;
        $submission->userid = $user->id;
        $submission->status = 'submitted';
        $submission->attemptnumber = 0;
        $submission->latest = 1;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submissionid = $DB->insert_record('assign_submission', $submission);

        $onlinetext = new \stdClass();
        $onlinetext->assignment = $assign->id;
        $onlinetext->submission = $submissionid;
        $onlinetext->onlinetext = $text;
        $onlinetext->onlineformat = FORMAT_HTML;
        $DB->insert_record('assignsubmission_onlinetext', $onlinetext);

        foreach ($moduleconfig as $name => $value) {
            $record = new \stdClass();
            $record->cm = $cm->id;
            $record->name = $name;
            $record->value = $value;
            $DB->insert_record('plagiarism_pchkorg_config', $record);
        }

        $filedb = new \stdClass();
        $filedb->id = 1;
        $filedb->cm = $cm->id;
        $filedb->userid = $user->id;
        $filedb->fileid = null;
        $filedb->itemid = $submissionid;
        $filedb->signature = sha1($text);
        $filedb->attempt = 0;

        \plagiarism_pchkorg_config_model::reset_caches();

        $data = new \stdClass();
        $data->cm = $cm;
        $data->user = $user;
        $data->filedb = $filedb;

        return $data;
    }

    /**
     * Build a quiz whose queued record points at an attached file.
     *
     * The file path is used rather than an essay answer because it needs no
     * question usage scaffolding, and the filters under test do not depend on
     * how the content was found.
     *
     * @return \stdClass
     */
    private function setup_quiz() {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $content = 'An essay answer about turtles.';
        $file = $this->create_stored_file(\context_module::instance($cm->id)->id, 'answer.txt', $content);

        $filedb = new \stdClass();
        $filedb->id = 1;
        $filedb->cm = $cm->id;
        $filedb->userid = $user->id;
        $filedb->fileid = $file->get_id();
        $filedb->itemid = 55;
        $filedb->signature = sha1($content);
        $filedb->attempt = 0;

        \plagiarism_pchkorg_config_model::reset_caches();

        $data = new \stdClass();
        $data->cm = $cm;
        $data->user = $user;
        $data->filedb = $filedb;

        return $data;
    }

    /**
     * Build a forum with one discussion and a queued record for its post.
     *
     * @param string $text
     * @return \stdClass
     */
    private function setup_forum($text) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'message' => $text,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        $filedb = new \stdClass();
        $filedb->id = 1;
        $filedb->cm = $cm->id;
        $filedb->userid = $user->id;
        $filedb->fileid = null;
        $filedb->itemid = $post->id;
        $filedb->signature = sha1($post->message);
        $filedb->attempt = 0;

        \plagiarism_pchkorg_config_model::reset_caches();

        $data = new \stdClass();
        $data->cm = $cm;
        $data->user = $user;
        $data->filedb = $filedb;

        return $data;
    }

    /**
     * A stored file in the area a quiz attempt attachment lives in.
     *
     * The send path only ever loads it by id, so the area matters no further
     * than being a plausible one.
     *
     * @param int $contextid
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function create_stored_file($contextid, $filename, $content) {
        $record = [
            'contextid' => $contextid,
            'component' => 'question',
            'filearea' => 'response_attachments',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ];

        return get_file_storage()->create_file_from_string($record, $content);
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
     * Sender.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_sender
     */
    private function sender($transport) {
        $provider = new \plagiarism_pchkorg_api_provider(
            'personal-token',
            'https://service.example',
            $transport
        );

        return new \plagiarism_pchkorg_sender($provider, new \plagiarism_pchkorg_config_model());
    }

    /**
     * Success.
     *
     * @param int $textid
     * @return string
     */
    private function success($textid) {
        return json_encode([
            'success' => true,
            'data' => ['text' => ['id' => $textid]],
        ]);
    }
}
