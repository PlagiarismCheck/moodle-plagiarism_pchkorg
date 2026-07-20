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
 * Locates forum post content for checking.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/resolver.php');
require_once(__DIR__ . '/assign_resolver.php');

/**
 * Forum posts, or files attached to them.
 */
class plagiarism_pchkorg_forum_resolver implements plagiarism_pchkorg_resolver {
    /**
     * Posts are matched on the signature of either the raw message or its
     * stripped form, because both have been used when queueing.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_text($filedb, $cm) {
        global $DB;

        $post = $DB->get_record_sql(
            "SELECT subject, message"
            . " FROM {forum_posts}"
            . " WHERE {forum_posts}.id = ?",
            [$filedb->itemid]
        );
        if (!$post) {
            return null;
        }

        $signature = sha1($post->message);
        if ($signature !== $filedb->signature) {
            $signature = sha1(trim(strip_tags($post->message)));
            if ($signature !== $filedb->signature) {
                return null;
            }
        }

        return plagiarism_pchkorg_payload::from_html(
            $post->subject,
            $signature,
            $post->message,
            sprintf('%s-forum.txt', $filedb->itemid)
        );
    }

    /**
     * Describe a file attached to a forum post.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @param stored_file $file
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_file($filedb, $cm, $file) {
        $submissionid = plagiarism_pchkorg_assign_resolver::assign_submission_id($filedb, $cm);
        if (null === $submissionid) {
            return null;
        }

        return plagiarism_pchkorg_payload::from_file($submissionid, $file);
    }
}
