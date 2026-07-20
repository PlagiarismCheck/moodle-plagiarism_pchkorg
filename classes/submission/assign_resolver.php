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
 * Locates assignment submission content for checking.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/resolver.php');

/**
 * Assignment submissions: online text, or an attached file.
 */
class plagiarism_pchkorg_assign_resolver implements plagiarism_pchkorg_resolver {
    /**
     * Locate the online text of an assignment submission.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_text($filedb, $cm) {
        global $DB;

        $submission = $DB->get_record(
            'assignsubmission_onlinetext',
            ['submission' => $filedb->itemid],
            '*'
        );
        if (!$submission) {
            return null;
        }

        return plagiarism_pchkorg_payload::from_html(
            $submission->id,
            $submission->id,
            $submission->onlinetext,
            sprintf('%s-submission.txt', $submission->id)
        );
    }

    /**
     * Describe a file attached to an assignment submission.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @param stored_file $file
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_file($filedb, $cm, $file) {
        $submissionid = self::assign_submission_id($filedb, $cm);
        if (null === $submissionid) {
            return null;
        }

        return plagiarism_pchkorg_payload::from_file($submissionid, $file);
    }

    /**
     * The service keys submissions by the assignment submission id.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @return int|null Null when the submission has since been removed.
     */
    public static function assign_submission_id($filedb, $cm) {
        global $DB;

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $cm->instance,
            'userid' => $filedb->userid,
            'id' => $filedb->itemid,
        ], 'id');

        return $submission ? $submission->id : null;
    }
}
