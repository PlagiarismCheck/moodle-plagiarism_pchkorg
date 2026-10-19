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

require_once(__DIR__ . '/plagiarism_pchkorg_config_model.php');

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
     * Name of the site setting holding the custom teaching role shortnames.
     *
     * Stored like every other global setting of this plugin, against cm 0 in
     * plagiarism_pchkorg_config, one shortname per line.
     */
    const CONFIG_NAME = 'pchkorg_custom_teacher_roles';

    /**
     * Longest value the setting can hold.
     *
     * The width of plagiarism_pchkorg_config.value. Widening it to TEXT would
     * cost a portable comparison everywhere the table is queried by value.
     */
    const CONFIG_MAX_LENGTH = 255;

    /**
     * Custom teaching roles a site starts with.
     *
     * The roles used by institutions running this plugin before the list
     * became a setting, plus tutor. Written into the setting once, on install
     * or upgrade, after which the site's own list is what counts.
     *
     * @var string[]
     */
    const DEFAULT_CUSTOM_TEACHER_SHORTNAMES = [
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
        'tutor',
    ];

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
     * The Moodle defaults followed by the custom roles configured for this
     * site.
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
     * Read from the site setting. A setting never stored falls back to the
     * defaults rather than to nothing: the 2026082601 upgrade step asks for
     * this list before the step that stores the setting has run, and a site
     * upgrading across both must still grant its custom roles what they had.
     * A setting stored empty means the site wants no custom roles.
     *
     * @return string[]
     */
    public static function custom_teacher_shortnames() {
        $value = (new plagiarism_pchkorg_config_model())->get_system_config(self::CONFIG_NAME);
        if (null === $value) {
            return self::DEFAULT_CUSTOM_TEACHER_SHORTNAMES;
        }

        return self::parse_shortnames($value);
    }

    /**
     * Turn the setting as typed into a list of shortnames.
     *
     * Entries may be separated by new lines, commas or spaces. They are
     * lowercased, since Moodle's role editor saves shortnames lowercased and
     * the comparisons in this class are made against lowercased names. The
     * stock roles are dropped, as they always count and listing them twice
     * would only put them in the SQL twice.
     *
     * @param string $value
     * @return string[] In the order given, without duplicates.
     */
    public static function parse_shortnames($value) {
        $shortnames = [];
        foreach (preg_split('/[\s,]+/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $shortname = core_text::strtolower($entry);
            if (in_array($shortname, self::DEFAULT_TEACHER_SHORTNAMES, true)) {
                continue;
            }
            $shortnames[$shortname] = $shortname;
        }

        return array_values($shortnames);
    }

    /**
     * The stored form of a list of shortnames.
     *
     * @param string[] $shortnames
     * @return string One shortname per line.
     */
    public static function format_shortnames(array $shortnames) {
        return implode("\n", $shortnames);
    }

    /**
     * Normalise the setting as typed into the form it is stored in.
     *
     * @param string $value
     * @return string
     */
    public static function normalise_setting($value) {
        return self::format_shortnames(self::parse_shortnames($value));
    }

    /**
     * Entries in the setting that cannot be a role shortname.
     *
     * Moodle's role editor keeps a shortname to the characters PARAM_ALPHANUMEXT
     * allows, so anything else can never match a role.
     *
     * @param string $value The setting as typed.
     * @return string[]
     */
    public static function invalid_shortnames($value) {
        $invalid = [];
        foreach (self::parse_shortnames($value) as $shortname) {
            if (clean_param($shortname, PARAM_ALPHANUMEXT) !== $shortname) {
                $invalid[] = $shortname;
            }
        }

        return $invalid;
    }

    /**
     * Shortnames that no role on this site has.
     *
     * Not an error: a site may list a role before creating it. Reported so a
     * typo does not go unnoticed.
     *
     * @param string[] $shortnames
     * @return string[]
     */
    public static function unknown_shortnames(array $shortnames) {
        global $DB;

        if (empty($shortnames)) {
            return [];
        }

        $known = [];
        foreach ($DB->get_fieldset_sql('SELECT shortname FROM {role}') as $shortname) {
            $known[core_text::strtolower($shortname)] = true;
        }

        return array_values(array_filter($shortnames, function ($shortname) use ($known) {
            return !isset($known[$shortname]);
        }));
    }

    /**
     * Store the default custom roles, unless the site already has a list.
     *
     * Used on install and upgrade. A stored value, including an empty one, is
     * a decision the site made and is left alone.
     *
     * @return bool Whether the defaults were written.
     */
    public static function store_default_setting() {
        $configmodel = new plagiarism_pchkorg_config_model();
        if (null !== $configmodel->get_system_config(self::CONFIG_NAME)) {
            return false;
        }

        $value = self::format_shortnames(self::DEFAULT_CUSTOM_TEACHER_SHORTNAMES);
        // Both stores, as the settings page writes both.
        set_config(self::CONFIG_NAME, $value, 'plagiarism_pchkorg');
        $configmodel->set_system_config(self::CONFIG_NAME, $value);

        return true;
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
        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED);
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
