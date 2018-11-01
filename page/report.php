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
$cm = get_coursemodule_from_id('', $cmid);
require_login($cm->course, true, $cm);

$fs = get_file_storage();
$form = new send_text_form($currentUrl = $urlGenerator->getCheckUrl($cmid, $fileid));

// Prevent JS caching
$CFG->cachejs = false;
$PAGE->set_url($currentUrl);
$PAGE->set_pagelayout('report');
$PAGE->set_title('My modules page title');
$PAGE->set_heading('My modules page heading');

if ('POST' === $_SERVER['REQUEST_METHOD']) {// form submission

    $data   = $form->get_data();
    $cmid   = (int)$data->cmid;
    $fileid = (int)$data->fileid;

    $file = $fs->get_file_by_id($fileid);
    if (!$file) {
        // file not found

        die('404 not exists');
    }

    $textid = $apiProvider->sendText(
        $file->get_content(),
        $file->get_mimetype(),
        $file->get_filename()
    );

    $message = '';
    if (null !== $textid) {
        $fileRecord             = new \stdClass();
        $fileRecord->fileid     = $fileid;
        $fileRecord->cm         = $cmid;
        $fileRecord->userid     = $USER->id;
        $fileRecord->textid     = $textid;
        $fileRecord->state      = FileModel::STATE_SENT;
        $fileRecord->created_at = time();

        $fileModel->create($fileRecord);
    } else {
        if ('Invalid token' === $apiProvider->getLastError()) {
            $configModel->setSystemConfig('pchkorg_use', '0');
        }
        $message = $apiProvider->getLastError();
    }

    redirect($urlGenerator->getCheckUrl($cmid, $fileid), $message);
    exit;
}

$file = $fs->get_file_by_id($fileid);
if (!$file) {
    // file not found

    die('404 not exists');
}



$fileRecord = $fileModel->findFileByModuleAndFile($cmid, $fileid);


if (!$fileRecord) {
    $content = $file->get_content();
    $mime    = $file->get_mimetype();

    if ($isSupported = $apiProvider->isSupportedMime($file->get_mimetype())) {
        if ('plain/text' === $mime || 'text/plain' === $mime) {
            $content = $content = $file->get_content();
        } else {
            $content = $file->get_filename();
        }

        $default = ['fileid' => $fileid, 'cmid' => $cmid];
        $form->set_data($default);
    }

    require '../view/send_text.php';
} elseif (null !== $fileRecord->reportid) {
    $report = $apiProvider->getReport($fileRecord->textid);
    $json = json_decode($report);
    $error = '';
    if (isset($json->message)) {
        $error = $json->message;
    } else {
        $data = json_encode($json);
    }

    require '../view/report.php';
} elseif (null !== $fileRecord->textid) {

    $PAGE->requires->js_call_amd('plagiarism_pchkorg/main', 'checkReport');
    require '../view/check_report.php';
}
