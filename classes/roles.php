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
 * Which roles count as teaching roles.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Which roles count as teaching roles.
 *
 * Moodle sites rename and invent roles freely, so role shortnames alone are an
 * unreliable signal. The capability check is therefore authoritative and the
 * shortname list is a fallback, kept because installations already depend on
 * the custom roles in it.
 *
 * The list lives here rather than being repeated at each use: it is needed both
 * in PHP, when deciding what a viewer may see, and in SQL, when the
 * auto-registration task looks for teachers to import.
 */
class plagiarism_pchkorg_roles {
    /**
     * Capability that distinguishes a teacher from a student.
     *
     * Held by editingteacher, teacher and manager in a stock Moodle, and by any
     * custom role built for grading.
     */
    const TEACHER_CAPABILITY = 'moodle/grade:viewall';

    /**
     * Teaching role shortnames.
     *
     * The first four are Moodle defaults; the rest are custom roles used by
     * institutions running this plugin.
     *
     * @return string[]
     */
    public static function teacher_shortnames() {
        return [
            'manager',
            'coursecreator',
            'editingteacher',
            'teacher',
            // Popular custom teacher roles.
            'l',
            'ta',
            'cce',
            'hod',
            'cl',
            'ca',
            'lib',
            'led',
            'id',
            'adt1',
            'adt1tii',
        ];
    }

    /**
     * Whether a user should be treated as a teacher in the given context.
     *
     * Capability first, so sites with renamed roles behave correctly; the
     * shortname list second, so sites relying on the custom roles above keep
     * the behaviour they already have.
     *
     * @param context $context
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function is_teacher($context, $userid = null) {
        global $USER;

        if (null === $userid) {
            $userid = $USER->id;
        }

        if (has_capability(self::TEACHER_CAPABILITY, $context, $userid)) {
            return true;
        }

        return self::has_teacher_shortname($context, $userid);
    }

    /**
     * Whether any of the user's roles in the context is a known teaching role.
     *
     * @param context $context
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function has_teacher_shortname($context, $userid = null) {
        global $USER;

        if (null === $userid) {
            $userid = $USER->id;
        }

        $teachershortnames = self::teacher_shortnames();
        foreach (get_user_roles($context, $userid, true) as $role) {
            if (in_array(strtolower($role->shortname), $teachershortnames, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a user should be treated as a student in the given context.
     *
     * Moodle allows several roles at once, so this is deliberately the inverse
     * of is_teacher() rather than a check for the student role: anyone holding
     * any teaching role is treated as a teacher.
     *
     * @param context $context
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function is_student($context, $userid = null) {
        return !self::is_teacher($context, $userid);
    }
}
