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

require_login();

$pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);
$urlgenerator = new plagiarism_pchkorg_url_generator();
$apiprovider = new plagiarism_pchkorg_api_provider(
        $pchkorgconfigmodel->get_system_config('pchkorg_token')
);

$cmid = (int) required_param('cmid', PARAM_INT); // Course Module ID
$fileid = (int) required_param('file', PARAM_INT); // plagiarism file id.
$cm = get_coursemodule_from_id('', $cmid);
require_login($cm->course, true, $cm);
$context = context_module::instance($cm->id);
header('Content-Type: application/json');
$isgranted = has_capability('mod/assign:view', $context, null);
if (!$isgranted) {
    die('{error: "access denied"}');
}
$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);

if (!$file) {
    die('{error: "file not exists"}');
}

if ('submission_files' !== $file->get_filearea()
        || $file->get_contextid() != $context->id) {
    die('{error: "access denied"}');
}

// Prevent JS caching.
$CFG->cachejs = false;
$PAGE->set_url($urlgenerator->get_status_url());
$where = new \stdClass();
$where->cm = $cmid;
$where->fileid = $fileid;

$filerecord = $DB->get_record('plagiarism_pchkorg_files', (array) $where);

if (!$filerecord) {
    echo json_encode(array(
            'success' => false,
            'message' => '404 can not find text'
    ));
}

$report = $apiprovider->check_text($filerecord->textid);
if ($checked = (null !== $report)) {
    $filerecord->reportid = $report->id;
    $filerecord->score = $report->percent;
    $DB->update_record('plagiarism_pchkorg_files', $filerecord);
}

$location = new moodle_url(sprintf(
        '/plagiarism/pchkorg/page/report.php?cmid=%s&file=%s',
        intval($cmid),
        intval($fileid)
));

echo json_encode(array(
        'success' => true,
        'checked' => $checked,
        'location' => $location->out(false)
));