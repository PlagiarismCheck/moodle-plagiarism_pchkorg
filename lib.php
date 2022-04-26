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

        global $DB, $USER;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
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
            return '';
        }

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return '';
        }

        $context = null;
        if (!empty($cmid)) {
            $context = context_module::instance($cmid);// Get context of course.
        }

        if (empty($context)) {
            return '';
        }

        $canview = has_capability(capability::VIEW_SIMILARITY, $context);
        if (!$canview) {
            return '';
        }

        // SQL will be called only once per page. There is static result inside.
        if (!$pchkorgconfigmodel->is_enabled_for_module($cmid)) {
            return '';
        }

        // Only for some type of account, method will call a remote HTTP API.
        // The API will be called only once, because result is static.
        // Also, there is timeout 2 seconds for response.
        // Even if service will be unavailable, method will try call API only once.
        // Also, we don't use raw user email.
        if (!$apiprovider->is_group_member($USER->email)) {
            return '';
        }

        $isgranted = !empty($context) && has_capability('mod/assign:view', $context, null);
        if (!$isgranted) {
            return '';
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

        if ($filerecords) {
            $filerecord = end($filerecords);

            $img = new moodle_url('/plagiarism/pchkorg/pix/icon.png');
            $imgsrc = $img->__toString();

            // Text had been successfully checked.
            if ($filerecord->state == 5) {
                $action = $apiprovider->get_report_action($filerecord->textid);
                $reporttoken = $apiprovider->generate_api_token();
                $formid = 'plagiarism_pchkorg_report_id_' . $filerecord->id;
                $score = $filerecord->score;
                $title = sprintf(get_string('pchkorg_label_title', 'plagiarism_pchkorg'),
                    $filerecord->textid,
                    $score);
                $label = sprintf(get_string('pchkorg_label_result', 'plagiarism_pchkorg'), $filerecord->textid, $score);

                if ($score < 30) {
                    $color = '#63ec80a1';
                } else if (30 < $score && $score < 60) {
                    $color = '#f7b011';
                } else {
                    $color = '#f04343';
                }

                return '
                <a style="padding: 5px 3px;
text-decoration: none;
background-color: ' . $color . ';
color: black;
cursor: pointer;
border-radius: 3px 3px 3px 3px;
margin: 4px;
display: inline-block;"
            href="#" title="' . $title . '"
            class="plagiarism_pchkorg_report_id_score"
            onclick="document.getElementById(\'' . $formid . '\').submit(); return false;">
            <img src="' . $imgsrc . '" alt="logo" width="20px;" />
            ' . $label . '
            </a><form target="_blank" id="' . $formid . '" action="' . $action . '" method="post">
            <input type="hidden" name="token" value="' . $reporttoken . '"/>
            <input type="hidden" name="lms-type" value="moodle"/>
        </form>';
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
                <img src="' . $imgsrc . '" alt="logo" width="20px;" />
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
                <img src="' . $imgsrc . '" alt="logo" width="20px;" />
                ' . $label . '
            </span>';
            }
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
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' != $config) {
            return;
        }

        if (!isset($data->pchkorg_module_use)) {
            return;
        }
        $fields = array('pchkorg_module_use', 'pchkorg_min_percent');

        $records = $DB->get_records('plagiarism_pchkorg_config', array(
            'cm' => $data->coursemodule
        ));

        foreach ($fields as $field) {
            $isfounded = false;
            foreach ($records as $record) {
                if ($record->name === $field) {
                    $isfounded = true;
                    $record->value = $data->{$record->name};
                    $DB->update_record('plagiarism_pchkorg_config', $record);
                    break;
                }
            }
            if (!$isfounded) {
                $insert = new \stdClass();
                $insert->cm = $data->coursemodule;
                $insert->name = $field;
                $insert->value = $data->{$field};

                $DB->insert_record('plagiarism_pchkorg_config', $insert);
            }
        }
    }

    /**
     *
     *  Build plugin settings form.
     *
     * @param object $mform
     * @param object $context
     * @param string $modulename
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
        if (!$context || !isset($modulename) || 'mod_assign' !== $modulename) {
            return;
        }
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        $enabled = has_capability(capability::ENABLE, $context);

        if ('1' == $config && $enabled) {
            $defaultcmid = null;
            $cm = optional_param('update', $defaultcmid, PARAM_INT);
            if (null !== $cm) {
                $records = $DB->get_records('plagiarism_pchkorg_config', array(
                    'cm' => $cm,
                ));
                if (!empty($records)) {
                    $record = end($records);
                    $mform->setDefault($record->name, $record->value);
                }
            }

            $mform->addElement('header', 'plagiarism_pchkorg', get_string('pluginname', 'plagiarism_pchkorg'));
            $mform->addElement(
                'select',
                $setting = 'pchkorg_module_use',
                get_string('pchkorg_module_use', 'plagiarism_pchkorg'),
                array(get_string('no'), get_string('yes'))
            );
            $mform->addHelpButton('pchkorg_module_use', 'pchkorg_module_use', 'plagiarism_pchkorg');

            if (!isset($mform->exportValues()[$setting]) || is_null($mform->exportValues()[$setting])) {
                $mform->setDefault($setting, '1');
            }

            $mform->registerRule('check_pchkorg_min_percent', 'callback', 'pchkorg_check_pchkorg_min_percent');

            $mform->addElement('text', 'pchkorg_min_percent', get_string('pchkorg_min_percent', 'plagiarism_pchkorg'));
            $mform->addHelpButton('pchkorg_min_percent', 'pchkorg_min_percent', 'plagiarism_pchkorg');
            $mform->addRule('pchkorg_min_percent', null, 'numeric', null, 'client');
            $mform->addRule('pchkorg_min_percent', get_string('pchkorg_min_percent_range', 'plagiarism_pchkorg'), 'check_pchkorg_min_percent');
            $mform->setType('pchkorg_min_percent', PARAM_INT);

        }
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

        if (!$cm || $cm->modname != 'assign') {
            return '';
        }

        $configmodel = new plagiarism_pchkorg_config_model();

        $enabled = $configmodel->get_system_config('pchkorg_use');
        if ($enabled !== '1') {
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

        // We support only assign module so just ignore all other.
        if ($eventdata['other']['modulename'] !== 'assign') {
            return true;
        }

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }

        // Receive couser moudle id.
        $cmid = $eventdata['contextinstanceid'];
        // Remove the event if the course module no longer exists.
        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $cmid);
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
        // Also, there is timeout 2 seconds for response.
        // Even if service is unavailable, method will try call only once.
        // Also, we don't use raw users email.
        if (!$apiprovider->is_group_member($USER->email)) {
            return true;
        }

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        // Related user ID will be NULL if an instructor submits on behalf of a student who is in a group.
        // To get around this, we get the group ID, get the group members and set the author as the first student in the group.
        if ((empty($eventdata['relateduserid'])) && ($eventdata['other']['modulename'] == 'assign')
            && has_capability('mod/assign:editothersubmission', context_module::instance($cm->id), $submitter)) {
            $moodlesubmission = $DB->get_record('assign_submission', array('id' => $eventdata['objectid']), 'id, groupid');
            if (!empty($moodlesubmission->groupid)) {
                $author = $this->get_first_group_author($cm->course, $moodlesubmission->groupid);
            }
        }

        // Get actual text content and files to be submitted for draft submissions.
        // As this won't be present in eventdata for certain event types.
        if ($eventdata['other']['modulename'] == 'assign' && $eventdata['eventtype'] == "assessable_submitted") {
            // Get content.
            $moodlesubmission = $DB->get_record('assign_submission', array('id' => $eventdata['objectid']), 'id');
            if ($moodletextsubmission = $DB->get_record('assignsubmission_onlinetext',
                array('submission' => $moodlesubmission->id), 'onlinetext')) {
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
        if (in_array($eventdata['eventtype'], array("content_uploaded", "assessable_submitted"))
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

        $moodlefiles = $DB->get_records('plagiarism_pchkorg_files', $filesconditions,
            'id', '*', 0, 20);
        if ($moodlefiles) {
            $fs = get_file_storage();

            foreach ($moodlefiles as $filedb) {
                $textid = null;
                $user = $DB->get_record('user', array('id' => $filedb->userid));
                // This is attached file.
                $cm = get_coursemodule_from_id('', $filedb->cm);
                if ($filedb->fileid === null) {
                    $moodletextsubmission = $DB->get_record('assignsubmission_onlinetext',
                        array('submission' => $filedb->itemid), '*');
                    if ($moodletextsubmission) {
                        $content = $moodletextsubmission->onlinetext;

                        if ($apiprovider->is_group_token()) {
                            $textid = $apiprovider->send_group_text(
                                $apiprovider->user_email_to_hash($user->email),
                                $cm->course,
                                $cm->id,
                                $moodletextsubmission->id,
                                $moodletextsubmission->id,
                                html_to_text($content, 75, false),
                                'plain/text',
                                sprintf('%s-submussion.txt', $moodletextsubmission->id)
                            );
                        } else {
                            $textid = $apiprovider->send_text(
                                html_to_text($content, 75, false),
                                'plain/text',
                                sprintf('%s-submussion.txt', $moodletextsubmission->id)
                            );
                        }
                    }
                } else {
                    $moodlesubmission = $DB->get_record('assign_submission', array('assignment' => $cm->instance,
                        'userid' => $filedb->userid, 'id' => $filedb->itemid), 'id');
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

                    if ($apiprovider->is_group_token()) {
                        $textid = $apiprovider->send_group_text(
                            $apiprovider->user_email_to_hash($user->email),
                            $cm->course,
                            $cm->id,
                            $moodlesubmission->id,
                            $file->get_id(),
                            $file->get_content(),
                            $file->get_mimetype(),
                            $file->get_filename()
                        );
                    } else {
                        $agreementwhere = array(
                            'cm' => 0,
                            'name' => 'accepted_agreement',
                            'value' => '1'
                        );
                        $agreementaccepted = $DB->get_records('plagiarism_pchkorg_config', $agreementwhere);
                        if (empty($agreementaccepted)) {
                            $apiprovider->save_accepted_agreement($user->email);
                            $DB->insert_record('plagiarism_pchkorg_config', $agreementwhere);
                        }

                        $textid = $apiprovider->send_text(
                            $file->get_content(),
                            $file->get_mimetype(),
                            $file->get_filename()
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

                $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
            }
        }
    }
}
