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
     * Stock Moodle teaching roles.
     *
     * @var string[]
     */
    const DEFAULT_TEACHER_SHORTNAMES = [
        'manager',
        'coursecreator',
        'editingteacher',
        'teacher',
    ];

    /**
     * Teaching role shortnames.
     *
     * The Moodle defaults followed by the custom roles used by institutions
     * running this plugin.
     *
     * @return string[]
     */
    public static function teacher_shortnames() {
        return array_merge(self::DEFAULT_TEACHER_SHORTNAMES, self::custom_teacher_shortnames());
    }

    /**
     * Teaching role shortnames that are not Moodle defaults.
     *
     * Kept apart from the stock four because these can only be reached by name.
     * A capability declared in db/access.php is handed out by archetype, and a
     * site is free to build a role on any archetype or none, so nothing there
     * can be relied on to reach these roles. Granting them a capability takes
     * an upgrade step that looks them up by shortname.
     *
     * @return string[]
     */
    public static function custom_teacher_shortnames() {
        return [
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
     * Grant a capability to every custom teaching role on this site.
     *
     * For capabilities db/access.php cannot reach on its own. Its archetype
     * defaults only reach roles built from a stock archetype, and a custom
     * teaching role may be built on any archetype or none, so a site's TA or
     * head-of-department role can end up with nothing.
     *
     * Granted at system level, where role definitions live, so an override in
     * a course or an activity still wins afterwards. A role that already has
     * an answer for this capability keeps it, including a deliberate refusal:
     * this is here to give roles a starting point, not to overrule a site.
     *
     * @param string $capability
     * @return int How many roles were granted it.
     */
    public static function grant_to_custom_teachers($capability) {
        global $DB;

        $shortnames = self::custom_teacher_shortnames();
        list($insql, $params) = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED);
        $roles = $DB->get_records_select('role', "LOWER(shortname) {$insql}", $params, '', 'id, shortname');

        $systemcontextid = \context_system::instance()->id;
        $granted = 0;
        foreach ($roles as $role) {
            if (assign_capability($capability, CAP_ALLOW, $role->id, $systemcontextid)) {
                $granted++;
            }
        }

        return $granted;
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
