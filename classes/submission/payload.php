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
 * Describes one document to send to the service.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * What to send to the service for one queued record.
 */
class plagiarism_pchkorg_payload {
    /** @var mixed Identifies the submission to the service. */
    public $submissionid;

    /** @var mixed Identifies the attachment to the service. */
    public $attachmentid;

    /** @var string Document content. */
    public $content;

    /** @var string MIME type of the content. */
    public $mime;

    /** @var string Filename; the service picks its parser from the extension. */
    public $filename;

    /**
     * Build a payload.
     *
     * @param mixed $submissionid
     * @param mixed $attachmentid
     * @param string $content
     * @param string $mime
     * @param string $filename
     */
    public function __construct($submissionid, $attachmentid, $content, $mime, $filename) {
        $this->submissionid = $submissionid;
        $this->attachmentid = $attachmentid;
        $this->content = $content;
        $this->mime = $mime;
        $this->filename = $filename;
    }

    /**
     * Build a payload from a stored Moodle file.
     *
     * @param mixed $submissionid
     * @param stored_file $file
     * @return plagiarism_pchkorg_payload
     */
    public static function from_file($submissionid, $file) {
        return new self(
            $submissionid,
            $file->get_id(),
            $file->get_content(),
            $file->get_mimetype(),
            $file->get_filename()
        );
    }

    /**
     * Build a payload from HTML content, which the service takes as plain text.
     *
     * @param mixed $submissionid
     * @param mixed $attachmentid
     * @param string $html
     * @param string $filename
     * @return plagiarism_pchkorg_payload
     */
    public static function from_html($submissionid, $attachmentid, $html, $filename) {
        return new self(
            $submissionid,
            $attachmentid,
            html_to_text($html, 75, false),
            'text/plain',
            $filename
        );
    }
}
