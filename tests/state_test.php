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

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/state.php');

/**
 * Characterization tests for the pipeline state constants.
 *
 * The REMOTE_* values are a mirror of the service's own text states. They are
 * pinned here so that renaming or reordering them cannot silently change the
 * wire contract, which would strand queued submissions.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_test extends \basic_testcase {
    /**
     * Local queue states keep the literal values already stored in the
     * plagiarism_pchkorg_files table on every existing installation.
     */
    public function test_local_states_match_stored_values(): void {
        $this->assertSame(5, \plagiarism_pchkorg_state::LOCAL_CHECKED);
        $this->assertSame(10, \plagiarism_pchkorg_state::LOCAL_QUEUED);
        $this->assertSame(11, \plagiarism_pchkorg_state::LOCAL_ERROR);
        $this->assertSame(12, \plagiarism_pchkorg_state::LOCAL_SENT);
    }

    /**
     * Remote states mirror UVO\PlagcheckBundle\Entity\Text in the service.
     */
    public function test_remote_states_mirror_the_service(): void {
        $this->assertSame(1, \plagiarism_pchkorg_state::REMOTE_CREATED);
        $this->assertSame(2, \plagiarism_pchkorg_state::REMOTE_STORED);
        $this->assertSame(3, \plagiarism_pchkorg_state::REMOTE_SUBMITTED);
        $this->assertSame(4, \plagiarism_pchkorg_state::REMOTE_FAILED);
        $this->assertSame(5, \plagiarism_pchkorg_state::REMOTE_CHECKED);
        $this->assertSame(6, \plagiarism_pchkorg_state::REMOTE_TEMP_FAILED);
        $this->assertSame(7, \plagiarism_pchkorg_state::REMOTE_DROPPED);
        $this->assertSame(8, \plagiarism_pchkorg_state::REMOTE_ERASED);
    }

    /**
     * A ready report is the one state shared by both sets, because the poll
     * copies the remote value straight into the local record.
     */
    public function test_checked_is_shared_between_both_sets(): void {
        $this->assertSame(
            \plagiarism_pchkorg_state::REMOTE_CHECKED,
            \plagiarism_pchkorg_state::LOCAL_CHECKED
        );
    }

    /**
     * The poll resolves a record only on states the service considers final.
     */
    public function test_remote_final_states(): void {
        $this->assertSame(
            [4, 5, 6, 7, 8],
            \plagiarism_pchkorg_state::remote_final_states()
        );
    }
}
