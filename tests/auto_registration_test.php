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

/**
 * Tests for the teacher auto-registration query.
 *
 * The point of these tests is that the query is actually executed against the
 * database under test. The previous version parsed on MySQL but was rejected by
 * PostgreSQL, and nothing caught it because no test ever ran it.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_registration_test extends \advanced_testcase {
    /**
     * The query runs on the database under test and finds an enrolled teacher
     * in a course with the plugin enabled.
     */
    public function test_query_finds_enrolled_teacher(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('editingteacher');
        $found = $this->run_query();

        $this->assertArrayHasKey($teacher->id, $found);
        $this->assertSame($teacher->email, $found[$teacher->id]->email);
    }

    /**
     * Names are concatenated by the database, which needs a portable spelling
     * rather than MySQL's CONCAT.
     */
    public function test_name_is_concatenated_portably(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('editingteacher');
        $found = $this->run_query();

        $this->assertSame(
            $teacher->firstname . ' ' . $teacher->lastname,
            $found[$teacher->id]->name
        );
    }

    /**
     * Custom institution role shortnames are recognised alongside the Moodle
     * defaults, since installations already depend on them.
     */
    public function test_custom_teacher_roles_are_recognised(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('hod');
        $found = $this->run_query();

        $this->assertArrayHasKey($teacher->id, $found);
    }

    /**
     * Students are not imported as teachers.
     */
    public function test_students_are_excluded(): void {
        $this->resetAfterTest(true);

        $student = $this->setup_course_with_teacher('student');
        $found = $this->run_query();

        $this->assertArrayNotHasKey($student->id, $found);
    }

    /**
     * Users already imported are skipped, which is what lets the task make
     * progress across runs.
     */
    public function test_already_imported_users_are_excluded(): void {
        global $DB;
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('editingteacher');

        $record = new \stdClass();
        $record->email = $teacher->email;
        $DB->insert_record('plagiarism_pchkorg_users', $record);

        $this->assertArrayNotHasKey($teacher->id, $this->run_query());
    }

    /**
     * A course without the plugin enabled contributes nobody.
     */
    public function test_courses_without_the_plugin_are_excluded(): void {
        $this->resetAfterTest(true);

        $teacher = $this->setup_course_with_teacher('editingteacher', false);

        $this->assertArrayNotHasKey($teacher->id, $this->run_query());
    }

    /**
     * Create a course with an assignment, optionally enable the plugin on it,
     * and enrol one user with the given role.
     *
     * @param string $roleshortname
     * @param bool $pluginenabled
     * @return \stdClass The enrolled user.
     */
    private function setup_course_with_teacher($roleshortname, $pluginenabled = true) {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        if (!$DB->record_exists('role', ['shortname' => $roleshortname])) {
            create_role($roleshortname, $roleshortname, 'Custom role for tests');
        }

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, $roleshortname);

        if ($pluginenabled) {
            $cm = get_coursemodule_from_instance('assign', $assign->id);
            $config = new \stdClass();
            $config->cm = $cm->id;
            $config->name = 'pchkorg_module_use';
            $config->value = '1';
            $DB->insert_record('plagiarism_pchkorg_config', $config);
        }

        return $user;
    }

    /**
     * Run the auto-registration query exactly as the task builds it.
     *
     * @return array Records keyed by user id.
     */
    private function run_query() {
        global $DB;

        [$rolesql, $roleparams] = $DB->get_in_or_equal(
            \plagiarism_pchkorg_roles::teacher_shortnames(),
            SQL_PARAMS_NAMED,
            'role'
        );
        $namesql = $DB->sql_concat('u.firstname', "' '", 'u.lastname');

        $sql = "SELECT DISTINCT u.id, u.email, {$namesql} AS name
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctxcourse ON ctxcourse.id = ra.contextid
                       AND ctxcourse.contextlevel = :coursecontext
                 WHERE u.deleted = 0
                   AND r.shortname {$rolesql}
                   AND e.courseid IN (
                       SELECT c.id
                         FROM {assign} a
                         JOIN {course_modules} cm ON cm.instance = a.id
                         JOIN {modules} m ON m.id = cm.module
                         JOIN {plagiarism_pchkorg_config} pc ON pc.cm = cm.id
                         JOIN {course} c ON c.id = cm.course AND a.course = c.id
                        WHERE pc.value = :enabledvalue
                          AND pc.name = :configname
                          AND m.name = :modname
                   )
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {plagiarism_pchkorg_users} pu
                        WHERE pu.email = u.email
                   )
              ORDER BY u.id";

        $params = array_merge($roleparams, [
            'coursecontext' => CONTEXT_COURSE,
            'enabledvalue' => '1',
            'configname' => 'pchkorg_module_use',
            'modname' => 'assign',
        ]);

        return $DB->get_records_sql($sql, $params, 0, 50);
    }
}
