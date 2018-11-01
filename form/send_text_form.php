<?php

// moodleform is defined in formslib.php
require_once("$CFG->libdir/formslib.php");

class send_text_form extends moodleform
{
    // Add elements to form
    public function definition()
    {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('hidden', 'fileid', '');
        $mform->setType('fileid', PARAM_INT);
        $mform->addElement('hidden', 'cmid', '');
        $mform->setType('cmid', PARAM_INT);

        $this->add_action_buttons(false, get_string('pchkorg_submit', 'plagiarism_pchkorg'));

    }

    // Custom validation should be added here
    function validation($data, $files)
    {
        return array();
    }
}
