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
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/ignore_template_download.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/ignore_template_form.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/roles.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/db/upgrade.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Who may manage the templates an activity's submissions are checked against.
 *
 * Every API call goes through the fake transport; nothing here reaches
 * plagiarismcheck.org.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_template_capability_test extends \advanced_testcase {
    /** @var string The capability under test. */
    const CAPABILITY = 'plagiarism/pchkorg:manageignoretemplates';

    /** @var \stdClass Course the activity under test lives in. */
    private $course;

    /** @var \stdClass Assignment course module. */
    private $cm;

    /**
     * A course, an assignment and the feature switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \plagiarism_pchkorg_config_model::reset_caches();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('assign', $assign->id);

        $this->set_site_config('pchkorg_enable_ignore_templates', '1');
        $this->set_site_config('pchkorg_token', 'G-institutional-token');
        \plagiarism_pchkorg_config_model::reset_caches();
    }

    /**
     * The stock roles db/access.php grants the capability to.
     *
     * The cases are looped rather than supplied by a data provider: the
     * dataProvider annotation is deprecated in PHPUnit 11 and removed in 12,
     * while its replacement attribute needs PHP 8.0 and does not exist in the
     * PHPUnit 7 shipped with Moodle 3.9.
     */
    public function test_stock_teaching_roles_may_manage_templates(): void {
        $allowed = [
            'editing teacher' => 'editingteacher',
            'manager' => 'manager',
            'course creator' => 'coursecreator',
        ];

        foreach ($allowed as $label => $shortname) {
            $this->assertTrue($this->can_manage($this->user_with_role($shortname)), $label);
        }
    }

    /**
     * The roles deliberately left out. A template decides what stops counting
     * against every student in the activity, which is a change to how the work
     * is marked rather than part of marking it.
     *
     * Looped rather than a data provider, for the reason given above.
     */
    public function test_other_roles_may_not_manage_templates(): void {
        $refused = [
            'non-editing teacher' => 'teacher',
            'student' => 'student',
        ];

        foreach ($refused as $label => $shortname) {
            $this->assertFalse($this->can_manage($this->user_with_role($shortname)), $label);
        }
    }

    /**
     * The point of a capability of our own: a site can now withhold templates
     * from a role that still edits the activity in every other respect. Under
     * moodle/course:manageactivities the two could not be told apart.
     */
    public function test_a_site_may_withhold_templates_from_an_editing_teacher(): void {
        $teacher = $this->user_with_role('editingteacher');
        $role = $this->role_by_shortname('editingteacher');

        assign_capability(
            self::CAPABILITY,
            CAP_PREVENT,
            $role->id,
            \context_module::instance($this->cm->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->can_manage($teacher));
    }

    /**
     * Downloading a template is gated on the same capability, so a role that
     * may not manage templates cannot read them out of the activity either.
     */
    public function test_downloading_needs_the_same_capability(): void {
        $this->setUser($this->user_with_role('teacher'));

        $transport = new \plagiarism_pchkorg_fake_transport();

        $this->expectException(\required_capability_exception::class);

        try {
            \plagiarism_pchkorg_ignore_template_download::resolve(
                $this->cm,
                7,
                $this->user_with_role('teacher'),
                new \plagiarism_pchkorg_config_model(),
                $this->provider($transport)
            );
        } finally {
            $this->assertSame(0, $transport->request_count(), 'refused before any request');
        }
    }

    /**
     * A custom teaching role built on no archetype gets nothing from
     * db/access.php, which is the whole reason the grant exists.
     */
    public function test_a_custom_teaching_role_is_granted_the_capability(): void {
        $user = $this->user_with_custom_role('ta');

        $this->assertFalse($this->can_manage($user), 'nothing reaches it on its own');

        \plagiarism_pchkorg_roles::grant_to_custom_teachers(self::CAPABILITY);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->can_manage($user));
    }

    /**
     * Only the roles this plugin knows by name. A site's own roles are its own
     * business.
     */
    public function test_an_unrelated_role_is_left_alone(): void {
        $user = $this->user_with_custom_role('marker');

        \plagiarism_pchkorg_roles::grant_to_custom_teachers(self::CAPABILITY);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->can_manage($user));
    }

    /**
     * A site that has already refused a custom role keeps its answer. The
     * grant hands out a starting point, it does not overrule anybody.
     */
    public function test_a_refusal_already_recorded_is_not_overruled(): void {
        $user = $this->user_with_custom_role('hod');
        $role = $this->role_by_shortname('hod');

        assign_capability(self::CAPABILITY, CAP_PREVENT, $role->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        \plagiarism_pchkorg_roles::grant_to_custom_teachers(self::CAPABILITY);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->can_manage($user));
    }

    /**
     * The non-editing teacher is a stock role, so the custom-role grant must
     * not be a way for it to arrive after all.
     */
    public function test_the_grant_does_not_reach_the_non_editing_teacher(): void {
        $teacher = $this->user_with_role('teacher');

        \plagiarism_pchkorg_roles::grant_to_custom_teachers(self::CAPABILITY);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->can_manage($teacher));
    }

    /**
     * The upgrade step is what performs the grant on a site that already has
     * the plugin, so run the real thing rather than only the helper under it.
     */
    public function test_the_upgrade_step_grants_the_capability(): void {
        $user = $this->user_with_custom_role('cce');

        $this->assertFalse($this->can_manage($user));

        // The savepoint refuses to move a version backwards, so pretend the
        // site is on the release before this one.
        set_config('version', 2026082600, 'plagiarism_pchkorg');

        $this->assertTrue(xmldb_plagiarism_pchkorg_upgrade(2026082600));
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->can_manage($user));
    }

    /**
     * The state a real upgrade actually runs in: Moodle reads db/access.php in
     * upgrade_component_updated(), which happens after the upgrade function has
     * returned, so the capability is not in the capabilities table yet while
     * the step runs. assign_capability() throws on a capability it cannot find,
     * so the step has to register the plugin's definitions itself.
     */
    public function test_the_upgrade_step_runs_before_the_capability_is_registered(): void {
        global $DB;

        $user = $this->user_with_custom_role('cce');

        $DB->delete_records('capabilities', ['name' => self::CAPABILITY]);
        \cache::make('core', 'capabilities')->delete('core_capabilities');
        accesslib_clear_all_caches_for_unit_testing();

        set_config('version', 2026082600, 'plagiarism_pchkorg');

        $this->assertTrue(xmldb_plagiarism_pchkorg_upgrade(2026082600));
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(
            $DB->record_exists('capabilities', ['name' => self::CAPABILITY]),
            'the step registers the capability it is about to hand out'
        );
        $this->assertTrue($this->can_manage($user));
    }

    /**
     * Splitting the shortname list in two must not change what the rest of the
     * plugin sees, in particular the auto-registration query.
     */
    public function test_the_custom_roles_are_still_part_of_the_teacher_list(): void {
        $all = \plagiarism_pchkorg_roles::teacher_shortnames();

        $this->assertSame(
            ['manager', 'coursecreator', 'editingteacher', 'teacher'],
            array_slice($all, 0, 4),
            'the stock roles still come first'
        );

        foreach (\plagiarism_pchkorg_roles::custom_teacher_shortnames() as $shortname) {
            $this->assertContains($shortname, $all);
        }

        $this->assertSame($all, array_unique($all), 'no shortname listed twice');
    }

    /**
     * Whether the templates section is offered to a user.
     *
     * Asked through is_available() rather than has_capability() so the test
     * covers the gate as the form actually applies it.
     *
     * @param \stdClass $user
     * @return bool
     */
    private function can_manage($user) {
        $this->setUser($user);

        return \plagiarism_pchkorg_ignore_template_form::is_available(
            new \plagiarism_pchkorg_config_model(),
            \context_module::instance($this->cm->id),
            $this->provider()
        );
    }

    /**
     * A user enrolled in the course with a stock role.
     *
     * @param string $shortname
     * @return \stdClass
     */
    private function user_with_role($shortname) {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $this->course->id, $shortname);

        return $user;
    }

    /**
     * A user holding a role that exists only on this site.
     *
     * Created with no archetype, which is the case db/access.php cannot reach:
     * a role built on a stock archetype might inherit the capability and hide
     * whether the grant did anything.
     *
     * @param string $shortname
     * @return \stdClass
     */
    private function user_with_custom_role($shortname) {
        $roleid = create_role(strtoupper($shortname), $shortname, 'Custom role for testing');
        set_role_contextlevels($roleid, [CONTEXT_COURSE, CONTEXT_MODULE]);

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $this->course->id, $roleid);

        return $user;
    }

    /**
     * Role record by shortname.
     *
     * @param string $shortname
     * @return \stdClass
     */
    private function role_by_shortname($shortname) {
        global $DB;

        return $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);
    }

    /**
     * API provider wired to a fake transport.
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
     * Store a site-level plugin setting.
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
}
