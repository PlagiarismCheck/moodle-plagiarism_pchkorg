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

/**
 * Whether reports are scoped per course, and what that implies.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Whether reports are scoped per course, and what that implies.
 *
 * The service stores one role per member for the whole institution, so a person
 * who teaches one course and studies in another is either a teacher over every
 * report the institution holds or a student over none of them. Course-scoped
 * access replaces that with a per-course grant, issued when a teacher opens a
 * report.
 *
 * A site is in exactly one of two modes, and this class is the only place that
 * decides which. Callers ask it questions rather than reading the setting
 * themselves, so the two producers -- the report page and the
 * auto-registration task -- cannot drift apart in how they read it.
 */
class plagiarism_pchkorg_course_access {
    /**
     * Institution-wide access. The behaviour every site has today.
     *
     * Members are registered with the service as teachers, and a teacher may
     * open any report in the institution.
     */
    const MODE_INSTITUTION = 1;

    /**
     * Course-scoped access.
     *
     * Members are registered as students, and teaching rights are granted one
     * course at a time as reports are opened.
     */
    const MODE_COURSE = 2;

    /**
     * Setting that chooses between the two modes.
     */
    const SETTING = 'pchkorg_course_access';

    /**
     * Service role id for a teacher.
     *
     * The service's GroupMember::ROLE_TEACHER. Named here because it used to be
     * a bare 2 with a comment beside it.
     */
    const SERVICE_ROLE_TEACHER = 2;

    /**
     * Service role id for a student.
     *
     * The service's GroupMember::ROLE_STUDENT.
     */
    const SERVICE_ROLE_STUDENT = 3;

    /**
     * Which mode this site is in.
     *
     * Off unless an administrator has turned it on, so an existing site keeps
     * its behaviour to the byte until somebody decides otherwise.
     *
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return int One of the MODE_* constants.
     */
    public static function mode($configmodel = null) {
        if (null === $configmodel) {
            $configmodel = new plagiarism_pchkorg_config_model();
        }

        return '1' === $configmodel->get_system_config(self::SETTING)
            ? self::MODE_COURSE
            : self::MODE_INSTITUTION;
    }

    /**
     * Whether report access is scoped per course on this site.
     *
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return bool
     */
    public static function is_course_scoped($configmodel = null) {
        return self::MODE_COURSE === self::mode($configmodel);
    }

    /**
     * The role the auto-registration task should register a teacher under.
     *
     * In course-scoped mode a member is registered as a student, which leaves
     * the grant as the only source of teaching rights and is what makes the
     * service-side change purely additive. Members registered before the switch
     * keep whatever role they already have; nothing here goes back and changes
     * them.
     *
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return int A SERVICE_ROLE_* constant.
     */
    public static function registration_role($configmodel = null) {
        return self::is_course_scoped($configmodel)
            ? self::SERVICE_ROLE_STUDENT
            : self::SERVICE_ROLE_TEACHER;
    }

    /**
     * Make sure the viewer can reach this report, before they are sent to it.
     *
     * In course-scoped mode a teacher's rights over a course exist only as a
     * grant on the service, and the grant is written here, one moment before
     * the browser is handed over. Doing it now rather than on a schedule means
     * there is no enrolment sync to keep correct: whoever Moodle says may open
     * this report is who the service is told about.
     *
     * Failure is reported rather than swallowed. Sending a teacher onward
     * without a grant lands them on a service-side refusal they have no way to
     * interpret, so the caller stops with a message instead.
     *
     * Called for every viewer, and answers true unchanged for all of them
     * except a teacher on a course-scoped site: an institution-wide site needs
     * no grant, and a student reaches their own report as its author.
     *
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @param context $context Module context the viewer was checked against.
     * @param int|string $courseid Moodle course id of the activity.
     * @param string $login Identity of the viewer as the service knows them,
     *                      from {@see plagiarism_pchkorg_service_login::resolve()}.
     *                      On a course-scoped site this is a namespaced username
     *                      rather than an address, and it must be the same string
     *                      the membership check used or the grant lands on nobody.
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return bool False only when a grant was needed and the service refused it.
     */
    public static function ensure_report_access($apiprovider, $context, $courseid, $login, $configmodel = null) {
        if (!self::is_course_scoped($configmodel)) {
            return true;
        }

        if (!plagiarism_pchkorg_roles::is_teacher($context)) {
            return true;
        }

        return $apiprovider->grant_course_role($login, $courseid, self::SERVICE_ROLE_TEACHER);
    }
}
