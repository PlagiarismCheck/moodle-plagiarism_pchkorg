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
 * Class defined plugin settings form.
 */
class plagiarism_pchkorg_setup_form extends moodleform {

    /**
     *
     * Method defined plugin settings form.
     *
     * @throws coding_exception
     */
    public function definition() {

        $mform = &$this->_form;

        $mform->addElement(
                'select',
                $setting = 'pchkorg_use',
                get_string('pchkorg_use', 'plagiarism_pchkorg'),
                array(get_string('no'), get_string('yes'))
        );
        $mform->addHelpButton('pchkorg_use', 'pchkorg_use', 'plagiarism_pchkorg');

        if (!isset($mform->exportValues()[$setting]) || is_null($mform->exportValues()[$setting])) {
            $mform->setDefault($setting, false);
        }

        $mform->addElement('password', 'pchkorg_token', get_string('pchkorg_token', 'plagiarism_pchkorg'));
        $mform->addHelpButton('pchkorg_token', 'pchkorg_token', 'plagiarism_pchkorg');
        $mform->addRule('pchkorg_token', null, 'required', null, 'client');
        $mform->setType('pchkorg_token', PARAM_TEXT);

        $this->add_action_buttons(true);
    }
}
