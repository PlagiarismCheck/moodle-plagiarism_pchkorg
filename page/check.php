<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../form/send_text_form.php');
require_once(__DIR__ . '/../component/UrlGenerator.php');
require_once(__DIR__ . '/../component/ApiProvider.php');

global $PAGE, $CFG, $OUTPUT, $DB, $USER;

require_login();

$fileModel    = new FileModel($DB);
$configModel  = new ConfigModel($DB);
$urlGenerator = new UrlGenerator();
$apiProvider = new ApiProvider($configModel->getSystemConfig('pchkorg_token'));

$cmid   = (int)required_param('cmid', PARAM_INT); // Course Module ID
$fileid = (int)required_param('file', PARAM_INT); // plagiarism file id.
$cm     = get_coursemodule_from_id('', $cmid);
require_login($cm->course, true, $cm);
header('Content-Type: application/json');
// Prevent JS caching
$CFG->cachejs = false;
$PAGE->set_url($urlGenerator->getStatusUrl());
$fileRecord = $fileModel->findFileByModuleAndFile($cmid, $fileid);
if (!$fileRecord) {
    echo json_encode([
        'success' => false,
        'message' => '404 can not find text'
    ]);
}

$reportid = $apiProvider->checkText($fileRecord->textid);
if ($checked = (null !== $reportid)) {
    $report = $apiProvider->getReport($fileRecord->textid);
    $json = json_decode($report);
    $score = 0;
    if (isset($json->data)
        && isset($json->data->report)
        && isset($json->data->report->percent)
    ) {
        $score = $json->data->report->percent;
    }

    $fileRecord->reportid = $reportid;
    $fileRecord->score    = $score;
    $fileModel->update($fileRecord);
}

echo json_encode([
    'success'  => true,
    'checked'  => $checked,
    'location' =>sprintf(
        '/plagiarism/pchkorg/page/report.php?cmid=%s&file=%s',
        intval($cmid),
        intval($fileid)
    )
]);