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

global $CFG;

require_once($CFG->dirroot . '/plagiarism/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_api_provider.php');
require_once(__DIR__ . '/classes/permissions/capability.class.php');

use plagiarism_pchkorg\classes\permissions\capability;

function pchkorg_check_pchkorg_min_percent($value)
{
    return 0 <= $value && $value < 100;
}

function plagiarism_pchkorg_coursemodule_standard_elements($formwrapper, $mform)
{
    $context = context_course::instance($formwrapper->get_course()->id);
    $modulename = $formwrapper->get_current()->modulename;
    $allowedmodules = array('assign', 'mod_assign');
    if (!$context || !isset($modulename)) {
        return;
    }
    global $DB;

    $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

    $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
    $enabled = has_capability(capability::ENABLE, $context);
    if ( '1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_quiz')) {
        $allowedmodules[] = 'quiz';
    }
    if ( '1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_forum')) {
        $allowedmodules[] = 'forum';
    }
    if ('1' == $config && $enabled) {
        if (!in_array($modulename, $allowedmodules, true)) {
            return;
        }
        $defaultcmid = null;
        $cm = optional_param('update', $defaultcmid, PARAM_INT);
        $minpercent = $pchkorgconfigmodel->get_system_config('pchkorg_min_percent');
        $exportedvalues = $mform->exportValues(array());
        if (!is_array($exportedvalues)) {
            $exportedvalues = array();
        }
        if (!isset($exportedvalues['pchkorg_exclude_self_plagiarism'])
            || is_null($exportedvalues['pchkorg_exclude_self_plagiarism'])) {
            $mform->setDefault('pchkorg_exclude_self_plagiarism', 1);
        }
        if (!isset($exportedvalues['pchkorg_include_referenced'])
            || is_null($exportedvalues['pchkorg_include_referenced'])) {
            $mform->setDefault('pchkorg_include_referenced', 0);
        }
        if (!isset($exportedvalues['pchkorg_include_citation'])
            || is_null($exportedvalues['pchkorg_include_citation'])) {
            $mform->setDefault('pchkorg_include_citation', 0);
        }

        if (!isset($exportedvalues['pchkorg_student_can_see_report']) || is_null(
                $exportedvalues['pchkorg_student_can_see_report']
            )) {
            $mform->setDefault('pchkorg_student_can_see_report', 1);
        }
        if (!isset($exportedvalues['pchkorg_student_can_see_widget']) || is_null(
                $exportedvalues['pchkorg_student_can_see_widget']
            )) {
            $mform->setDefault('pchkorg_student_can_see_widget', 1);
        }

        if (null === $cm) {
            if (!isset($exportedvalues['pchkorg_module_use'])
                || is_null($exportedvalues['pchkorg_module_use'])) {
                $mform->setDefault('pchkorg_module_use', '1');
            }
        } else {
            $records = $DB->get_records('plagiarism_pchkorg_config', array(
                'cm' => $cm,
            ));
            if (!empty($records)) {
                foreach ($records as $record) {
                    $mform->setDefault($record->name, $record->value);
                }
            }
        }
        $mform->addElement(
            'header',
            'plagiarism_pchkorg',
            get_string('pluginname', 'plagiarism_pchkorg')
        );
        $mform->addElement(
            'select',
            'pchkorg_module_use',
            get_string('pchkorg_module_use', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );
        $mform->addHelpButton('pchkorg_module_use', 'pchkorg_module_use', 'plagiarism_pchkorg');

        $canchangeminpercent = has_capability(capability::CHANGE_MIN_PERCENT_FILTER, $context);
        if ($canchangeminpercent) {
            $dissabledattribute = '';
        } else {
            $dissabledattribute = 'disabled="disabled"';
        }
        $mform->registerRule(
            'check_pchkorg_min_percent',
            'callback',
            'pchkorg_check_pchkorg_min_percent'
        );
        $label = get_string('pchkorg_min_percent', 'plagiarism_pchkorg');
        if (!empty($minpercent)) {
            $label = \str_replace('X%', $minpercent . '%', $label);
        }

        $mform->addElement(
            'text',
            'pchkorg_min_percent',
            $label,
            $dissabledattribute
        );
        $mform->addHelpButton('pchkorg_min_percent', 'pchkorg_min_percent', 'plagiarism_pchkorg');
        $mform->addRule(
            'pchkorg_min_percent',
            get_string('pchkorg_min_percent_range', 'plagiarism_pchkorg'),
            'check_pchkorg_min_percent'
        );
        $mform->setType('pchkorg_min_percent', PARAM_INT);

        $mform->addElement(
            'select',
            'pchkorg_exclude_self_plagiarism',
            get_string('pchkorg_exclude_self_plagiarism', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );

        $mform->addElement(
            'select',
            'pchkorg_include_referenced',
            get_string('pchkorg_include_referenced', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );

        $mform->addElement(
            'select',
            'pchkorg_include_citation',
            get_string('pchkorg_include_citation', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );

        $mform->addElement(
            'select',
            'pchkorg_student_can_see_widget',
            get_string('pchkorg_student_can_see_widget', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );

        $mform->addElement(
            'select',
            'pchkorg_student_can_see_report',
            get_string('pchkorg_student_can_see_report', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );

        $mform->addElement(
            'select',
            'pchkorg_check_ai',
            get_string('pchkorg_check_ai', 'plagiarism_pchkorg'),
            array(get_string('no'), get_string('yes'))
        );
        $mform->setDefault('pchkorg_check_ai', 1);
    }
}

function plagiarism_pchkorg_coursemodule_edit_post_actions($data, $course)
{
    global $DB;

    $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

    $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
    if ('1' != $config) {
        return $data;
    }

    $fields = array(
        'pchkorg_module_use',
        'pchkorg_min_percent',
        'pchkorg_include_citation',
        'pchkorg_include_referenced',
        'pchkorg_exclude_self_plagiarism',
        'pchkorg_student_can_see_widget',
        'pchkorg_student_can_see_report',
        'pchkorg_check_ai'
    );

    $records = $DB->get_records('plagiarism_pchkorg_config', array(
        'cm' => $data->coursemodule
    ));

    $context = context_module::instance($data->coursemodule);
    $canchangeminpercent = has_capability(capability::CHANGE_MIN_PERCENT_FILTER, $context);

    foreach ($fields as $field) {
        $isfounded = false;
        foreach ($records as $record) {
            if ($record->name === $field) {
                $isfounded = true;
                if ($field === 'pchkorg_min_percent' && !$canchangeminpercent) {
                    $DB->delete_records('plagiarism_pchkorg_config', array('id' => $record->id));
                    break;
                }
                if ($field === 'pchkorg_min_percent' && 0 == $data->{$record->name}) {
                    $DB->delete_records('plagiarism_pchkorg_config', array('id' => $record->id));
                    break;
                }
                $record->value = $data->{$record->name};
                $DB->update_record('plagiarism_pchkorg_config', $record);
                break;
            }
        }
        if (!$isfounded && isset($data->{$field})) {
            if ($field === 'pchkorg_min_percent' && !$canchangeminpercent) {
                continue;
            }
            if ($field === 'pchkorg_min_percent' && 0 == $data->{$field}) {
                continue;
            }
            $insert = new \stdClass();
            $insert->cm = $data->coursemodule;
            $insert->name = $field;
            $insert->value = $data->{$field};

            $DB->insert_record('plagiarism_pchkorg_config', $insert);
        }
    }

    return $data;
}

/**
 * Class plagiarism_plugin_pchkorg
 */
class plagiarism_plugin_pchkorg extends plagiarism_plugin {


    /**
     * hook to allow plagiarism specific information to be displayed beside a submission.
     *
     * @param array $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     *
     */
    public function get_links($linkarray) {
        global $DB, $USER, $PAGE;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $isdebugenabled = $pchkorgconfigmodel->get_system_config('pchkorg_enable_debug') === '1';
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

        $cmid = $linkarray['cmid'];
        if (array_key_exists('file', $linkarray)) {
            $file = $linkarray['file'];
        } else {
            // Online text submission.
            $file = null;
        }

        // We can do nothing with submissions which we can not handle.
        if (null !== $file && !$apiprovider->is_supported_mime($file->get_mimetype())) {
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string('pchkorg_debug_mime', 'plagiarism_pchkorg'),
                    $file->get_mimetype()),
                $isdebugenabled
            );
        }

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return $this->exit_message(
                get_string('pchkorg_debug_disabled', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }
        $context = null;
        $component = !empty($linkarray['component']) ? $linkarray['component'] : '';
        if ($cmid === null && $component == 'qtype_essay' && !empty($linkarray['area'])) {
            $questions = question_engine::load_questions_usage_by_activity($linkarray['area']);
            $context = $questions->get_owning_context();
            if ($cmid === null && $context->contextlevel == CONTEXT_MODULE) {
                $cmid = $context->instanceid;
            }
        }

        if (!empty($cmid)) {
            $context = context_module::instance($cmid);// Get context of course.
        }

        if (empty($context)) {
            return $this->exit_message(
                get_string('pchkorg_debug_empty_context', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }
        $roleDatas = get_user_roles($context, $USER->id, true);
        $roles = array();
        foreach ($roleDatas as $rolesData) {
            $roles[] = strtolower($rolesData->shortname);
        }
        // Moodle has multiple roles in courses.
        $isstudent = in_array('student', $roles)
            && !in_array('teacher', $roles)
            && !in_array('editingteacher', $roles)
            && !in_array('managerteacher', $roles);

        $canview = has_capability(capability::VIEW_SIMILARITY, $context);

        $pchkorgconfigmodel->show_widget_for_student($cmid);

        if (!$canview) {
            return $this->exit_message(
                get_string('pchkorg_debug_user_has_no_permission', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        // SQL will be called only once per page. There is static result inside.
        if (!$pchkorgconfigmodel->is_enabled_for_module($cmid)) {
            return $this->exit_message(
                get_string('pchkorg_debug_disabled_acitivity', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        // Widget for student is disabled.
        if ($isstudent && $pchkorgconfigmodel->show_widget_for_student($cmid) === false) {
            return $this->exit_message(
                get_string('pchkorg_debug_student_not_allowed_see_widget', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        $isreportallowed = !$isstudent
            || $pchkorgconfigmodel->show_report_for_student($cmid) === true
            || $pchkorgconfigmodel->show_report_for_student($cmid) === null;

        // Only for some type of account, method will call a remote HTTP API.
        // The API will be called only once, because result is static.
        // Also, there is timeout 8 seconds for response.
        // Even if service will be unavailable, method will try call API only once.
        // Also, we don't use raw user email.
        if (!$apiprovider->is_group_member($USER->email)) {
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string('pchkorg_debug_not_member', 'plagiarism_pchkorg'),
                    $USER->email),
                $isdebugenabled
            );
        }

        $isgranted = !empty($context) && has_capability('mod/assign:view', $context, null);
        if (!$isgranted) {
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string('pchkorg_debug_user_has_no_capability', 'plagiarism_pchkorg'),
                    'mod/assign:view'),
                $isdebugenabled
            );
        }

        $where = new \stdClass();
        $where->cm = $cmid;
        if ($file === null) {
            $where->signature = sha1($linkarray['content']);
            $where->fileid = null;
        } else {
            $where->fileid = $file->get_id();
        }
        $filerecords = $DB->get_records('plagiarism_pchkorg_files', (array) $where,
            'id', '*', 0, 1);

        if (!$filerecords && $file === null) {
            $where->signature = sha1(trim(strip_tags($linkarray['content'])));
            $where->fileid = null;
            $filerecords = $DB->get_records('plagiarism_pchkorg_files', (array) $where,
                'id', '*', 0, 1);
        }

        if ($filerecords) {
            $filerecord = end($filerecords);

            $img = new moodle_url('/plagiarism/pchkorg/pix/icon.png');
            $imgsrc = $img->__toString();

            // Text had been successfully checked.
            if ($filerecord->state == 5) {
                $action = $apiprovider->get_report_action($filerecord->textid);
                $reporttoken = $apiprovider->generate_api_token();
                $score = $filerecord->score;
                $isaienabled = '1' === $pchkorgconfigmodel->get_filter_for_module($cmid, 'pchkorg_check_ai');

                if (isset($filerecord->scoreai) && $isaienabled) {
                    $title = sprintf(
                        get_string('pchkorg_label_title_ai', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score,
                        $filerecord->scoreai
                    );
                    $label = sprintf(
                        get_string('pchkorg_label_result_ai', 'plagiarism_pchkorg'), 
                        $filerecord->textid, 
                        $score,
                        $filerecord->scoreai
                    );
                } else {
                    $title = sprintf(
                        get_string('pchkorg_label_title', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score
                    );
                    $label = sprintf(
                        get_string('pchkorg_label_result', 'plagiarism_pchkorg'), 
                        $filerecord->textid, 
                        $score
                    );
                }

                if ($score < 30) {
                    $color = '#63EC80';
                } else if (30 < $score && $score < 60) {
                    $color = '#F7B011';
                } else {
                    $color = '#F04343';
                }
                $jsdata = array(
                    'id' => $filerecord->id,
                    'title' => $title,
                    'action' => $action,
                    'token' => $reporttoken,
                    'label' => $label,
                    'color' => $color,
                    'isreportallowed' => $isreportallowed,
                );
                static $isjsfuncinjected = false;
                if (!$isjsfuncinjected) {
                    $isjsfuncinjected = true;
                    $PAGE->requires->js_amd_inline(
                        "
window.plagiarism_check_data = [];
window.plagiarism_check_full_report = function (action, token) {
    const form = document.createElement('form');
    const element1 = document.createElement('input');
    const element2 = document.createElement('input');

    form.method = 'POST';
    form.target = '_blank';
    form.action = action;

    element1.value = 'moodle';
    element1.name = 'lms-type';
    element1.type = 'hidden';
    form.appendChild(element1);

    element2.value = token;
    element2.name = 'token';
    element2.type = 'hidden';
    form.appendChild(element2);

    document.body.appendChild(form);

    form.submit();
};

require(['jquery'], function ($) {
    $(function () {
        var spans = window.document.getElementsByClassName('plagiarism-pchkorg-widget');
        for (var s in spans) {
            var span = spans[s];
            if (span) {
                for (var c in span.classList) {
                    var classname = span.classList[c];
                    if (classname && classname.includes('plagiarism-pchkorg-widget-id-')) {
                        var id = classname.replace('plagiarism-pchkorg-widget-id-', '');
                        if (id) {
                            for (var d in window.plagiarism_check_data) {
                                var data = window.plagiarism_check_data[d];
                                if (data && data.id == id) {
                                    var a = document.createElement('a');
                                    a.setAttribute('href', '#');
                                    a.setAttribute('title', data.title);
                                    a.setAttribute('data-id', data.id);
                                    a.style.fontFamily =  'Roboto';
                                    a.style.fontStyle = 'normal';
                                    a.style.fontWeight =  '400';
                                    a.style.fontSize =  '16px';
                                    a.style.textAlign =  'center';
                                    a.style.padding = '4px 16px';
                                    a.style.textDecoration = 'none';
                                    a.style.backgroundColor = data.color;
                                    a.style.color = 'black';
                                    a.style.cursor = 'pointer';
                                    a.style.borderRadius = '4px 4px 4px 4px';
                                    a.style.margin = '4px';
                                    a.style.display = 'inline-block';
                                    var label = document.createTextNode(data.label);
                                    a.appendChild(label);
                                    span.appendChild(a);
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }

        $(window.document.body).on('click', '.plagiarism-pchkorg-widget', function(e) {
            var id = $(e.target).closest('a').attr('data-id')
            if (id) {
                for (var d in window.plagiarism_check_data) {
                    var data = window.plagiarism_check_data[d];
                    if (data && data.id == id && data.isreportallowed) {
                        window.plagiarism_check_full_report(data.action, data.token);
                        break;
                    }
                }
            }
            return false;
        })
    });
});
"
                    );
                }

                $PAGE->requires->js_amd_inline("window.plagiarism_check_data.push(".json_encode($jsdata).")");


                return '
                <span class="plagiarism-pchkorg-widget plagiarism-pchkorg-widget-id-'.$filerecord->id.'"></span>';
            } else if ($filerecord->state == 10) {
                $label = get_string('pchkorg_label_queued', 'plagiarism_pchkorg');
                return '
                <span style="padding: 5px 3px;
text-decoration: none;
background-color: #eeeded;
color: black;
border-radius: 3px 3px 3px 3px;
margin: 4px;
display: inline-block;"
            href="#" class="plagiarism_pchkorg_report_id_score">
                <img src="' . $imgsrc . '" alt="logo" width="20" />
                ' . $label . '
            </span>';
            } else if ($filerecord->state == 12) {
                $label = sprintf(get_string('pchkorg_label_sent', 'plagiarism_pchkorg'), $filerecord->textid);
                return '
                <span style="padding: 5px 3px;
text-decoration: none;
background-color: #eeeded;
color: black;
border-radius: 3px 3px 3px 3px;
margin: 4px;
display: inline-block;"
            href="#" class="plagiarism_pchkorg_report_id_score">
                <img src="' . $imgsrc . '" alt="logo" width="20" />
                ' . $label . '
            </span>';
            } else {
                return $this->exit_message(
                    sprintf(
                        '%s (%s)',
                        get_string('pchkorg_debug_status_error', 'plagiarism_pchkorg'),
                        $filerecord->state),
                    $isdebugenabled
                );
            }
        }

        return $this->exit_message(
            sprintf(
                '%s (%s)',
                get_string('pchkorg_debug_no_check', 'plagiarism_pchkorg'),
                $cmid),
            $isdebugenabled
        );
    }

    /**
     * Render message with reason why do we stop plugin.
     *
     * @param string $message - exit message
     * @param bool $debug - is debug enabled.
     * @return string
     */
    private function exit_message($message, $debug) {
        if ($debug) {
            return $message;
        }

        return '';
    }

    /**
     * hook to save plagiarism specific settings on a module settings page
     *
     * @param object $data - data from an mform submission.
     * @throws dml_exception
     */
    public function save_form_elements($data) {
        return plagiarism_pchkorg_coursemodule_edit_post_actions($data, null);
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission.
     *
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid) {
        global $OUTPUT;

        if (empty($cmid)) {
            return '';
        }
        // Get course details.
        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            return '';
        }

        $configmodel = new plagiarism_pchkorg_config_model();
        $enabled = $configmodel->get_system_config('pchkorg_use');
        if ($enabled !== '1') {
            return '';
        }
        $modulename = $cm->modname;
        $allowedmodules = array('assign', 'mod_assign');
        if ($configmodel->get_system_config('pchkorg_enable_quiz')) {
            $allowedmodules[] = 'quiz';
        }
        if ($configmodel->get_system_config('pchkorg_enable_forum')) {
            $allowedmodules[] = 'forum';
        }
        if (!in_array($modulename, $allowedmodules, true)) {
            return '';
        }

        if (!$configmodel->is_enabled_for_module($cmid)) {
            return '';
        }

        $result = '';

        $result .= $OUTPUT->box_start('generalbox boxaligncenter', 'intro');

        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        $formatoptions->cmid = $cmid;

        $result .= '<div style="background-color: #d5ffd5; padding: 10px; border: 1px solid #b7dab7">';
        $result .= format_text(get_string('pchkorg_disclosure', 'plagiarism_pchkorg'), FORMAT_MOODLE, $formatoptions);
        $result .= '</div>';
        $result .= $OUTPUT->box_end();

        return $result;
    }

    /**
     *
     * Method will handle event assessable_uploaded.
     *
     * @param $eventdata
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    public function event_handler($eventdata) {
        global $USER, $DB;

        // Whitelist of supported events, ignore other.
        $issupportedevent = in_array($eventdata['eventtype'], array(
            "forum_attachment",
            "quiz_submitted",
            "assessable_submitted",
            "content_uploaded"
        ));
        if (!$issupportedevent) {
            return true;
        }

        $modulename = $eventdata['other']['modulename'];
        $allowedmodules = array('assign', 'mod_assign');
        // We support only assign module so just ignore all other.
        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        // Token is needed for API auth.
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);
        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }
        if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_quiz')) {
            $allowedmodules[] = 'quiz';
        }
        if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_forum')) {
            $allowedmodules[] = 'forum';
        }
        if (!in_array($modulename, $allowedmodules, true)) {
            return true;
        }

        // Receive couser moudle id.
        $cmid = $eventdata['contextinstanceid'];
        // Remove the event if the course module no longer exists.
        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $cmid);
        $context = context_module::instance($cm->id);
        if (!$cm) {
            return true;
        }

        // SQL will be called only once per page. There is static result inside.
        // Plugin is enabled for this module.
        if (!$pchkorgconfigmodel->is_enabled_for_module($cm->id)) {
            return true;
        }

        // Only for some type of account, method will call a remote HTTP API.
        // The API will be called only once, because result is static.
        // Also, there is timeout 8 seconds for response.
        // Even if service is unavailable, method will try call only once.
        // Also, we don't use raw users email.
        $ismemberresponse = $apiprovider->get_group_member_response($USER->email);
        if (!$ismemberresponse->is_member) {
            if ($ismemberresponse->is_auto_registration_enabled) {
                $name = $USER->firstname . ' ' . $USER->lastname;
                $roleDatas = get_user_roles($context, $USER->id, true);
                $roles = array();
                foreach ($roleDatas as $rolesData) {
                    $roles[] = strtolower($rolesData->shortname);
                }
                // Moodle has multiple roles in courses.
                $isstudent = !in_array('teacher', $roles)
                    && !in_array('editingteacher', $roles)
                    && !in_array('managerteacher', $roles);
                $isregistered = $apiprovider->auto_registrate_member($name, $USER->email, $isstudent ? 3 : 2);
                if (!$isregistered) {
                    return true;
                }
            } else {
                return true;
            }
        }

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        // Related user ID will be NULL if an instructor submits on behalf of a student who is in a group.
        // To get around this, we get the group ID, get the group members and set the author as the first student in the group.
        if ((empty($eventdata['relateduserid'])) && ($eventdata['other']['modulename'] == 'assign')
            && has_capability('mod/assign:editothersubmission', $context, $submitter)) {
            $moodlesubmission = $DB->get_record('assign_submission', array('id' => $eventdata['objectid']), 'id, groupid');
            if (!empty($moodlesubmission->groupid)) {
                $author = $this->get_first_group_author($cm->course, $moodlesubmission->groupid);
            }
        }

        if ($eventdata['other']['modulename'] === 'forum'
            && $eventdata['eventtype'] === 'forum_attachment') {
            if (!empty($eventdata['other']['content'])) {
                $content = trim(strip_tags($eventdata['other']['content']));
                if (strlen($content) > 80) {
                    $signature = sha1($content);
                    $filesconditions = array(
                        'signature' => $signature,
                        'cm' => $cmid,
                        'userid' => $USER->id,
                        'itemid' => $eventdata['objectid']
                    );
                    $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                    if (!$oldfile) {
                        $filerecord = new \stdClass();
                        $filerecord->fileid = null;
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->state = 10;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;

                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                }
            }
            if (!empty($eventdata['other']['pathnamehashes'])) {
                foreach ($eventdata['other']['pathnamehashes'] as $pathnamehash) {
                    $file = get_file_storage()->get_file_by_hash($pathnamehash);
                    if (!$file) {
                        // We can not find file so we do not send it in queue.
                        continue;
                    } else {
                        try {
                            // Check that we can fetch content without exception.
                            $content = $file->get_content();
                        } catch (Exception $e) {
                            // No we can not.
                            continue;
                        }
                    }
                    if ($file->get_filename() === '.') {
                        continue;
                    }
                    $filemime = $file->get_mimetype();

                    // File type is not supported.
                    if (!$apiprovider->is_supported_mime($filemime)) {
                        continue;
                    }
                    $signature = sha1($content);
                    $filesconditions = array(
                        'fileid' => $file->get_id()
                    );
                    $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                    if (!$oldfile) {
                        $filerecord = new \stdClass();
                        $filerecord->fileid = $file->get_id();
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->state = 10;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;

                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                }
            }

            return true;
        }

        if ($eventdata['other']['modulename'] === 'quiz'
            && $eventdata['eventtype'] === 'quiz_submitted') {

            $attempt = quiz_attempt::create($eventdata['objectid']);
            foreach ($attempt->get_slots() as $slot) {
                $questionattempt = $attempt->get_question_attempt($slot);
                $qtype = $questionattempt->get_question()->qtype;
                if ($qtype instanceof qtype_essay) {
                    $attachments = $questionattempt->get_last_qt_files('attachments', $eventdata['contextid']);
                    $content = $questionattempt->get_response_summary();
                    if (strlen($content) > 80) {
                        $signature = sha1($content);
                        $filesconditions = array(
                            'signature' => $signature,
                            'cm' => $cmid,
                            'userid' => $USER->id,
                            'itemid' => $eventdata['objectid']
                        );

                        $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                        if ($oldfile) {
                            // There is the same check in database, so we can skip this one.
                            return true;
                        }

                        $filerecord = new \stdClass();
                        $filerecord->fileid = null;
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->state = 10;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;

                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                    foreach ($attachments as $pathnamehash => $file) {
                        if (!$file) {
                            // We can not find file so we do not send it in queue.
                            continue;
                        } else {
                            try {
                                // Check that we can fetch content without exception.
                                $content = $file->get_content();
                            } catch (Exception $e) {
                                // No we can not.
                                continue;
                            }
                        }
                        if ($file->get_filename() === '.') {
                            continue;
                        }
                        $filemime = $file->get_mimetype();

                        // File type is not supported.
                        if (!$apiprovider->is_supported_mime($filemime)) {
                            continue;
                        }
                        $signature = sha1($content);
                        $filesconditions = array(
                            'fileid' => $file->get_id()
                        );
                        $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                        if (!$oldfile) {
                            $filerecord = new \stdClass();
                            $filerecord->fileid = $file->get_id();
                            $filerecord->cm = $cmid;
                            $filerecord->userid = $USER->id;
                            $filerecord->textid = null;
                            $filerecord->state = 10;
                            $filerecord->created_at = time();
                            $filerecord->itemid = $eventdata['objectid'];
                            $filerecord->signature = $signature;

                            $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                        }
                    }
                }
            }
        }

        // Get actual text content and files to be submitted for draft submissions.
        // As this won't be present in eventdata for certain event types.
        if ($eventdata['other']['modulename'] === 'assign'
            && $eventdata['eventtype'] === 'assessable_submitted') {

            // Get content.
            $moodlesubmission = $DB->get_record('assign_submission', array('id' => $eventdata['objectid']), 'id');

            $moodletextsubmission = $DB->get_record('assignsubmission_onlinetext',
                array('submission' => $moodlesubmission->id), 'onlinetext');

            if ($moodletextsubmission) {
                $eventdata['other']['content'] = $moodletextsubmission->onlinetext;
            }

            $filesconditions = array(
                'component' => 'assignsubmission_file',
                'itemid' => $moodlesubmission->id,
                'userid' => $author
            );

            $moodlefiles = $DB->get_records('files', $filesconditions);
            if ($moodlefiles) {
                $fs = get_file_storage();
                foreach ($moodlefiles as $filedb) {
                    $file = $fs->get_file_by_id($filedb->id);

                    if (!$file) {
                        // We can not find file so we do not send it in queue.
                        continue;
                    } else {
                        try {
                            // Check that we can fetch content without exception.
                            $content = $file->get_content();
                        } catch (Exception $e) {
                            // No we can not.
                            continue;
                        }
                    }

                    if ($file->get_filename() === '.') {
                        continue;
                    }
                    $filemime = $file->get_mimetype();

                    // File type is not supported.
                    if (!$apiprovider->is_supported_mime($filemime)) {
                        continue;
                    }

                    $filerecord = new \stdClass();
                    $filerecord->fileid = $file->get_id();
                    $filerecord->cm = $cmid;
                    $filerecord->userid = $USER->id;
                    $filerecord->textid = null;
                    $filerecord->state = 10;
                    $filerecord->created_at = time();
                    $filerecord->itemid = $eventdata['objectid'];
                    $filerecord->signature = sha1($content);

                    $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                }
            }
        }

        // Queue text content to send to plagiarismcheck.org.
        // If there was an error when creating the assignment then still queue the submission so it can be saved as failed.
        if ($eventdata['other']['modulename'] === 'assign'
            && in_array($eventdata['eventtype'], array("content_uploaded", "assessable_submitted"))
            && !empty($eventdata['other']['content'])) {

            $signature = sha1($eventdata['other']['content']);

            $filesconditions = array(
                'signature' => $signature,
                'cm' => $cmid,
                'userid' => $USER->id,
                'itemid' => $eventdata['objectid']
            );

            $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
            if ($oldfile) {
                // There is the same check in database, so we can skip this one.
                return true;
            }

            $filerecord = new \stdClass();
            $filerecord->fileid = null;
            $filerecord->cm = $cmid;
            $filerecord->userid = $USER->id;
            $filerecord->textid = null;
            $filerecord->state = 10;
            $filerecord->created_at = time();
            $filerecord->itemid = $eventdata['objectid'];
            $filerecord->signature = $signature;

            $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
        }

        return true;
    }

    /**
     *
     * Will find the first user in group assignment.
     *
     * @param $cmid
     * @param $groupid
     * @return mixed
     * @throws coding_exception
     */
    private function get_first_group_author($cmid, $groupid) {
        static $context;
        if (empty($context)) {
            $context = context_course::instance($cmid);
        }

        $groupmembers = groups_get_members($groupid, "u.id");
        foreach ($groupmembers as $author) {
            if (!has_capability('mod/assign:grade', $context, $author->id)) {
                return $author->id;
            }
        }
    }

    /**
     *
     * Method will be called by cron. Method sends queued files into plagiarism check system.
     *
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    public function cron_send_submissions() {
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }
        $filesconditions = array('state' => 10);
        $moodlefiles = $DB->get_records(
            'plagiarism_pchkorg_files',
            $filesconditions,
            'id',
            '*',
            0,
            20
        );

        if ($moodlefiles) {
            $fs = get_file_storage();
            foreach ($moodlefiles as $filedb) {
                $textid = null;
                $user = $DB->get_record('user', array('id' => $filedb->userid));
                // This is attached file.
                $cm = get_coursemodule_from_id('', $filedb->cm);
                // Filter for future search.
                $systemminpercent = $pchkorgconfigmodel->get_system_config('pchkorg_min_percent');
                // Module filter value has a bigger priority then system config value.
                $moduleminpercent = $pchkorgconfigmodel->get_filter_for_module($cm->id, 'pchkorg_min_percent');
                if ($moduleminpercent) {
                    $minpercent = $moduleminpercent;
                } else {
                    $minpercent = $systemminpercent;
                }
                $filters = array(
                    'include_references' => $pchkorgconfigmodel->get_filter_for_module(
                        $cm->id,
                        'pchkorg_include_referenced'
                    ),
                    'include_quotes' => $pchkorgconfigmodel->get_filter_for_module(
                        $cm->id,
                        'pchkorg_include_citation'
                    ),
                    'exclude_self_plagiarism' => $pchkorgconfigmodel->get_filter_for_module(
                        $cm->id,
                        'pchkorg_exclude_self_plagiarism'
                    ),
                );
                if ($minpercent) {
                    $filters['source_min_percent'] = $minpercent;
                }

                $agreementwhere = array(
                    'cm' => 0,
                    'name' => 'accepted_agreement',
                    'value' => '1',
                );
                $agreementaccepted = $DB->get_records('plagiarism_pchkorg_config', $agreementwhere);
                if (empty($agreementaccepted)) {
                    $apiprovider->save_accepted_agreement($user->email);
                    $DB->insert_record('plagiarism_pchkorg_config', $agreementwhere);
                }

                if ($cm->modname === 'quiz') {
                    if ($filedb->fileid === null) {
                        $questionanswers = $DB->get_records_sql(
                            "SELECT {question_attempts}.responsesummary "
                            ." FROM {question_attempts} "
                            ." INNER JOIN {question} on {question}.id = {question_attempts}.questionid "
                            ." WHERE {question_attempts}.questionusageid = ? AND {question}.qtype = 'essay' ", array(
                                $filedb->itemid
                            )
                        );

                        foreach ($questionanswers as $questionanswer) {
                            $content = $questionanswer->responsesummary;
                            $signature = sha1($content);
                            if ($signature === $filedb->signature) {
                                $textid = $apiprovider->general_send_check(
                                    $apiprovider->user_email_to_hash($user->email),
                                    $cm->course,
                                    $cm->id,
                                    $cm->name,
                                    $filedb->itemid,
                                    null,
                                    html_to_text($content, 75, false),
                                    'plain/text',
                                    sprintf('%s-quiz.txt', $filedb->itemid),
                                    $filters
                                );
                                break;
                            }
                        }
                    } else {
                        $file = $fs->get_file_by_id($filedb->fileid);
                        // We can not receive file by id.
                        // Maybe file does not exist anymore.
                        // So we mark it as error and continue.
                        if (!$file || !is_object($file)) {
                            $filedbnew = new stdClass();
                            $filedbnew->id = $filedb->id;
                            $filedbnew->attempt = $filedb->attempt + 1;
                            $filedbnew->state = 11; // Sending error.

                            $DB->update_record('plagiarism_pchkorg_files', $filedbnew);

                            continue;
                        }
                        $textid = $apiprovider->general_send_check(
                            $apiprovider->user_email_to_hash($user->email),
                            $cm->course,
                            $cm->id,
                            $cm->name,
                            $filedb->itemid,
                            $file->get_id(),
                            $file->get_content(),
                            $file->get_mimetype(),
                            $file->get_filename(),
                            $filters
                        );
                    }
                }
                if ($cm->modname === 'forum') {
                    if ($filedb->fileid === null) {
                        $post = $DB->get_record_sql(
                            "SELECT subject, message"
                            ." FROM {forum_posts}"
                            ." WHERE {forum_posts}.id = ?", array(
                                $filedb->itemid
                            )
                        );
                        if ($post) {
                            $subject = $post->subject;
                            $content = $post->message;
                            $signature = sha1($content);
                            $ismatched = false;
                            if ($signature === $filedb->signature) {
                                $ismatched = true;
                            }
                            if (!$ismatched) {
                                $signature = sha1(trim(strip_tags($content)));
                                if ($signature === $filedb->signature) {
                                    $ismatched = true;
                                }
                            }

                            if ($ismatched) {
                                $textid = $apiprovider->general_send_check(
                                    $apiprovider->user_email_to_hash($user->email),
                                    $cm->course,
                                    $cm->id,
                                    $cm->name,
                                    $subject,
                                    null,
                                    html_to_text($content, 75, false),
                                    'plain/text',
                                    sprintf('%s-quiz.txt', $filedb->itemid),
                                    $filters
                                );
                            }
                        }
                    } else {
                        $moodlesubmission = $DB->get_record('assign_submission', array(
                            'assignment' => $cm->instance,
                            'userid' => $filedb->userid,
                            'id' => $filedb->itemid,
                        ), 'id');
                        $file = $fs->get_file_by_id($filedb->fileid);

                        // We can not receive file by id.
                        // Maybe file does not exist anymore.
                        // So we mark it as error and continue.
                        if (!$file || !is_object($file)) {
                            $filedbnew = new stdClass();
                            $filedbnew->id = $filedb->id;
                            $filedbnew->attempt = $filedb->attempt + 1;
                            $filedbnew->state = 11; // Sending error.

                            $DB->update_record('plagiarism_pchkorg_files', $filedbnew);

                            continue;
                        }
                        $textid = $apiprovider->general_send_check(
                            $apiprovider->user_email_to_hash($user->email),
                            $cm->course,
                            $cm->id,
                            $cm->name,
                            $moodlesubmission->id,
                            $file->get_id(),
                            $file->get_content(),
                            $file->get_mimetype(),
                            $file->get_filename(),
                            $filters
                        );
                    }
                }
                if ($cm->modname === 'assign') {
                    if ($filedb->fileid === null) {
                        $moodletextsubmission = $DB->get_record(
                            'assignsubmission_onlinetext',
                            array('submission' => $filedb->itemid),
                            '*'
                        );
                        if ($moodletextsubmission) {
                            $content = $moodletextsubmission->onlinetext;
                            $textid = $apiprovider->general_send_check(
                                $apiprovider->user_email_to_hash($user->email),
                                $cm->course,
                                $cm->id,
                                $cm->name,
                                $moodletextsubmission->id,
                                $moodletextsubmission->id,
                                html_to_text($content, 75, false),
                                'plain/text',
                                sprintf('%s-submussion.txt', $moodletextsubmission->id),
                                $filters
                            );
                        }
                    } else {
                        $moodlesubmission = $DB->get_record('assign_submission', array(
                            'assignment' => $cm->instance,
                            'userid' => $filedb->userid,
                            'id' => $filedb->itemid,
                        ), 'id');
                        $file = $fs->get_file_by_id($filedb->fileid);

                        // We can not receive file by id.
                        // Maybe file does not exist anymore.
                        // So we mark it as error and continue.
                        if (!$file || !is_object($file)) {
                            $filedbnew = new stdClass();
                            $filedbnew->id = $filedb->id;
                            $filedbnew->attempt = $filedb->attempt + 1;
                            $filedbnew->state = 11; // Sending error.

                            $DB->update_record('plagiarism_pchkorg_files', $filedbnew);

                            continue;
                        }
                        $textid = $apiprovider->general_send_check(
                            $apiprovider->user_email_to_hash($user->email),
                            $cm->course,
                            $cm->id,
                            $cm->name,
                            $moodlesubmission->id,
                            $file->get_id(),
                            $file->get_content(),
                            $file->get_mimetype(),
                            $file->get_filename(),
                            $filters
                        );
                    }
                }

                $filedbnew = new stdClass();
                $filedbnew->id = $filedb->id;
                if ($textid) {
                    // Text was successfully sent to the service.
                    $filedbnew->textid = $textid;
                    $filedbnew->state = 12; // 12 - is SENT.
                } else {
                    $filedbnew->attempt = $filedb->attempt + 1;
                    if ($filedbnew->attempt > 6) {
                        $filedbnew->state = 11; // Sending error.
                    }
                }
                $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
            }
        }

        return true;
    }

    /**
     * Method will update similarity score and change status of checks.
     *
     * @return bool
     * @throws dml_exception
     */
    public function cron_update_reports() {
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }

        $filesconditions = array('state' => 12);

        $moodlefiles = $DB->get_records('plagiarism_pchkorg_files', $filesconditions,
            'id', '*', 0, 20);

        foreach ($moodlefiles as $filedb) {
            $report = $apiprovider->check_text($filedb->textid);
            if ($report !== null) {
                $filedbnew = new stdClass();
                $filedbnew->id = $filedb->id;
                $filedbnew->state = 5;
                $filedbnew->reportid = $report->id;
                $filedbnew->score = $report->percent;
                $filedbnew->scoreai = $report->percent_ai;

                $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
            }
        }
    }
}
