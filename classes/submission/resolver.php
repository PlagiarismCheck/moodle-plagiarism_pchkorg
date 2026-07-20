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
 * Contract for locating submission content per activity type.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/payload.php');

/**
 * Turns a queued record into something sendable.
 *
 * One implementation per supported activity type. Everything else about the
 * send, filters, credentials, retry accounting, is identical across activities
 * and lives in plagiarism_pchkorg_sender.
 */
interface plagiarism_pchkorg_resolver {
    /**
     * Resolve a record which carries text rather than an attached file.
     *
     * Returning null means the content could not be matched right now, which
     * leaves the record queued for a later attempt.
     *
     * @param stdClass $filedb Queue record.
     * @param stdClass $cm Course module.
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_text($filedb, $cm);

    /**
     * Resolve a record which carries an attached file.
     *
     * The file is loaded by the caller, since a missing file means the same
     * thing for every activity type.
     *
     * @param stdClass $filedb Queue record.
     * @param stdClass $cm Course module.
     * @param stored_file $file
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_file($filedb, $cm, $file);
}
