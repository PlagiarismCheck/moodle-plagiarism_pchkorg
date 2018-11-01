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

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot.'/plagiarism/lib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/accesslib.php');
require_once(__DIR__.'/model/ConfigModel.php');
require_once(__DIR__.'/model/FileModel.php');
require_once(__DIR__.'/component/UrlGenerator.php');
require_once(__DIR__.'/component/ApiProvider.php');

class plagiarism_plugin_pchkorg extends plagiarism_plugin
{
    /**
     * hook to allow plagiarism specific information to be displayed beside a submission
     * @param array $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     *
     */
    public function get_links($linkarray)
    {
        global $DB;

        $fileModel    = new FileModel($DB);
        $configModel  = new ConfigModel($DB);
        $urlGenerator = new UrlGenerator();
        $apiProvider = new ApiProvider($configModel->getSystemConfig('pchkorg_token'));

        $config = $configModel->getSystemConfig('pchkorg_use');
        if ('1' !== $config) {
            return '';
        }

        //$userid, $file, $cmid, $course, $module
        $cmid   = $linkarray['cmid'];
        $userid = $linkarray['userid'];
        /** @var stored_file $file */
        $file = $linkarray['file'];

        if (!$configModel->isEnabledForModule($cmid)) {
            return '';
        }

        if (!$apiProvider->isSupportedMime($file->get_mimetype())) {
            return '';
        }

        $fileRecord = $fileModel->findFileByModuleAndFile($cmid, $file->get_id());

        $checkUrl = $urlGenerator->getCheckUrl($cmid, $file->get_id());

        if ($fileRecord) {
            $label = sprintf('%.2f', $fileRecord->score) . '%';
        } else {
            $label = 'Check for plagiarism';
        }

        return sprintf(' <a href="%s">( %s )</a> ', $checkUrl->__toString(), $label);

    }

    /* hook to save plagiarism specific settings on a module settings page
     * @param object $data - data from an mform submission.
    */
    public function save_form_elements($data)
    {
        global $DB;

        $configModel = new ConfigModel($DB);

        $config = $configModel->getSystemConfig('pchkorg_use');
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
            $insert        = new \stdClass();
            $insert->cm    = $data->coursemodule;
            $insert->name  = 'pchkorg_module_use';
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
     * hook to add plagiarism specific settings to a module settings page
     * @param object $mform - Moodle form
     * @param object $context - current context
     * @param object $modulename
     */
    public function get_form_elements_module($mform, $context, $modulename = '')
    {
        global $CFG, $DB;

        $configModel = new ConfigModel($DB);

        $config = $configModel->getSystemConfig('pchkorg_use');
        if ('1' == $config) {
            $defaultcmid = null;
            $cm          = optional_param('update', $defaultcmid, PARAM_INT);
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
                [get_string('no'), get_string('yes')]
            );
            $mform->addHelpButton('pchkorg_module_use', 'pchkorg_module_use', 'plagiarism_pchkorg');

            if (!isset($mform->exportValues()[$setting]) || is_null($mform->exportValues()[$setting])) {
                $mform->setDefault($setting, '1');
            }
        }
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid)
    {
        global $OUTPUT, $DB;

        $configModer = new ConfigModel($DB);

        echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');

        $formatoptions          = new stdClass;
        $formatoptions->noclean = true;
        $text                   = $configModer->getSystemConfig('plagiarism_pchkorg');

        echo format_text($text, FORMAT_MOODLE, $formatoptions);

        echo $OUTPUT->box_end();
    }

    public static function trans($message, $param = null)
    {
        return get_string($message, 'plagiarism_pchkorg', $param);
    }

    /**
     * hook to allow status of submitted files to be updated - called on grading/report pages.
     *
     * @param object $course - full Course object
     * @param object $cm - full cm object
     */
    public function update_status($course, $cm)
    {
        //called at top of submissions/grading pages - allows printing of admin style links or updating status
    }

    /**
     * called by admin/cron.php
     *
     */
    public function cron()
    {
        //do any scheduled task stuff
    }
}

