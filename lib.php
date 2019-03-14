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
require_once(__DIR__ . '/classes/plagiarism_pchkorg_url_generator.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_api_provider.php');

/**
 * Class plagiarism_plugin_pchkorg
 */
class plagiarism_plugin_pchkorg extends plagiarism_plugin {
    /**
     * hook to allow plagiarism specific information to be displayed beside a submission
     *
     * @param array $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     *
     */
    public function get_links($linkarray) {
        global $DB, $USER;
        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);
        $urlgenerator = new plagiarism_pchkorg_url_generator();
        $apiprovider = new plagiarism_pchkorg_api_provider($pchkorgconfigmodel->get_system_config('pchkorg_token'));

        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return '';
        }

        $cmid = $linkarray['cmid'];
        $file = $linkarray['file'];
        $context = null;
        if (!empty($cmid)) {
            $context = context_module::instance($cmid);// Get context of course.
        }

        if (!$pchkorgconfigmodel->is_enabled_for_module($cmid)) {
            return '';
        }

        if (!$apiprovider->is_supported_mime($file->get_mimetype())) {
            return '';
        }

        if (!$apiprovider->is_group_member($USER->email)) {
            return '';
        }

        $isgranted = !empty($context) && has_capability('mod/assign:view', $context, null);
        if (!$isgranted) {
            return '';
        }

        $where = new \stdClass();
        $where->cm = $cmid;
        $where->fileid = $file->get_id();

        $filerecord = $DB->get_record('plagiarism_pchkorg_files', (array) $where);

        $checkurl = $urlgenerator->get_check_url($cmid, $file->get_id());

        if ($filerecord) {
            $label = sprintf('%.2f', $filerecord->score) . '%';
            $link = sprintf(' <a href="%s" target="_blank">( %s )</a> ', $checkurl->__toString(), $label);
        } else {
            $label = get_string('pchkorg_check_for_plagiarism', 'plagiarism_pchkorg');
            $link = sprintf(' <a href="%s" target="_blank">( %s )</a> ', $checkurl->__toString(), $label);
        }

        return $link;
    }

    /* hook to save plagiarism specific settings on a module settings page
     * @param object $data - data from an mform submission.
    */
    /**
     * @param $data
     * @throws dml_exception
     */
    public function save_form_elements($data) {
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);

        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' != $config) {

            return;
        }
        if (!isset($data->pchkorg_module_use)) {
            return;
        }

        $records = $DB->get_records('plagiarism_pchkorg_config', array(
                'cm' => $data->coursemodule
        ));

        if (empty($records)) {
            $insert = new \stdClass();
            $insert->cm = $data->coursemodule;
            $insert->name = 'pchkorg_module_use';
            $insert->value = $data->pchkorg_module_use;
            $DB->insert_record('plagiarism_pchkorg_config', $insert);
        } else {
            foreach ($records as $record) {
                $record->value = $data->{$record->name};
                $DB->update_record('plagiarism_pchkorg_config', $record);
            }
        }
    }

    /**
     * @param object $mform
     * @param object $context
     * @param string $modulename
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_form_elements_module($mform, $context, $modulename = '') {
        if (!$context || !isset($modulename)) {
            return;
        }
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);

        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' == $config) {
            $defaultcmid = null;
            $cm = optional_param('update', $defaultcmid, PARAM_INT);
            if (null !== $cm) {
                $records = $DB->get_records('plagiarism_pchkorg_config', array(
                        'cm' => $cm,
                ));
                if (!empty($records)) {
                    foreach ($records as $record) {
                        $mform->setDefault($record->name, $record->value);
                    }
                }
            }

            $mform->addElement('header', 'plagiarism_pchkorg', self::trans('pluginname'));
            $mform->addElement(
                    'select',
                    $setting = 'pchkorg_module_use',
                    self::trans('pchkorg_module_use'),
                    array(get_string('no'), get_string('yes'))
            );
            $mform->addHelpButton('pchkorg_module_use', 'pchkorg_module_use', 'plagiarism_pchkorg');

            if (!isset($mform->exportValues()[$setting]) || is_null($mform->exportValues()[$setting])) {
                $mform->setDefault($setting, '1');
            }
        }
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission
     *
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid) {
        global $OUTPUT, $DB;

        $configmodel = new plagiarism_pchkorg_config_model($DB);

        echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');

        $formatoptions = new stdClass;
        $formatoptions->noclean = true;
        $formatoptions->cmid = $cmid;
        $text = $configmodel->get_system_config('plagiarism_pchkorg');

        echo format_text($text, FORMAT_MOODLE, $formatoptions);

        echo $OUTPUT->box_end();
    }

    /**
     * @param $message
     * @param null $param
     * @return string
     * @throws coding_exception
     */
    public static function trans($message, $param = null) {
        return get_string($message, 'plagiarism_pchkorg', $param);
    }
}
