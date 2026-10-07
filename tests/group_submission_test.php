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
require_once($CFG->dirroot . '/plagiarism/pchkorg/tests/fixtures/fake_transport.php');

/**
 * Tests for group (team) assignment submissions.
 *
 * Moodle stores a group submission with userid 0, and groupid the group, or 0
 * for the "Default group" of students in no group or in several. Before
 * v3.21.0 such a submission's files never resolved and were retried until they
 * failed, and only the submitting member's own files were queued at all.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_submission_test extends \advanced_testcase {
    /** @var string Personal token: membership is always granted and no HTTP happens. */
    const PERSONAL_TOKEN = 'personal-test-token';

    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
    }

    /**
     * Only a submission stored with userid 0 is a group submission.
     */
    public function test_is_group_submission(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $individual = $this->create_submission($data->individual, $data->alice->id, 0);
        $group = $this->create_submission($data->team, 0, $data->group->id);
        $defaultgroup = $this->create_submission($data->team, 0, 0);

        $this->assertFalse(\plagiarism_pchkorg_group_submission::is_group_submission($individual->id));
        $this->assertTrue(\plagiarism_pchkorg_group_submission::is_group_submission($group->id));
        $this->assertTrue(\plagiarism_pchkorg_group_submission::is_group_submission($defaultgroup->id));
        $this->assertFalse(\plagiarism_pchkorg_group_submission::is_group_submission(999999));
    }

    /**
     * A group submission resolves for whichever member uploaded, Default group included.
     */
    public function test_group_submission_resolves(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        foreach ([$data->group->id, 0] as $groupid) {
            $submission = $this->create_submission($data->team, 0, $groupid);

            $filedb = new \stdClass();
            $filedb->userid = $data->alice->id;
            $filedb->itemid = $submission->id;

            $this->assertEquals(
                $submission->id,
                \plagiarism_pchkorg_assign_resolver::assign_submission_id($filedb, $data->teamcm)
            );
        }
    }

    /**
     * A submission of another assignment does not resolve, group or not.
     */
    public function test_submission_of_another_assignment_does_not_resolve(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->individual, 0, $data->group->id);

        $filedb = new \stdClass();
        $filedb->userid = $data->alice->id;
        $filedb->itemid = $submission->id;

        $this->assertNull(\plagiarism_pchkorg_assign_resolver::assign_submission_id($filedb, $data->teamcm));
    }

    /**
     * When Alice uploads and Bob submits, Alice's file is queued, credited to Alice.
     */
    public function test_files_of_every_member_are_queued_for_the_uploader(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $alicefile = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');
        $bobfile = $this->add_file($data->teamcm, $submission->id, $data->bob->id, 'bob.txt');

        $this->setUser($data->bob);
        $this->submit($data->teamcm, $submission->id, $data->bob->id);

        $records = $DB->get_records('plagiarism_pchkorg_files', ['cm' => $data->teamcm->id], '', 'fileid, userid, state');
        $this->assertCount(2, $records);
        $this->assertEquals($data->alice->id, $records[$alicefile->get_id()]->userid);
        $this->assertEquals($data->bob->id, $records[$bobfile->get_id()]->userid);
        foreach ($records as $record) {
            $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_QUEUED, $record->state);
        }
    }

    /**
     * Every member clicking Submit still queues each file once.
     */
    public function test_each_member_submitting_queues_a_file_once(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');

        foreach ([$data->alice, $data->bob] as $member) {
            $this->setUser($member);
            $this->submit($data->teamcm, $submission->id, $member->id);
        }

        $this->assertEquals(1, $DB->count_records('plagiarism_pchkorg_files', ['cm' => $data->teamcm->id]));
    }

    /**
     * The Default group behaves as any other group.
     */
    public function test_default_group_files_are_queued_for_the_uploader(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, 0);
        $file = $this->add_file($data->teamcm, $submission->id, $data->carol->id, 'carol.txt');

        $this->setUser($data->bob);
        $this->submit($data->teamcm, $submission->id, $data->bob->id);

        $record = $DB->get_record('plagiarism_pchkorg_files', ['fileid' => $file->get_id()]);
        $this->assertNotFalse($record);
        $this->assertEquals($data->carol->id, $record->userid);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_QUEUED, $record->state);
    }

    /**
     * Group text saved by Alice and submitted by Bob is queued once, for Alice.
     */
    public function test_group_text_is_queued_once_for_whoever_saved_it(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $text = 'A group essay long enough to be worth checking for similarity.';
        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $data->team->id,
            'submission' => $submission->id,
            'onlinetext' => $text,
            'onlineformat' => FORMAT_HTML,
        ]);

        $this->setUser($data->alice);
        $this->handle([
            'eventtype' => 'content_uploaded',
            'contextinstanceid' => $data->teamcm->id,
            'objectid' => $submission->id,
            'userid' => $data->alice->id,
            'other' => ['modulename' => 'assign', 'content' => $text],
        ]);

        $this->setUser($data->bob);
        $this->submit($data->teamcm, $submission->id, $data->bob->id);

        $records = $DB->get_records('plagiarism_pchkorg_files', ['cm' => $data->teamcm->id]);
        $this->assertCount(1, $records);
        $this->assertEquals($data->alice->id, reset($records)->userid);
    }

    /**
     * Individual and group assignments side by side in one course each keep their own path.
     *
     * An individual submission still collects only the submitter's files and
     * credits them to the submitter, exactly as before group support.
     */
    public function test_individual_assignment_unchanged_beside_a_group_one(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $individual = $this->create_submission($data->individual, $data->bob->id, 0);
        $own = $this->add_file($data->individualcm, $individual->id, $data->bob->id, 'own.txt');
        // Not Bob's, so an individual submission does not pick it up.
        $this->add_file($data->individualcm, $individual->id, $data->alice->id, 'stray.txt');

        $team = $this->create_submission($data->team, 0, $data->group->id);
        $alicefile = $this->add_file($data->teamcm, $team->id, $data->alice->id, 'alice.txt');

        $this->setUser($data->bob);
        $this->submit($data->individualcm, $individual->id, $data->bob->id);
        $this->submit($data->teamcm, $team->id, $data->bob->id);

        $individualrecords = $DB->get_records('plagiarism_pchkorg_files', ['cm' => $data->individualcm->id]);
        $this->assertCount(1, $individualrecords);
        $this->assertEquals($own->get_id(), reset($individualrecords)->fileid);
        $this->assertEquals($data->bob->id, reset($individualrecords)->userid);

        $teamrecords = $DB->get_records('plagiarism_pchkorg_files', ['cm' => $data->teamcm->id]);
        $this->assertCount(1, $teamrecords);
        $this->assertEquals($alicefile->get_id(), reset($teamrecords)->fileid);
        $this->assertEquals($data->alice->id, reset($teamrecords)->userid);
    }

    /**
     * Membership is settled for the uploader, who is auto-registered, not for the member who submitted.
     */
    public function test_uploader_is_auto_registered(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $file = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');

        $transport = new \plagiarism_pchkorg_fake_transport([
            // Bob, who submits, is a member.
            json_encode(['success' => true, 'is_member' => true, 'is_auto_registration_enabled' => true]),
            // Alice, who uploaded, is not yet.
            json_encode(['success' => true, 'is_member' => false, 'is_auto_registration_enabled' => true]),
            // Registering Alice.
            json_encode(['success' => true]),
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-group-token', 'https://service.example', $transport);

        $this->setUser($data->bob);
        $this->submit($data->teamcm, $submission->id, $data->bob->id, $provider);

        $this->assertEquals(3, $transport->request_count());
        $registration = $transport->request(2);
        $this->assertStringContainsString('/lms/moodle/auto-registration/', $registration['url']);
        $this->assertSame($data->alice->email, $registration['params']['email']);
        $this->assertEquals(3, $registration['params']['role']);

        $record = $DB->get_record('plagiarism_pchkorg_files', ['fileid' => $file->get_id()]);
        $this->assertEquals($data->alice->id, $record->userid);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_QUEUED, $record->state);
    }

    /**
     * An uploader the service confirms is not a member fails the file, naming the uploader.
     */
    public function test_non_member_uploader_fails_their_file(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $file = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');

        $provider = new \plagiarism_pchkorg_api_provider(
            'G-group-token',
            'https://service.example',
            new \plagiarism_pchkorg_fake_transport([
                json_encode(['success' => true, 'is_member' => true, 'is_auto_registration_enabled' => false]),
                json_encode(['success' => true, 'is_member' => false, 'is_auto_registration_enabled' => false]),
            ])
        );

        $this->setUser($data->bob);
        $this->submit($data->teamcm, $submission->id, $data->bob->id, $provider);

        $record = $DB->get_record('plagiarism_pchkorg_files', ['fileid' => $file->get_id()]);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $record->state);
        $this->assertStringContainsString($data->alice->email, $record->message);
    }

    /**
     * The upgrade queues again only group files that failed for want of resolving.
     */
    public function test_requeue_unresolved(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $group = $this->create_submission($data->team, 0, $data->group->id);
        $defaultgroup = $this->create_submission($data->team, 0, 0);
        $individual = $this->create_submission($data->individual, $data->bob->id, 0);

        $stuck = $this->failed_record($data->teamcm, $group->id, 101);
        $stuckdefault = $this->failed_record($data->teamcm, $defaultgroup->id, 102);
        $rejected = $this->failed_record($data->teamcm, $group->id, 103, 'File is too short');
        $sent = $this->failed_record($data->teamcm, $group->id, 104, null, 555);
        $text = $this->failed_record($data->teamcm, $group->id, null);
        $individualstuck = $this->failed_record($data->individualcm, $individual->id, 105);

        $this->assertEquals(2, \plagiarism_pchkorg_group_submission::requeue_unresolved());

        foreach ([$stuck, $stuckdefault] as $id) {
            $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
            $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_QUEUED, $record->state);
            $this->assertEquals(0, $record->attempt);
        }
        foreach ([$rejected, $sent, $text, $individualstuck] as $id) {
            $record = $DB->get_record('plagiarism_pchkorg_files', ['id' => $id]);
            $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $record->state);
            $this->assertEquals(7, $record->attempt);
        }
    }

    /**
     * Every group member sees the result of a file another member uploaded.
     */
    public function test_every_member_sees_the_result(): void {
        global $DB, $PAGE;
        $this->resetAfterTest(true);
        $data = $this->setup_course(['pchkorg_student_can_see_widget' => '1', 'pchkorg_student_can_see_report' => '1']);
        $this->set_site_config('pchkorg_token', self::PERSONAL_TOKEN);

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $file = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');
        $DB->insert_record('plagiarism_pchkorg_files', (object) [
            'cm' => $data->teamcm->id,
            'fileid' => $file->get_id(),
            'userid' => $data->alice->id,
            'itemid' => $submission->id,
            'signature' => sha1($file->get_content()),
            'textid' => 9999,
            'state' => \plagiarism_pchkorg_state::LOCAL_CHECKED,
            'score' => 12,
            'attempt' => 0,
            'created_at' => time(),
        ]);
        \plagiarism_pchkorg_config_model::reset_caches();

        foreach ([$data->alice, $data->bob] as $member) {
            $this->setUser($member);
            $PAGE = new \moodle_page();
            $PAGE->set_context(\context_module::instance($data->teamcm->id));
            $PAGE->set_url('/mod/assign/view.php', ['id' => $data->teamcm->id]);

            $markup = (new \plagiarism_plugin_pchkorg())->get_links([
                'cmid' => $data->teamcm->id,
                'userid' => $member->id,
                'file' => $file,
            ]);

            $this->assertStringContainsString('report.php', $markup . $PAGE->requires->get_end_code());
        }
    }

    /**
     * The key names the site, the course module and the Moodle group.
     */
    public function test_inner_check_key_format(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $key = \plagiarism_pchkorg_group_submission::inner_check_key($data->teamcm, 7);

        $this->assertSame(
            \plagiarism_pchkorg_service_login::site_prefix() . 'cm' . $data->teamcm->id . '-g7',
            $key
        );
        // What the service accepts. Asserted with preg_match because
        // assertMatchesRegularExpression needs PHPUnit 9 and Moodle 3.9 runs 7.
        $this->assertSame(1, preg_match('/^[A-Za-z0-9._-]{1,255}$/', $key), "unexpected shape: {$key}");
    }

    /**
     * A group's file carries the group's identity, whichever member uploaded it.
     */
    public function test_group_file_payload_carries_the_inner_check_key(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $file = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');

        $payload = (new \plagiarism_pchkorg_assign_resolver())->resolve_file(
            $this->queued($submission->id, $data->alice->id, $file->get_id()),
            $data->teamcm,
            $file
        );

        $this->assertSame(
            \plagiarism_pchkorg_group_submission::inner_check_key($data->teamcm, $data->group->id),
            $payload->innercheckkey
        );
    }

    /**
     * The Default group is one group too, keyed g0.
     */
    public function test_default_group_file_payload_carries_g0(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, 0);
        $file = $this->add_file($data->teamcm, $submission->id, $data->carol->id, 'carol.txt');

        $payload = (new \plagiarism_pchkorg_assign_resolver())->resolve_file(
            $this->queued($submission->id, $data->carol->id, $file->get_id()),
            $data->teamcm,
            $file
        );

        $this->assertStringEndsWith('cm' . $data->teamcm->id . '-g0', $payload->innercheckkey);
    }

    /**
     * A group's online text carries the group's identity as its files do.
     */
    public function test_group_text_payload_carries_the_inner_check_key(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $data->team->id,
            'submission' => $submission->id,
            'onlinetext' => 'A group essay long enough to be worth checking for similarity.',
            'onlineformat' => FORMAT_HTML,
        ]);

        $payload = (new \plagiarism_pchkorg_assign_resolver())->resolve_text(
            $this->queued($submission->id, $data->alice->id, null),
            $data->teamcm
        );

        $this->assertSame(
            \plagiarism_pchkorg_group_submission::inner_check_key($data->teamcm, $data->group->id),
            $payload->innercheckkey
        );
    }

    /**
     * An individual submission has no group identity, file or text.
     */
    public function test_individual_payloads_have_no_inner_check_key(): void {
        global $DB;
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->individual, $data->bob->id, 0);
        $file = $this->add_file($data->individualcm, $submission->id, $data->bob->id, 'own.txt');
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $data->individual->id,
            'submission' => $submission->id,
            'onlinetext' => 'An individual essay long enough to be worth checking.',
            'onlineformat' => FORMAT_HTML,
        ]);
        $resolver = new \plagiarism_pchkorg_assign_resolver();

        $filepayload = $resolver->resolve_file(
            $this->queued($submission->id, $data->bob->id, $file->get_id()),
            $data->individualcm,
            $file
        );
        $textpayload = $resolver->resolve_text(
            $this->queued($submission->id, $data->bob->id, null),
            $data->individualcm
        );

        $this->assertNull($filepayload->innercheckkey);
        $this->assertNull($textpayload->innercheckkey);
    }

    /**
     * End to end: a group file reaches the service with its group's identity.
     */
    public function test_group_file_is_sent_with_the_inner_check_key(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();

        $submission = $this->create_submission($data->team, 0, $data->group->id);
        $file = $this->add_file($data->teamcm, $submission->id, $data->alice->id, 'alice.txt');

        // A group token resolves membership first, so the send is the second request.
        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode(['is_member' => true, 'is_auto_registration_enabled' => false]),
            json_encode(['success' => true, 'data' => ['text' => ['id' => 1]]]),
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-group-token', 'https://service.example', $transport);
        $sender = new \plagiarism_pchkorg_sender($provider, new \plagiarism_pchkorg_config_model());

        $result = $sender->send(
            $this->queued($submission->id, $data->alice->id, $file->get_id()),
            $data->teamcm,
            $data->alice
        );

        $this->assertSame(\plagiarism_pchkorg_sender::RESULT_SENT, $result->status);
        $this->assertSame(
            \plagiarism_pchkorg_group_submission::inner_check_key($data->teamcm, $data->group->id),
            $transport->request(1)['params']['inner_check_key']
        );
    }

    /**
     * A queue record as event_handler() stores it.
     *
     * @param int $submissionid
     * @param int $userid
     * @param int|null $fileid Null for online text.
     * @return \stdClass
     */
    private function queued($submissionid, $userid, $fileid) {
        $filedb = new \stdClass();
        $filedb->itemid = $submissionid;
        $filedb->userid = $userid;
        $filedb->fileid = $fileid;

        return $filedb;
    }

    /**
     * A course with an individual and a group assignment, both with the plugin on.
     *
     * Alice and Bob share a group; Carol is in none, so she submits to the
     * Default group.
     *
     * @param array $moduleconfig Extra per-activity settings.
     * @return \stdClass
     */
    private function setup_course(array $moduleconfig = []) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $individual = $generator->create_module('assign', ['course' => $course->id]);
        $team = $generator->create_module('assign', ['course' => $course->id, 'teamsubmission' => 1]);

        $data = new \stdClass();
        $data->individual = $individual;
        $data->team = $team;
        $data->individualcm = get_coursemodule_from_instance('assign', $individual->id);
        $data->teamcm = get_coursemodule_from_instance('assign', $team->id);

        foreach (['alice', 'bob', 'carol'] as $name) {
            $data->$name = $generator->create_user(['email' => $name . '-group-submission@example.com']);
            $generator->enrol_user($data->$name->id, $course->id, 'student');
        }
        $data->group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $data->group->id, 'userid' => $data->alice->id]);
        $generator->create_group_member(['groupid' => $data->group->id, 'userid' => $data->bob->id]);

        $this->set_site_config('pchkorg_use', '1');
        foreach ([$data->individualcm, $data->teamcm] as $cm) {
            foreach (array_merge(['pchkorg_module_use' => '1'], $moduleconfig) as $name => $value) {
                $DB->insert_record('plagiarism_pchkorg_config', (object) [
                    'cm' => $cm->id,
                    'name' => $name,
                    'value' => $value,
                ]);
            }
        }

        // Creating the course modules ran plugin hooks which cached
        // configuration before the rows above existed.
        \plagiarism_pchkorg_config_model::reset_caches();

        return $data;
    }

    /**
     * Store a submission row as mod_assign does.
     *
     * @param \stdClass $assign
     * @param int $userid 0 for a group submission.
     * @param int $groupid 0 for an individual submission or the Default group.
     * @return \stdClass
     */
    private function create_submission($assign, $userid, $groupid) {
        global $DB;

        $submission = (object) [
            'assignment' => $assign->id,
            'userid' => $userid,
            'groupid' => $groupid,
            'status' => 'submitted',
            'attemptnumber' => 0,
            'latest' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);

        return $submission;
    }

    /**
     * Attach a plain-text file to a submission, as uploaded by one user.
     *
     * @param \stdClass $cm
     * @param int $submissionid
     * @param int $userid Uploader.
     * @param string $filename
     * @return \stored_file
     */
    private function add_file($cm, $submissionid, $userid, $filename) {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submissionid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
            'mimetype' => 'text/plain',
        ], 'Contents of ' . $filename . ', long enough to be worth checking.');
    }

    /**
     * Fire assessable_submitted for a submission.
     *
     * @param \stdClass $cm
     * @param int $submissionid
     * @param int $userid Who clicked Submit.
     * @param \plagiarism_pchkorg_api_provider|null $provider
     */
    private function submit($cm, $submissionid, $userid, $provider = null) {
        $this->handle([
            'eventtype' => 'assessable_submitted',
            'contextinstanceid' => $cm->id,
            'objectid' => $submissionid,
            'userid' => $userid,
            'other' => ['modulename' => 'assign'],
        ], $provider);
    }

    /**
     * Run the event handler.
     *
     * @param array $eventdata
     * @param \plagiarism_pchkorg_api_provider|null $provider Personal token by default.
     */
    private function handle(array $eventdata, $provider = null) {
        if (null === $provider) {
            $provider = new \plagiarism_pchkorg_api_provider(
                self::PERSONAL_TOKEN,
                'https://service.example',
                new \plagiarism_pchkorg_fake_transport([])
            );
        }
        (new \plagiarism_plugin_pchkorg())->event_handler($eventdata, $provider);
    }

    /**
     * Insert a failed queue record.
     *
     * @param \stdClass $cm
     * @param int $submissionid
     * @param int|null $fileid Null for online text.
     * @param string|null $message The service's reason, when it gave one.
     * @param int|null $textid Set once the service had accepted it.
     * @return int Record id.
     */
    private function failed_record($cm, $submissionid, $fileid, $message = null, $textid = null) {
        global $DB;

        return $DB->insert_record('plagiarism_pchkorg_files', (object) [
            'cm' => $cm->id,
            'fileid' => $fileid,
            'userid' => 0,
            'itemid' => $submissionid,
            'signature' => sha1('x' . $fileid),
            'textid' => $textid,
            'message' => $message,
            'state' => \plagiarism_pchkorg_state::LOCAL_ERROR,
            'score' => 0,
            'attempt' => 7,
            'created_at' => time(),
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

        $DB->insert_record('plagiarism_pchkorg_config', (object) ['cm' => 0, 'name' => $name, 'value' => $value]);
    }
}
