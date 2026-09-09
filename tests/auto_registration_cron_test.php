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
 * Tests for cron_auto_registrate_teachers(), specifically how it reacts to
 * the service being unreachable or answering with something unparseable.
 *
 * A single timeout used to be indistinguishable from a genuine "this group
 * does not have auto-registration" answer: both left is_auto_registration_enabled
 * false, which the cron then wrote into the administrator's saved setting,
 * permanently disabling the feature. These tests pin the fix: an unknown
 * answer must stop the run without touching configuration, and Moodle must be
 * able to see that the run failed.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_registration_cron_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_api_provider::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
    }

    /**
     * A response the transport could not deliver at all (curl failure, empty
     * body) must not be treated as a negative answer.
     */
    public function test_unreachable_service_does_not_disable_feature(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('unreachable-cron@example.com');
        $configmodel = $this->enable_auto_registration();

        // The fake transport returns '' for an unqueued request, which is
        // exactly what Moodle's curl wrapper returns on a timeout.
        $provider = $this->provider($this->transport([]));

        $this->expectException(\moodle_exception::class);
        try {
            $this->plugin()->cron_auto_registrate_teachers($provider);
        } finally {
            \plagiarism_pchkorg_config_model::reset_caches();
            $this->assertSame(
                '1',
                $configmodel->get_system_config('pchkorg_teacher_auto_registration'),
                'An unreachable service must not overwrite the administrator\'s setting.'
            );
        }
    }

    /**
     * A response that parses as JSON but is missing the keys the plugin
     * relies on (for example a truncated body, or an unrelated envelope)
     * must be treated the same as unreachable, not as a negative answer.
     */
    public function test_unparseable_response_does_not_disable_feature(): void {
        $this->resetAfterTest(true);

        $this->setup_course_with_teacher('unparseable-cron@example.com');
        $configmodel = $this->enable_auto_registration();

        $provider = $this->provider($this->transport([json_encode(['success' => false])]));

        $this->expectException(\moodle_exception::class);
        try {
            $this->plugin()->cron_auto_registrate_teachers($provider);
        } finally {
            \plagiarism_pchkorg_config_model::reset_caches();
            $this->assertSame('1', $configmodel->get_system_config('pchkorg_teacher_auto_registration'));
        }
    }

    /**
     * A confirmed negative answer is still expected to disable the feature:
     * this is a real, intentional product behaviour, not the bug.
     */
    public function test_confirmed_disabled_still_disables_feature(): void {
        $this->resetAfterTest(true);

        $this->setup_course_with_teacher('confirmed-disabled-cron@example.com');
        $configmodel = $this->enable_auto_registration();

        $provider = $this->provider($this->transport([json_encode([
            'success' => true,
            'is_member' => false,
            'is_auto_registration_enabled' => false,
        ])]));

        $result = $this->plugin()->cron_auto_registrate_teachers($provider);

        $this->assertFalse($result);
        \plagiarism_pchkorg_config_model::reset_caches();
        $this->assertSame('0', $configmodel->get_system_config('pchkorg_teacher_auto_registration'));
    }

    /**
     * A confirmed enabled answer registers the teacher and leaves the
     * setting untouched, so the happy path is unaffected by the fix.
     */
    public function test_confirmed_enabled_registers_teacher(): void {
        global $DB;
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('confirmed-enabled-cron@example.com');
        $configmodel = $this->enable_auto_registration();

        $provider = $this->provider($this->transport([
            json_encode([
                'success' => true,
                'is_member' => false,
                'is_auto_registration_enabled' => true,
            ]),
            json_encode(['success' => true]),
        ]));

        $result = $this->plugin()->cron_auto_registrate_teachers($provider);

        $this->assertTrue($result);
        $this->assertTrue($DB->record_exists('plagiarism_pchkorg_users', ['email' => $teacher->email]));
        \plagiarism_pchkorg_config_model::reset_caches();
        $this->assertSame('1', $configmodel->get_system_config('pchkorg_teacher_auto_registration'));
    }

    /**
     * On an institution-wide site a teacher is registered as a teacher, which
     * is the role this task has always sent.
     */
    public function test_registers_teachers_as_teachers_by_default(): void {
        $this->resetAfterTest(true);

        $this->setup_course_with_teacher('institution-role-cron@example.com');
        $this->enable_auto_registration();

        $transport = $this->transport([
            json_encode([
                'success' => true,
                'is_member' => false,
                'is_auto_registration_enabled' => true,
            ]),
            json_encode(['success' => true]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        $this->assertSame(2, $transport->request(1)['params']['role']);
    }

    /**
     * On a course-scoped site the same teacher is registered as a student. The
     * per-course grants written when they open a report are then the only
     * thing carrying their teaching rights, which is what keeps the service
     * side of the feature purely additive.
     */
    public function test_registers_teachers_as_students_in_course_mode(): void {
        $this->resetAfterTest(true);

        $this->setup_course_with_teacher('course-role-cron@example.com');
        $configmodel = $this->enable_auto_registration();
        $configmodel->set_system_config('pchkorg_course_access', '1');

        // Course mode asks twice before registering: once under the namespaced
        // login and once under the address, so that a teacher the service
        // already knows is not registered a second time.
        $transport = $this->transport([
            $this->not_a_member(),
            $this->not_a_member(),
            json_encode(['success' => true]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        $this->assertSame(3, $transport->request(2)['params']['role']);
    }

    /**
     * A new teacher on a course-scoped site is named by their username.
     *
     * The bookkeeping row has to record the same string, because it is what the
     * cron's "already handled" guard matches on: storing the address instead
     * would bring this teacher back on every subsequent run.
     */
    public function test_course_mode_registers_the_namespaced_login(): void {
        global $DB;
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('course-login-cron@example.com');
        $configmodel = $this->enable_auto_registration();
        $configmodel->set_system_config('pchkorg_course_access', '1');

        $transport = $this->transport([
            $this->not_a_member(),
            $this->not_a_member(),
            json_encode(['success' => true]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        $login = \plagiarism_pchkorg_service_login::namespaced($teacher);
        $registration = $transport->request(2)['params'];

        $this->assertSame($login, $registration['email']);
        $this->assertSame('course-login-cron@example.com', $registration['additional_email']);
        $this->assertTrue($DB->record_exists('plagiarism_pchkorg_users', ['email' => $login]));
        $this->assertFalse($DB->record_exists('plagiarism_pchkorg_users', ['email' => $teacher->email]));
    }

    /**
     * An institution-wide site sends no additional_email at all.
     *
     * The identity is already the address there, so a second copy of it would
     * say nothing, and a site that never turns the setting on must keep posting
     * exactly the fields it always has.
     */
    public function test_institution_mode_sends_no_additional_email(): void {
        $this->resetAfterTest(true);

        $this->setup_course_with_teacher('no-additional-cron@example.com');
        $this->enable_auto_registration();

        $transport = $this->transport([
            $this->not_a_member(),
            json_encode(['success' => true]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        $this->assertArrayNotHasKey('additional_email', $transport->request(1)['params']);
    }

    /**
     * A teacher the service already knows by address is left under it.
     *
     * This is the bridge that stops turning the setting on from registering
     * every existing teacher a second time and orphaning their reports.
     */
    public function test_course_mode_keeps_a_teacher_already_known_by_email(): void {
        global $DB;
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('known-by-email-cron@example.com');
        $configmodel = $this->enable_auto_registration();
        $configmodel->set_system_config('pchkorg_course_access', '1');

        // Unknown by login, known by address.
        $transport = $this->transport([
            $this->not_a_member(),
            json_encode([
                'success' => true,
                'is_member' => true,
                'is_auto_registration_enabled' => true,
            ]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        // No registration call: two lookups and nothing more.
        $this->assertSame(2, $transport->request_count());
        $this->assertTrue($DB->record_exists('plagiarism_pchkorg_users', ['email' => $teacher->email]));
        $this->assertFalse($DB->record_exists(
            'plagiarism_pchkorg_users',
            ['email' => \plagiarism_pchkorg_service_login::namespaced($teacher)]
        ));
    }

    /**
     * A teacher whose address is junk is still registerable in course mode.
     *
     * Their username identifies them, so the address never has to be one; they
     * simply get no delivery address. On an institution-wide site the address
     * is the identity and such a teacher is still skipped.
     */
    public function test_course_mode_registers_a_teacher_with_no_usable_address(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('placeholder-cron@example.com');
        $configmodel = $this->enable_auto_registration();
        $configmodel->set_system_config('pchkorg_course_access', '1');

        // Moodle validates addresses on the form, so this goes in behind it.
        global $DB;
        $DB->set_field('user', 'email', 'not-an-address', ['id' => $teacher->id]);

        $transport = $this->transport([
            $this->not_a_member(),
            $this->not_a_member(),
            json_encode(['success' => true]),
        ]);

        $this->plugin()->cron_auto_registrate_teachers($this->provider($transport));

        $registration = $transport->request(2)['params'];
        $this->assertSame(
            \plagiarism_pchkorg_service_login::namespaced($teacher),
            $registration['email']
        );
        $this->assertArrayNotHasKey('additional_email', $registration);
    }

    /**
     * A membership response saying "not a member, auto-registration on".
     *
     * @return string
     */
    private function not_a_member() {
        return json_encode([
            'success' => true,
            'is_member' => false,
            'is_auto_registration_enabled' => true,
        ]);
    }

    /**
     * Build a plugin instance to call the cron method on.
     *
     * @return \plagiarism_plugin_pchkorg
     */
    private function plugin() {
        return new \plagiarism_plugin_pchkorg();
    }

    /**
     * Build a provider wired to the fake transport, using a group token so
     * the membership call is actually exercised.
     *
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($transport) {
        return new \plagiarism_pchkorg_api_provider('G-group-token', 'https://service.example', $transport);
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
     * Turn the plugin and the feature on, using a group token so the cron
     * gets as far as calling the membership endpoint.
     *
     * @return \plagiarism_pchkorg_config_model
     */
    private function enable_auto_registration() {
        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->set_system_config('pchkorg_use', '1');
        $configmodel->set_system_config('pchkorg_teacher_auto_registration', '1');
        $configmodel->set_system_config('pchkorg_token', 'G-group-token');
        $configmodel->set_system_config('pchkorg_enable_debug', '0');

        return $configmodel;
    }

    /**
     * Create a course with an assignment the plugin is enabled on, and
     * enrol one editing teacher, exactly as the cron's query expects.
     *
     * @param string $email Must be unique across this whole test run; see below.
     * @return \stdClass The enrolled teacher.
     */
    private function setup_course_with_teacher($email) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        // The provider's membership cache is a PHP function-static, shared by
        // every provider instance for the life of the process, not just this
        // test: the email must be unique across this whole test run, so an
        // auto-generated one is not safe to rely on.
        $teacher = $generator->create_user(['email' => $email]);
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $config = new \stdClass();
        $config->cm = $cm->id;
        $config->name = 'pchkorg_module_use';
        $config->value = '1';
        $DB->insert_record('plagiarism_pchkorg_config', $config);

        \plagiarism_pchkorg_config_model::reset_caches();

        return $teacher;
    }
}
