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

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../form/send_text_form.php');
require_once(__DIR__ . '/../classes/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/../classes/plagiarism_pchkorg_url_generator.php');
require_once(__DIR__ . '/../classes/plagiarism_pchkorg_api_provider.php');

global $PAGE, $CFG, $OUTPUT, $DB, $USER;

$pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
$urlgenerator = new plagiarism_pchkorg_url_generator();
$apiprovider = new plagiarism_pchkorg_api_provider($pchkorgconfigmodel->get_system_config('pchkorg_token'));

$cmid = (int) required_param('cmid', PARAM_INT); // Course Module ID.
$fileid = (int) required_param('file', PARAM_INT); // plagiarism file id.

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, true, $cm);
$context = context_module::instance($cm->id);// Get context of course.

$isgranted = has_capability('mod/assign:view', $context, null);

if (!$isgranted) {
    die('403 permission denied');
}

$fs = get_file_storage();
$form = new send_text_form($currenturl = $urlgenerator->get_check_url($cmid, $fileid));

$CFG->cachejs = false;
$PAGE->set_url($currenturl);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'plagiarism_pchkorg'));
$PAGE->set_heading(get_string('pluginname', 'plagiarism_pchkorg'));

if ('POST' === $_SERVER['REQUEST_METHOD']) { // Form submission.

    $data = $form->get_data();
    $cmid = (int) $data->cmid;
    $fileid = (int) $data->fileid;

    $file = $fs->get_file_by_id($fileid);

    $where = new \stdClass();
    $where->cm = $cmid;
    $where->fileid = $file->get_id();

    $filerecord = $DB->get_record('plagiarism_pchkorg_files', (array) $where);
    // Preventing some race condition.
    if ($filerecord) {
        redirect($urlgenerator->get_check_url($cmid, $fileid), 'Document already checked.');
        exit;
    }

    if ('submission_files' !== $file->get_filearea()
            || $file->get_contextid() != $context->id) {
        die('permission denied');
    }

    if (!$file) {
        // File not found.

        die('404 not exists');
    }

    if ($apiprovider->is_group_token()) {
        $textid = $apiprovider->send_group_text(
                $apiprovider->user_email_to_hash($USER->email),
                $cm->course,
                $cm->id,
                $cm->id,
                $file->get_id(),
                $file->get_content(),
                $file->get_mimetype(),
                $file->get_filename()
        );
    } else {
        $textid = $apiprovider->send_text(
                $file->get_content(),
                $file->get_mimetype(),
                $file->get_filename()
        );
    }

    $message = '';
    if (null !== $textid) {
        $filerecord = new \stdClass();
        $filerecord->fileid = $fileid;
        $filerecord->cm = $cmid;
        $filerecord->userid = $USER->id;
        $filerecord->textid = $textid;
        $filerecord->state = 1; // 1 - is SENT.
        $filerecord->created_at = time();

        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
    } else {
        if ('Invalid token' === $apiprovider->get_last_error()) {
            $pchkorgconfigmodel->set_system_config('pchkorg_use', '0');
        }
        $message = $apiprovider->get_last_error();
    }

    redirect($urlgenerator->get_check_url($cmid, $fileid), $message);
    exit;
}

$file = $fs->get_file_by_id($fileid);
if ('submission_files' !== $file->get_filearea()
        || $file->get_contextid() != $context->id) {
    die('permission denied');
}
if (!$file) {
    die('404 not exists');
}

$where = new \stdClass();
$where->cm = $cmid;
$where->fileid = $fileid;

$filerecord = $DB->get_record('plagiarism_pchkorg_files', (array) $where);

if (!$filerecord) {
    $content = $file->get_content();
    $mime = $file->get_mimetype();

    if ($issupported = $apiprovider->is_supported_mime($file->get_mimetype())) {
        if ('plain/text' === $mime || 'text/plain' === $mime) {
            $content = $content = $file->get_content();
        } else {
            $content = $file->get_filename();
        }

        $default = array('fileid' => $fileid, 'cmid' => $cmid);
        $form->set_data($default);
    }

    require('../view/send_text.php');
} else if (null !== $filerecord->reportid) {
    $action = $apiprovider->get_report_action($filerecord->textid);
    $token = $apiprovider->generate_api_token();

    require('../view/report.php');
} else if (null !== $filerecord->textid) {

    require('../view/check_report.php');
}
