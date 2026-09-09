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
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
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

        $params = $transport->request()['params'];
        $this->assertInstanceOf('CURLFile', $params['text']);
        $this->assertSame('text/plain', $params['text']->getMimeType());
        $this->assertStringContainsString('-submission.txt', $params['text']->getPostFilename());
    }

    /**
     * A real file is uploaded from the file pool, not copied through a PHP
     * string and a second temporary file on the way out.
     *
     * Asserted against the pool's own path because that is the only visible
     * difference: reading the document into memory would still produce a
     * working request, just one that costs the size of the document per record
     * processed, which is exactly the regression that would go unnoticed.
     */
    public function test_file_submission_uploads_from_the_file_pool(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_quiz();
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(88)]);

        $result = $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_SENT, $result->status);

        $file = get_file_storage()->get_file_by_id($data->filedb->fileid);
        $poolpath = get_file_storage()
            ->get_file_system()
            ->get_local_path_from_storedfile($file, true);

        $params = $transport->request()['params'];
        $this->assertInstanceOf('CURLFile', $params['text']);
        $this->assertSame($poolpath, $params['text']->getFilename());
        $this->assertSame('answer.txt', $params['text']->getPostFilename());
        $this->assertSame(
            'An essay answer about turtles.',
            file_get_contents($params['text']->getFilename())
        );
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

        $this->assertSame('42', $this->sent_min_percent($transport));
    }

    /**
     * A threshold of 0 is a teacher turning filtering off for the activity, so
     * it beats the site-wide value rather than being read as "unset".
     *
     * This is the case the setting was reported broken for: 0 used to leave the
     * field out of the request, and the service went on filtering by whatever
     * it had last been told.
     */
    public function test_zero_module_threshold_overrides_site_threshold(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.', ['pchkorg_min_percent' => '0']);
        $this->set_site_config('pchkorg_min_percent', '7');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('0', $this->sent_min_percent($transport));
    }

    /**
     * An activity with no threshold of its own defers to the site-wide one.
     */
    public function test_site_threshold_is_used_without_a_module_one(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $this->set_site_config('pchkorg_min_percent', '7');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('7', $this->sent_min_percent($transport));
    }

    /**
     * With no threshold anywhere the field still travels, as 0. Omitting it
     * would leave a threshold the service was told about earlier in force for
     * every later submission, which is what made clearing one look ignored.
     */
    public function test_threshold_is_sent_even_when_nothing_is_configured(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('0', $this->sent_min_percent($transport));
    }

    /**
     * The source_min_percent value in a multipart body.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return string|null Value sent, or null when the field was left out.
     */
    private function sent_min_percent($transport) {
        return $this->sent_filter($transport, 'source_min_percent');
    }

    /**
     * One filter's value as sent.
     *
     * Values are cast to string because that is what they become on the wire:
     * curl stringifies the form fields, so a test asserting against 1 rather
     * than '1' would be asserting about the PHP value and not the request.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @param string $name Filter name as it goes over the wire.
     * @return string|null Value sent, or null when the field was left out.
     */
    private function sent_filter($transport, $name) {
        $params = $transport->request()['params'];

        if (!array_key_exists($name, $params)) {
            return null;
        }

        return (string) $params[$name];
    }

    /**
     * With nothing configured anywhere, AI detection is asked for. This is what
     * every site did before the setting reached the service, and taking the
     * plugin's release must not quietly turn it off.
     */
    public function test_ai_detection_is_requested_by_default(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('1', $this->sent_filter($transport, 'enable_ai_detection'));
    }

    /**
     * A teacher switching AI detection off for the activity is told to the
     * service, which is the whole point: it used to be applied in Moodle only,
     * hiding a result the service had already been paid to produce.
     */
    public function test_ai_detection_off_for_activity_is_sent(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.', ['pchkorg_check_ai' => '0']);
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('0', $this->sent_filter($transport, 'enable_ai_detection'));
    }

    /**
     * An activity with no setting of its own -- one saved before the setting
     * existed -- defers to the site-wide one.
     */
    public function test_site_ai_setting_is_used_without_an_activity_one(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $this->set_site_config('pchkorg_check_ai', '0');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('0', $this->sent_filter($transport, 'enable_ai_detection'));
    }

    /**
     * The activity's own setting beats the site-wide one in both directions,
     * so a teacher can switch AI detection on where the site defaults it off.
     */
    public function test_activity_ai_setting_overrides_the_site_one(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.', ['pchkorg_check_ai' => '1']);
        $this->set_site_config('pchkorg_check_ai', '0');
        \plagiarism_pchkorg_config_model::reset_caches();

        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $this->assertSame('1', $this->sent_filter($transport, 'enable_ai_detection'));
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

        $params = $transport->request()['params'];
        $this->assertSame(
            \plagiarism_pchkorg_assignment_key::for_cmid($data->cm->id),
            $params['assignment_key']
        );
        $this->assertSame('0', (string) $params['ignore_templates_enabled']);
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

        $params = $transport->request()['params'];
        $this->assertSame('1', (string) $params['ignore_templates_enabled']);
    }

    /**
     * The site's Moodle and plugin releases travel with every submission, so
     * the service can see which versions are still in use.
     */
    public function test_versions_are_sent(): void {
        global $CFG;
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([$this->success(1)]);
        $this->sender($transport)->send($data->filedb, $data->cm, $data->user);

        $params = $transport->request()['params'];

        // Asserted against the running Moodle rather than a literal, so the
        // test does not need editing on every Moodle upgrade.
        $version = \plagiarism_pchkorg_site_version::moodle_major();
        $this->assertSame($version, (string) $params['moodle_version']);

        // Asserted with preg_match because assertMatchesRegularExpression needs
        // PHPUnit 9, and Moodle 3.9 ships 7.
        $this->assertSame(1, preg_match('/^\d+\.\d+$/', $version), "unexpected shape: {$version}");

        $plugin = new \stdClass();
        require($CFG->dirroot . '/plagiarism/pchkorg/version.php');
        $this->assertSame($plugin->release, (string) $params['plugin_version']);
    }

    /**
     * Assert the request carries the key for a course module.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @param int $cmid
     */
    private function assert_activity_key_sent($transport, $cmid) {
        $params = $transport->request()['params'];

        $this->assertArrayHasKey('assignment_key', $params);
        $this->assertSame(
            \plagiarism_pchkorg_assignment_key::for_cmid($cmid),
            $params['assignment_key']
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
     * On a course-scoped site a submission is attributed to the username.
     *
     * The author hash and the acting credential are both built from the
     * identity, and a submission hashed under a string the service has no
     * member for is attributed to nobody. This pins that both carry the
     * namespaced login rather than the address.
     */
    public function test_course_mode_attributes_the_submission_to_the_login(): void {
        $this->resetAfterTest(true);

        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->set_system_config('pchkorg_course_access', '1');

        $data = $this->setup_assign('Some text.');
        // A group token resolves membership over the network, so the send is
        // the second request, not the first.
        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode(['is_member' => true, 'is_auto_registration_enabled' => false]),
            $this->success(1),
        ]);
        $provider = new \plagiarism_pchkorg_api_provider(
            'G-group-token',
            'https://service.example',
            $transport
        );
        $sender = new \plagiarism_pchkorg_sender($provider, $configmodel);

        $sender->send($data->filedb, $data->cm, $data->user);

        $login = \plagiarism_pchkorg_service_login::namespaced($data->user);
        $this->assertNotSame($login, $data->user->email);

        // The membership lookup asked about the login too.
        $this->assertSame(
            $provider->user_email_to_hash($login),
            $transport->request(0)['params']['hash']
        );

        $sent = $transport->request(1)['params'];
        $this->assertSame($provider->user_email_to_hash($login), $sent['hash']);
        $this->assertContains(
            'X-API-TOKEN: ' . $provider->generate_api_token($login),
            $transport->request_headers(1)
        );
    }

    /**
     * An institution-wide site keeps attributing by address, byte for byte.
     */
    public function test_institution_mode_attributes_the_submission_to_the_email(): void {
        $this->resetAfterTest(true);

        $data = $this->setup_assign('Some text.');
        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode(['is_member' => true, 'is_auto_registration_enabled' => false]),
            $this->success(1),
        ]);
        $provider = new \plagiarism_pchkorg_api_provider(
            'G-group-token',
            'https://service.example',
            $transport
        );
        $sender = new \plagiarism_pchkorg_sender($provider, new \plagiarism_pchkorg_config_model());

        $sender->send($data->filedb, $data->cm, $data->user);

        $sent = $transport->request(1)['params'];
        $this->assertSame($provider->user_email_to_hash($data->user->email), $sent['hash']);
        $this->assertContains(
            'X-API-TOKEN: ' . $provider->generate_api_token($data->user->email),
            $transport->request_headers(1)
        );
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
