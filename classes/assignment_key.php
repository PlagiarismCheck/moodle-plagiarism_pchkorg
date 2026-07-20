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
 * Identifier for a Moodle assignment on the PlagiarismCheck.org side.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the assignment key sent to the API.
 *
 * The key identifies an activity so its ignore templates can be found again.
 * A course-module id alone is not enough: two Moodle sites sharing one
 * institutional token would collide on it, and each would then see the other's
 * templates. Including a hash of the site's wwwroot keeps sites apart.
 *
 * "Assignment" here is the service's word, not Moodle's: the key is built from
 * a course module id and so names any activity this plugin handles — an
 * assignment, a quiz or a forum. The name matches the assignment_key field of
 * the API and is kept for that reason.
 *
 * The service treats this value as opaque and prefixes its own institution id
 * before storing it, so this format can change without a server change.
 */
class plagiarism_pchkorg_assignment_key {
    /**
     * Length of the site segment. Twelve hex characters is far more than enough
     * to separate the handful of sites an institution runs, and keeps the key
     * short enough to read in a log line.
     */
    const SITE_HASH_LENGTH = 12;

    /**
     * Assignment key for a course module.
     *
     * @param int $cmid Course module id.
     * @return string For example moodle-1a2b3c4d5e6f-8753.
     */
    public static function for_cmid($cmid) {
        return sprintf('moodle-%s-%d', self::site_hash(), (int) $cmid);
    }

    /**
     * Stable identifier for this Moodle site.
     *
     * @return string
     */
    public static function site_hash() {
        global $CFG;

        $wwwroot = isset($CFG->wwwroot) ? $CFG->wwwroot : '';

        return substr(sha1((string) $wwwroot), 0, self::SITE_HASH_LENGTH);
    }
}
