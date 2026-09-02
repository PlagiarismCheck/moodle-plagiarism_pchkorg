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
 * Test double for the activity form wrapper.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The two things plagiarism_pchkorg_coursemodule_standard_elements asks of the
 * activity form it is passed.
 *
 * Moodle hands the hook a moodleform_mod, which cannot be built outside a real
 * form submission. Written by hand rather than with createMock() for the same
 * reason as plagiarism_pchkorg_fake_transport: the supported Moodle range spans
 * PHPUnit 7 to 11, whose mocking APIs are not compatible.
 */
class plagiarism_pchkorg_fake_form_wrapper {
    /** @var stdClass Course the activity is being edited in. */
    private $course;

    /** @var stdClass Activity being edited, carrying at least a modulename. */
    private $current;

    /**
     * Construct.
     *
     * @param stdClass $course
     * @param string $modulename Activity type, as the mod form reports it.
     */
    public function __construct($course, $modulename = 'assign') {
        $this->course = $course;
        $this->current = (object) ['modulename' => $modulename];
    }

    /**
     * Course.
     *
     * @return stdClass
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * Activity being edited.
     *
     * @return stdClass
     */
    public function get_current() {
        return $this->current;
    }
}
