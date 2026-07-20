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
 * Hands a viewer over to the PlagiarismCheck.org report for one submission.
 *
 * The service authenticates with a credential derived from the site's API
 * token. That credential must never be embedded in a Moodle page, where every
 * viewer of a grading table would receive it regardless of whether they are
 * allowed to open a report. Instead the widget links here, this script repeats
 * the permission checks server side, and only then is the credential posted on
 * to the service.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use plagiarism_pchkorg\classes\permissions\capability;

$id = required_param('id', PARAM_INT);

require_sesskey();

$filerecord = $DB->get_record(
    'plagiarism_pchkorg_files',
    ['id' => $id],
    '*',
    MUST_EXIST
);

$cm = get_coursemodule_from_id('', $filerecord->cm, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_url(new moodle_url('/plagiarism/pchkorg/report.php', ['id' => $id]));
$PAGE->set_context($context);

require_capability(capability::VIEW_SIMILARITY, $context);

$configmodel = new plagiarism_pchkorg_config_model();

if (
'1' !== $configmodel->get_system_config('pchkorg_use')
        || !$configmodel->is_enabled_for_module($cm->id)
) {
    throw new moodle_exception('pchkorg_report_not_available', 'plagiarism_pchkorg');
}

// Only a finished check has a report to open.
if ((int) $filerecord->state !== plagiarism_pchkorg_state::LOCAL_CHECKED) {
    throw new moodle_exception('pchkorg_report_not_available', 'plagiarism_pchkorg');
}

// Students see reports only where the activity allows it, and only their own.
if (plagiarism_pchkorg_roles::is_student($context, $USER->id)) {
    if ($configmodel->show_report_for_student($cm->id) === false) {
        throw new moodle_exception('pchkorg_report_not_allowed', 'plagiarism_pchkorg');
    }
    if ((int) $filerecord->userid !== (int) $USER->id) {
        throw new moodle_exception('pchkorg_report_not_allowed', 'plagiarism_pchkorg');
    }
}

$apitoken = $configmodel->get_system_config('pchkorg_token');
$apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

// A group account only recognises its own members.
if (!$apiprovider->is_group_member($USER->email)) {
    throw new moodle_exception('pchkorg_report_not_allowed', 'plagiarism_pchkorg');
}

$action = $apiprovider->get_report_action($filerecord->textid);
$token = $apiprovider->generate_api_token($USER->email);

// The service expects the credential as a POST field, so hand over with a
// self-submitting form rather than a redirect.
echo $OUTPUT->header();
echo html_writer::start_tag('form', [
    'id' => 'plagiarism_pchkorg_report_form',
    'method' => 'post',
    'action' => $action,
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'lms-type',
    'value' => 'moodle',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'token',
    'value' => $token,
]);
echo html_writer::tag('noscript', html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('pchkorg_report_open', 'plagiarism_pchkorg'),
]));
echo html_writer::end_tag('form');

$PAGE->requires->js_amd_inline(
    "document.getElementById('plagiarism_pchkorg_report_form').submit();"
);

echo $OUTPUT->footer();
