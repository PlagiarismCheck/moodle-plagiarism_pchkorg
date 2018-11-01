<?php


class plagiarism_setup_form extends moodleform {

// Define the form
    function definition () {
        global $CFG;

        $mform = &$this->_form;

        $mform->addElement(
            'select',
            $setting = 'pchkorg_use',
            self::trans('pchkorg_use'),
            [get_string('no'), get_string('yes')]
        );
        $mform->addHelpButton('pchkorg_use', 'pchkorg_use', 'plagiarism_pchkorg');

        if (!isset($mform->exportValues()[$setting]) || is_null($mform->exportValues()[$setting])) {
            $mform->setDefault($setting, false);
        }

        $mform->addElement('password', 'pchkorg_token', self::trans('pchkorg_token'));
        $mform->addHelpButton('pchkorg_token', 'pchkorg_token', 'plagiarism_pchkorg');
        $mform->addRule('pchkorg_token', null, 'required', null, 'client');
        $mform->setType('pchkorg_token', PARAM_TEXT);

        $this->add_action_buttons(true);
    }

    public static function trans($message, $param = null)
    {
        return get_string($message, 'plagiarism_pchkorg', $param);
    }
}

