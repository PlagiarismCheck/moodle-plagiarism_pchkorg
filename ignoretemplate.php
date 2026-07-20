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
 * Download proxy for ignored activity templates.
 *
 * Moodle fetches the file server to server and streams it back, so the
 * institutional API token never reaches the browser.
 *
 * Everything except the request handling lives in
 * plagiarism_pchkorg_ignore_template_download, which is testable; this script
 * is the part that cannot be, because it ends in send_file().
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/ignore_template_download.php');

$cmid = required_param('cmid', PARAM_INT);
$templateid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();

$file = plagiarism_pchkorg_ignore_template_download::resolve($cm, $templateid, $USER);

send_file(
    $file->content,
    $file->filename,
    0,
    0,
    true,
    true,
    $file->mime
);
