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
 * States used by the submission pipeline.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * States used by the submission pipeline.
 *
 * Two distinct sets of values live here.
 *
 * The LOCAL_* constants are stored in the state column of the
 * plagiarism_pchkorg_files table and describe where a submission sits in this
 * plugin's own queue: queued by an event observer, sent to the service by the
 * send_submissions task, and finally resolved to a report by update_reports.
 *
 * The REMOTE_* constants are the states the service itself reports for a text.
 * They are a mirror of UVO\PlagcheckBundle\Entity\Text in the
 * PlagiarismCheck.org backend and must be kept in step with it.
 *
 * LOCAL_CHECKED and REMOTE_CHECKED deliberately share the value 5: when a
 * report is ready the poll copies the remote state straight into the local
 * record. The two are still named separately because they are read from
 * different places and only one of them is ours to change.
 */
class plagiarism_pchkorg_state {
    /** @var int Report is ready; score and reportid are populated. */
    const LOCAL_CHECKED = 5;

    /** @var int Captured from a Moodle event and waiting to be sent. */
    const LOCAL_QUEUED = 10;

    /** @var int Terminal failure. The record is not retried and is hidden from the UI. */
    const LOCAL_ERROR = 11;

    /** @var int Uploaded to the service, waiting for a report. */
    const LOCAL_SENT = 12;

    /** @var int Service state: text record created. */
    const REMOTE_CREATED = 1;

    /** @var int Service state: text stored. */
    const REMOTE_STORED = 2;

    /** @var int Service state: submitted for checking. */
    const REMOTE_SUBMITTED = 3;

    /** @var int Service state: check failed permanently. */
    const REMOTE_FAILED = 4;

    /** @var int Service state: check finished, report available. */
    const REMOTE_CHECKED = 5;

    /** @var int Service state: check failed temporarily. */
    const REMOTE_TEMP_FAILED = 6;

    /** @var int Service state: text dropped. */
    const REMOTE_DROPPED = 7;

    /** @var int Service state: text erased. */
    const REMOTE_ERASED = 8;

    /**
     * Remote states which are final: the service will not change them again, so the
     * poll can stop asking and resolve the local record one way or the other.
     *
     * @return int[]
     */
    public static function remote_final_states() {
        return [
            self::REMOTE_FAILED,
            self::REMOTE_CHECKED,
            self::REMOTE_TEMP_FAILED,
            self::REMOTE_DROPPED,
            self::REMOTE_ERASED,
        ];
    }
}
