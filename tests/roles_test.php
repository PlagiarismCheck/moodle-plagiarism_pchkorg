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
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/roles.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/db/install.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/db/upgrade.php');

/**
 * Which roles count as teaching roles, and the setting that lists the custom ones.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roles_test extends \advanced_testcase {
    /** @var \stdClass Course the roles are held in. */
    private $course;

    /**
     * A course to enrol people in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \plagiarism_pchkorg_config_model::reset_caches();

        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Whatever an administrator types, the stored list is one lowercase
     * shortname per line, each once.
     */
    public function test_parsing_normalises_what_was_typed(): void {
        $this->assertSame(
            ['tutor', 'ta', 'marker', 'hod'],
            \plagiarism_pchkorg_roles::parse_shortnames("  Tutor\r\n\nta, Marker ,,\n  HOD \n tutor\n")
        );
    }

    /**
     * The stock roles always count, so listing them would only repeat them.
     */
    public function test_parsing_drops_the_stock_roles(): void {
        $this->assertSame(
            ['tutor'],
            \plagiarism_pchkorg_roles::parse_shortnames("Manager\ntutor\nteacher\neditingteacher\ncoursecreator")
        );
    }

    /**
     * An empty field is a list of nothing, not an error.
     */
    public function test_parsing_an_empty_value(): void {
        $this->assertSame([], \plagiarism_pchkorg_roles::parse_shortnames(''));
        $this->assertSame([], \plagiarism_pchkorg_roles::parse_shortnames(" \n , \n"));
        $this->assertSame('', \plagiarism_pchkorg_roles::normalise_setting(" \n , \n"));
    }

    /**
     * What the settings page stores.
     */
    public function test_normalising_the_setting(): void {
        $this->assertSame("tutor\nta", \plagiarism_pchkorg_roles::normalise_setting('Tutor, TA, tutor'));
    }

    /**
     * The fresh install and the upgrade both store the old hard-coded list.
     */
    public function test_a_new_site_starts_with_the_default_list(): void {
        $this->assertSame(
            \plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES,
            \plagiarism_pchkorg_roles::custom_teacher_shortnames()
        );
        $this->assertSame(
            \plagiarism_pchkorg_roles::format_shortnames(\plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES),
            (new \plagiarism_pchkorg_config_model())->get_system_config(\plagiarism_pchkorg_roles::CONFIG_NAME)
        );
    }

    /**
     * Never stored falls back to the defaults: the 2026082601 upgrade step
     * reads the list before the step that stores it has run.
     */
    public function test_an_unset_setting_falls_back_to_the_defaults(): void {
        $this->unset_setting();

        $this->assertSame(
            \plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES,
            \plagiarism_pchkorg_roles::custom_teacher_shortnames()
        );
    }

    /**
     * The site's own list replaces the defaults rather than adding to them.
     */
    public function test_a_stored_list_is_used(): void {
        $this->set_setting("tutor\nta");

        $this->assertSame(['tutor', 'ta'], \plagiarism_pchkorg_roles::custom_teacher_shortnames());
        $this->assertSame(
            ['manager', 'coursecreator', 'editingteacher', 'teacher', 'tutor', 'ta'],
            \plagiarism_pchkorg_roles::teacher_shortnames()
        );
    }

    /**
     * An emptied setting is a decision, not a missing value.
     */
    public function test_a_stored_empty_list_means_no_custom_roles(): void {
        $this->set_setting('');

        $this->assertSame([], \plagiarism_pchkorg_roles::custom_teacher_shortnames());
        $this->assertSame(
            ['manager', 'coursecreator', 'editingteacher', 'teacher'],
            \plagiarism_pchkorg_roles::teacher_shortnames()
        );
    }

    /**
     * The case this setting exists for: a Tutor role allowed to view results
     * but not moodle/grade:viewall is a student until the site lists it.
     */
    public function test_a_listed_custom_role_is_treated_as_a_teacher(): void {
        $tutor = $this->user_with_custom_role('tutor');
        $context = \context_course::instance($this->course->id);

        $this->assertFalse(\plagiarism_pchkorg_roles::is_teacher($context, $tutor->id), 'not listed by default');
        $this->assertTrue(\plagiarism_pchkorg_roles::is_student($context, $tutor->id));

        $this->set_setting("ta\ntutor");

        $this->assertTrue(\plagiarism_pchkorg_roles::is_teacher($context, $tutor->id));
        $this->assertFalse(\plagiarism_pchkorg_roles::is_student($context, $tutor->id));

        $this->set_setting('ta');

        $this->assertFalse(\plagiarism_pchkorg_roles::is_teacher($context, $tutor->id), 'removed again');
    }

    /**
     * Removing a default role from the list is honoured too.
     */
    public function test_a_default_role_removed_from_the_list_is_a_student(): void {
        $ta = $this->user_with_custom_role('ta');
        $context = \context_course::instance($this->course->id);

        $this->assertTrue(\plagiarism_pchkorg_roles::is_teacher($context, $ta->id));

        $this->set_setting('tutor');

        $this->assertFalse(\plagiarism_pchkorg_roles::is_teacher($context, $ta->id));
    }

    /**
     * A role made through the API rather than the role editor can keep
     * capitals in its shortname.
     */
    public function test_a_listed_role_is_found_whatever_its_case(): void {
        $tutor = $this->user_with_custom_role('Tutor');
        $context = \context_course::instance($this->course->id);

        $this->set_setting('tutor');

        $this->assertTrue(\plagiarism_pchkorg_roles::is_teacher($context, $tutor->id));
    }

    /**
     * Only the characters Moodle's role editor allows in a shortname.
     */
    public function test_invalid_shortnames_are_reported(): void {
        $this->assertSame([], \plagiarism_pchkorg_roles::invalid_shortnames("tutor\nhead-of_dept\nadt1"));
        $this->assertSame(
            ['tutor!', 'lé'],
            \plagiarism_pchkorg_roles::invalid_shortnames("tutor!\nta\nlé")
        );
    }

    /**
     * Listed but missing from the site: allowed, but worth pointing out.
     */
    public function test_unknown_shortnames_are_reported(): void {
        $this->user_with_custom_role('tutor');

        $this->assertSame(
            ['tutr', 'marker'],
            \plagiarism_pchkorg_roles::unknown_shortnames(['tutor', 'tutr', 'student', 'marker'])
        );
        $this->assertSame([], \plagiarism_pchkorg_roles::unknown_shortnames([]));
    }

    /**
     * The install hook stores the defaults.
     */
    public function test_install_stores_the_defaults(): void {
        $this->unset_setting();

        $this->assertTrue(xmldb_plagiarism_pchkorg_install());

        $this->assertSame(
            \plagiarism_pchkorg_roles::format_shortnames(\plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES),
            (new \plagiarism_pchkorg_config_model())->get_system_config(\plagiarism_pchkorg_roles::CONFIG_NAME)
        );
        $this->assertSame(
            \plagiarism_pchkorg_roles::format_shortnames(\plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES),
            get_config('plagiarism_pchkorg', \plagiarism_pchkorg_roles::CONFIG_NAME)
        );
    }

    /**
     * A site upgrading from before the setting existed gets the list it
     * already had.
     */
    public function test_the_upgrade_stores_the_defaults(): void {
        $this->unset_setting();

        set_config('version', 2026100900, 'plagiarism_pchkorg');
        $this->assertTrue(xmldb_plagiarism_pchkorg_upgrade(2026100900));

        $this->assertSame(
            \plagiarism_pchkorg_roles::DEFAULT_CUSTOM_TEACHER_SHORTNAMES,
            \plagiarism_pchkorg_roles::custom_teacher_shortnames()
        );
    }

    /**
     * Running the step again, or on a site that already set its list, must not
     * undo the administrator's choice.
     *
     * Looped rather than a data provider, for the reason given in
     * ignore_template_capability_test.
     */
    public function test_the_upgrade_keeps_a_stored_list(): void {
        foreach (['a list' => 'tutor', 'an empty list' => ''] as $label => $value) {
            $this->set_setting($value);

            set_config('version', 2026100900, 'plagiarism_pchkorg');
            $this->assertTrue(xmldb_plagiarism_pchkorg_upgrade(2026100900));
            \plagiarism_pchkorg_config_model::reset_caches();

            $this->assertSame(
                $value,
                (new \plagiarism_pchkorg_config_model())->get_system_config(\plagiarism_pchkorg_roles::CONFIG_NAME),
                $label
            );
        }
    }

    /**
     * A user holding a role that exists only on this site.
     *
     * Created with no archetype, so it has no moodle/grade:viewall and only
     * the shortname list can make a teacher of it.
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
     * Store the custom teaching roles setting as the settings page would.
     *
     * @param string $value
     */
    private function set_setting($value) {
        (new \plagiarism_pchkorg_config_model())->set_system_config(
            \plagiarism_pchkorg_roles::CONFIG_NAME,
            \plagiarism_pchkorg_roles::normalise_setting($value)
        );
    }

    /**
     * Remove the setting, as on a site that never stored it.
     */
    private function unset_setting() {
        global $DB;

        $DB->delete_records('plagiarism_pchkorg_config', [
            'cm' => 0,
            'name' => \plagiarism_pchkorg_roles::CONFIG_NAME,
        ]);
        unset_config(\plagiarism_pchkorg_roles::CONFIG_NAME, 'plagiarism_pchkorg');
        \plagiarism_pchkorg_config_model::reset_caches();
    }
}
