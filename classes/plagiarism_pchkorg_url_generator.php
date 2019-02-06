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
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class plagiarism_pchkorg_url_generator
 */
class plagiarism_pchkorg_url_generator {
    /**
     * @param $cmid
     * @param $fileid
     * @return moodle_url
     * @throws moodle_exception
     */
    public function get_check_url($cmid, $fileid) {
        return new moodle_url(sprintf(
                        '/plagiarism/pchkorg/page/report.php?cmid=%s&file=%s',
                        intval($cmid),
                        intval($fileid)
                )
        );
    }

    /**
     * @return moodle_url
     * @throws moodle_exception
     */
    public function get_status_url() {
        return new moodle_url('/plagiarism/pchkorg/page/status.php');
    }
}
