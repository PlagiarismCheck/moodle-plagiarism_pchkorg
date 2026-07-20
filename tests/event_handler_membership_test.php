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
 * Tests for how event_handler() reacts to an unknown group-membership answer
 * when queuing a submission.
 *
 * Before the fix, "the service could not be reached" and "the service said
 * this user is not a member" both left $ismember false, so a transient outage
 * permanently failed the submission with LOCAL_ERROR: cron only ever retries
 * LOCAL_QUEUED records, so that record was stuck forever, even after the
 * outage cleared. These tests pin the fix: an unknown answer queues the
 * submission for a retry instead.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_handler_membership_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * An unreachable service must not permanently fail the submission: it
     * should be queued, so the send step can retry it once the outage clears.
     */
    public function test_unknown_membership_queues_submission(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$plugin, $cm, $eventdata] = $this->setup_pending_submission('unreachable-event@example.com');

        $provider = new \plagiarism_pchkorg_api_provider(
            'G-group-token',
            'https://service.example',
            new \plagiarism_pchkorg_fake_transport([]) // Empty response: unreachable.
        );

        $plugin->event_handler($eventdata, $provider);

        $filerecord = $DB->get_record('plagiarism_pchkorg_files', ['cm' => $cm->id]);
        $this->assertNotFalse($filerecord);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_QUEUED, $filerecord->state);
    }

    /**
     * A confirmed non-member is still expected to fail the submission: this
     * is existing, intentional behaviour, not the bug.
     */
    public function test_confirmed_non_member_fails_submission(): void {
        global $DB;
        $this->resetAfterTest(true);

        [$plugin, $cm, $eventdata] = $this->setup_pending_submission('confirmed-non-member-event@example.com');

        $provider = new \plagiarism_pchkorg_api_provider(
            'G-group-token',
            'https://service.example',
            new \plagiarism_pchkorg_fake_transport([json_encode([
                'success' => true,
                'is_member' => false,
                'is_auto_registration_enabled' => false,
            ])])
        );

        $plugin->event_handler($eventdata, $provider);

        $filerecord = $DB->get_record('plagiarism_pchkorg_files', ['cm' => $cm->id]);
        $this->assertNotFalse($filerecord);
        $this->assertEquals(\plagiarism_pchkorg_state::LOCAL_ERROR, $filerecord->state);
    }

    /**
     * Create a course, an assignment with the plugin enabled, and a
     * content_uploaded event ready to be queued for the enrolled user.
     *
     * @param string $email Must be unique across this whole test run; see below.
     * @return array [plagiarism_plugin_pchkorg, stdClass cm, array eventdata]
     */
    private function setup_pending_submission($email) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        // The provider's membership cache is a PHP function-static, shared by
        // every provider instance for the life of the process, not just this
        // test: the email must be unique across this whole test run, so an
        // auto-generated one is not safe to rely on.
        $user = $generator->create_user(['email' => $email]);
        $generator->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->set_system_config('pchkorg_use', '1');

        $config = new \stdClass();
        $config->cm = $cm->id;
        $config->name = 'pchkorg_module_use';
        $config->value = '1';
        $DB->insert_record('plagiarism_pchkorg_config', $config);

        \plagiarism_pchkorg_config_model::reset_caches();

        $eventdata = [
            'eventtype' => 'content_uploaded',
            'contextinstanceid' => $cm->id,
            'objectid' => 999,
            'userid' => $user->id,
            'other' => [
                'modulename' => 'assign',
                'content' => 'Submission text long enough to matter for this test.',
            ],
        ];

        return [new \plagiarism_plugin_pchkorg(), $cm, $eventdata];
    }
}
