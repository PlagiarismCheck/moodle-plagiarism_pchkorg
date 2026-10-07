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
 * Group (team) assignment submissions.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/state.php');

/**
 * Tells group submissions apart from individual ones.
 *
 * Moodle stores a group submission with userid 0: groupid is the group, or 0
 * for the shared "Default group" of students in no group or in several. An
 * individual submission always carries its student's id. The test is made on
 * the submission row rather than on the assignment's settings, so individual
 * and group assignments in one course, and records queued before a teacher
 * changed a setting, each keep the path they were queued on.
 */
class plagiarism_pchkorg_group_submission {
    /**
     * Whether an assignment submission belongs to a group.
     *
     * @param int $submissionid assign_submission id.
     * @return bool False for an individual submission, and for one that no longer exists.
     */
    public static function is_group_submission($submissionid) {
        global $DB;

        $userid = $DB->get_field('assign_submission', 'userid', ['id' => $submissionid]);

        return false !== $userid && 0 === (int) $userid;
    }

    /**
     * Identity of a group's submission to one assignment, for the service's inner check.
     *
     * The service indexes a group's files under this rather than their
     * uploader, so files of one group's submission never match each other,
     * while other groups' and other work still do. Built from the course
     * module and the Moodle group rather than the submission id, because a
     * reopened attempt is a new submission row of the same group. The site
     * digest keeps two Moodle sites apart in the service's shared index.
     *
     * @param stdClass $cm Course module.
     * @param int $groupid Moodle group, 0 for the Default group.
     * @return string e.g. "moodle-1a2b3c4d5e6f-cm42-g7"
     */
    public static function inner_check_key($cm, $groupid) {
        return sprintf(
            '%scm%d-g%d',
            plagiarism_pchkorg_service_login::site_prefix(),
            $cm->id,
            $groupid
        );
    }

    /**
     * Queue again the group submission files which failed only because they could not be resolved.
     *
     * Before v3.21.0 a group submission file never resolved, was retried until
     * it ran out of attempts and was left failed with no message. Those were
     * never sent, so queueing them again cannot produce a second check. Files
     * the service turned down carry its message and are left alone, as is
     * everything individual.
     *
     * @return int How many records were queued again.
     */
    public static function requeue_unresolved() {
        global $DB;

        $sql = "SELECT f.id
                  FROM {plagiarism_pchkorg_files} f
                  JOIN {course_modules} cm ON cm.id = f.cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {assign_submission} s ON s.id = f.itemid AND s.assignment = cm.instance
                 WHERE f.state = :state
                   AND f.fileid IS NOT NULL
                   AND f.textid IS NULL
                   AND f.message IS NULL
                   AND s.userid = 0";
        $ids = $DB->get_fieldset_sql($sql, ['state' => plagiarism_pchkorg_state::LOCAL_ERROR]);

        foreach (array_chunk($ids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->execute(
                "UPDATE {plagiarism_pchkorg_files}
                    SET state = :state, attempt = 0
                  WHERE id $insql",
                $params + ['state' => plagiarism_pchkorg_state::LOCAL_QUEUED]
            );
        }

        return count($ids);
    }
}
