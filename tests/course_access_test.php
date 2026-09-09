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
 * Course-scoped report access: the mode switch and the report-page producer.
 *
 * The setting is off by default and the first tests here are the ones that say
 * so — a site that has not opted in must behave exactly as it did before this
 * feature existed, which means not making the extra call at all.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_access_test extends \advanced_testcase {
    /** @var string Institutional token; a personal one has no members to grant to. */
    const TOKEN = 'G-group-token';

    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
    }

    /**
     * Nothing is scoped until an administrator says so.
     */
    public function test_institution_mode_is_the_default(): void {
        $this->resetAfterTest(true);
        $configmodel = new \plagiarism_pchkorg_config_model();

        $this->assertSame(
            \plagiarism_pchkorg_course_access::MODE_INSTITUTION,
            \plagiarism_pchkorg_course_access::mode($configmodel)
        );
        $this->assertFalse(\plagiarism_pchkorg_course_access::is_course_scoped($configmodel));
    }

    /**
     * Only an explicit '1' turns it on. Anything else is the old behaviour.
     */
    public function test_only_an_explicit_yes_enables_course_mode(): void {
        $this->resetAfterTest(true);
        $configmodel = new \plagiarism_pchkorg_config_model();

        foreach (['0', '', 'yes'] as $value) {
            $configmodel->set_system_config('pchkorg_course_access', $value);
            $this->assertFalse(
                \plagiarism_pchkorg_course_access::is_course_scoped($configmodel),
                sprintf('Value %s must not enable course scoping.', var_export($value, true))
            );
        }

        $configmodel->set_system_config('pchkorg_course_access', '1');
        $this->assertTrue(\plagiarism_pchkorg_course_access::is_course_scoped($configmodel));
    }

    /**
     * The role the auto-registration task sends follows the mode: teacher on an
     * institution-wide site, student on a course-scoped one, where the grants
     * are what carry the teaching rights instead.
     */
    public function test_registration_role_follows_the_mode(): void {
        $this->resetAfterTest(true);
        $configmodel = new \plagiarism_pchkorg_config_model();

        $this->assertSame(2, \plagiarism_pchkorg_course_access::registration_role($configmodel));

        $configmodel->set_system_config('pchkorg_course_access', '1');

        $this->assertSame(3, \plagiarism_pchkorg_course_access::registration_role($configmodel));
    }

    /**
     * An institution-wide site makes no grant call at all. This is the test
     * that says the feature costs an unconverted site nothing.
     */
    public function test_no_grant_is_issued_in_institution_mode(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course();
        $this->setUser($data->teacher);
        $transport = $this->transport([json_encode(['success' => true])]);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($transport),
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertTrue($allowed);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A teacher on a course-scoped site is granted the course they are opening
     * a report in, with the right course, role and hashed email.
     */
    public function test_grant_is_issued_for_a_teacher_in_course_mode(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->teacher);
        $transport = $this->transport([json_encode(['success' => true])]);
        $provider = $this->provider($transport);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $provider,
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertTrue($allowed);
        $this->assertSame(1, $transport->request_count());

        $request = $transport->request(0);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://service.example/lms/moodle/course-role/', $request['url']);
        $this->assertSame(self::TOKEN, $request['params']['token']);
        $this->assertSame($data->course->id, $request['params']['course_id']);
        $this->assertSame(2, $request['params']['role']);
        $this->assertSame(
            $provider->user_email_to_hash($data->teacher->email),
            $request['params']['hash']
        );
    }

    /**
     * The raw email must not leave Moodle here, any more than it does anywhere
     * else the plugin identifies a member.
     */
    public function test_grant_never_sends_the_raw_email(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->teacher);
        $transport = $this->transport([json_encode(['success' => true])]);

        \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($transport),
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertStringNotContainsString(
            $data->teacher->email,
            json_encode($transport->request(0)['params'])
        );
    }

    /**
     * A student needs no grant: they reach their own report as its author, and
     * granting them one would hand them the whole course.
     */
    public function test_no_grant_is_issued_for_a_student_in_course_mode(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->student);
        $transport = $this->transport([json_encode(['success' => true])]);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($transport),
            $data->context,
            $data->course->id,
            $data->student->email,
            $data->configmodel
        );

        $this->assertTrue($allowed);
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A refused grant stops the report page. Handing the teacher onward would
     * land them on a service-side refusal they cannot interpret.
     */
    public function test_a_refused_grant_stops_the_report(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->teacher);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($this->transport([json_encode(['success' => false])])),
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertFalse($allowed);
    }

    /**
     * An unreachable service is a refusal too. The fake transport returns ''
     * for an unqueued request, which is what Moodle's curl wrapper returns on
     * a timeout.
     */
    public function test_an_unreachable_service_stops_the_report(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->teacher);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($this->transport([])),
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertFalse($allowed);
    }

    /**
     * A body that parses but carries no verdict is not a yes.
     */
    public function test_an_unparseable_response_stops_the_report(): void {
        $this->resetAfterTest(true);
        $data = $this->setup_course(true);
        $this->setUser($data->teacher);

        $allowed = \plagiarism_pchkorg_course_access::ensure_report_access(
            $this->provider($this->transport(['<html>gateway timeout</html>'])),
            $data->context,
            $data->course->id,
            $data->teacher->email,
            $data->configmodel
        );

        $this->assertFalse($allowed);
    }

    /**
     * A personal token has no members, so there is nobody to grant anything to
     * and no call to make.
     */
    public function test_a_personal_token_issues_no_grant(): void {
        $this->resetAfterTest(true);
        $transport = $this->transport([json_encode(['success' => true])]);
        $provider = new \plagiarism_pchkorg_api_provider(
            'personal-token',
            'https://service.example',
            $transport
        );

        $this->assertFalse($provider->grant_course_role('teacher@example.com', 42, 2));
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * Build a fake transport.
     *
     * @param array $responses
     * @return \plagiarism_pchkorg_fake_transport
     */
    private function transport(array $responses = []) {
        return new \plagiarism_pchkorg_fake_transport($responses);
    }

    /**
     * Build a provider wired to the fake transport, on an institutional token.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($transport) {
        return new \plagiarism_pchkorg_api_provider(self::TOKEN, 'https://service.example', $transport);
    }

    /**
     * A course with an assignment, an enrolled teacher and student, and the
     * plugin configured.
     *
     * @param bool $coursescoped Whether to turn course scoping on.
     * @return \stdClass
     */
    private function setup_course($coursescoped = false) {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->set_system_config('pchkorg_use', '1');
        $configmodel->set_system_config('pchkorg_token', self::TOKEN);
        if ($coursescoped) {
            $configmodel->set_system_config('pchkorg_course_access', '1');
        }

        \plagiarism_pchkorg_config_model::reset_caches();

        return (object) [
            'course' => $course,
            'cm' => $cm,
            'context' => \context_module::instance($cm->id),
            'teacher' => $teacher,
            'student' => $student,
            'configmodel' => $configmodel,
        ];
    }
}
