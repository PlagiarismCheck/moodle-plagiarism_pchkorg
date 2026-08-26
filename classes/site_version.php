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
 * Versions of this site's Moodle and of this plugin, as reported to the service.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Reports which Moodle and which plugin release a check came from.
 *
 * Both values travel with every submission so the service can tell which
 * releases are still in use and which can stop being supported. Neither is
 * personal data and neither affects how a text is checked: a site that cannot
 * report a version sends nothing and is checked exactly as before.
 */
class plagiarism_pchkorg_site_version {
    /** @var string|null|false Cached plugin release; false while not yet read. */
    private static $release = false;

    /**
     * Major version of this Moodle, for example 5.0, 4.5 or 3.11.
     *
     * @return string|null Null when the version cannot be determined.
     */
    public static function moodle_major() {
        global $CFG;

        // The release is the human-readable name, "5.0 (Build: 20250414)" or
        // "3.11.4+ (Build: 20220520)". Its leading major.minor is what Moodle
        // itself calls a version, and the pattern has held across every release
        // line, including the 3.9 -> 3.10 two-digit minor and "5.1dev" builds.
        if (isset($CFG->release) && preg_match('/^(\d+\.\d+)/', (string) $CFG->release, $matches)) {
            return $matches[1];
        }

        // Fall back to the branch, which is the same version with the dot
        // dropped: 500 is 5.0, 405 is 4.5, 311 is 3.11 and 39 is 3.9. Only the
        // major digit is ever leading, so splitting after it restores the dot.
        if (isset($CFG->branch) && preg_match('/^(\d)(\d{1,2})$/', (string) $CFG->branch, $matches)) {
            return $matches[1] . '.' . (int) $matches[2];
        }

        return null;
    }

    /**
     * Release of this plugin, for example v3.16.4.
     *
     * @return string|null Null when the release cannot be determined.
     */
    public static function plugin_release() {
        // One send run asks once per activity, and the answer cannot change
        // within a request, so the file is read at most once.
        if (false !== self::$release) {
            return self::$release;
        }

        // Read from the plugin's own version.php rather than from the database,
        // which stores only the numeric version. Seeding $plugin first is the
        // documented way in: version.php keeps whatever object it is given.
        $plugin = new stdClass();
        require(__DIR__ . '/../version.php');
        self::$release = isset($plugin->release) ? (string) $plugin->release : null;

        return self::$release;
    }

    /**
     * Forget the cached plugin release. Only used by tests.
     */
    public static function reset_cache() {
        self::$release = false;
    }
}
