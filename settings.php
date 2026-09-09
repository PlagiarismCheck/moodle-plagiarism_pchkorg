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
 * Site-wide administration settings.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/lib/formslib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/form/plagiarism_pchkorg_setup_form.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/lib.php');

$pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

require_login();
admin_externalpage_setup('plagiarismpchkorg');

$context = context_system::instance();

require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");

$mform = new plagiarism_pchkorg_setup_form();
$plagiarismplugin = new plagiarism_plugin_pchkorg();

if ($mform->is_cancelled()) {
    redirect('');
}
echo $OUTPUT->header();

// Set by the save below when it validated a token, so the panel further down
// can show what that call already learned instead of asking a second time.
$validation = null;
$validatedtoken = null;

if (($data = $mform->get_data()) && confirm_sesskey()) {
    if (!isset($data->pchkorg_use)) {
        $data->pchkorg_use = 0;
    }

    foreach ($data as $field => $value) {
        if (strpos($field, 'pchkorg') === 0) {
            if ('pchkorg_use' === $field) {
                set_config('enabled', $value, 'plagiarism_pchkorg');
            } else {
                set_config($field, $value, 'plagiarism_pchkorg');
            }
            if ('pchkorg_token' === $field) {
                $value = trim($value);
            }
            $pchkorgconfigmodel->set_system_config($field, $value);
        }
    }
    $OUTPUT->notification(get_string('savedconfigsuccess', 'plagiarism_pchkorg'), 'notifysuccess');

    // Validate the token here and when the page renders below, and nowhere
    // else: not per assignment edit, not in scheduled tasks. The three outcomes
    // are kept apart so an unreachable service is never reported as a bad
    // token, and a personal token is not validated at all -- see
    // validate_token(), which explains why.
    $submittedtoken = isset($data->pchkorg_token) ? trim($data->pchkorg_token) : '';
    if ('' !== $submittedtoken) {
        $apiprovider = new plagiarism_pchkorg_api_provider($submittedtoken);
        $validation = $apiprovider->validate_token();
        $validatedtoken = $submittedtoken;
        if (!$validation->checked) {
            $validation = null;
        } else if ($validation->ok) {
            echo $OUTPUT->notification(get_string('pchkorg_token_valid', 'plagiarism_pchkorg'), 'notifysuccess');
        } else if (!$validation->reachable) {
            echo $OUTPUT->notification(get_string('pchkorg_token_unavailable', 'plagiarism_pchkorg'), 'notifyproblem');
        } else {
            echo $OUTPUT->notification(get_string('pchkorg_token_invalid', 'plagiarism_pchkorg'), 'notifyproblem');
        }
    }
}

$plagiarismsettings = $pchkorgconfigmodel->get_all_system_config();

$mform->set_data($plagiarismsettings);

echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
echo plagiarism_pchkorg_render_group_panel($plagiarismsettings, $validation, $validatedtoken);
$mform->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();

/**
 * The account panel shown above the settings form.
 *
 * Asked afresh every time the page renders. A balance that has run out or a
 * subscription that lapsed since the token was pasted in is exactly what the
 * administrator opens this page to find out, so a value stored at save time
 * would be the one thing this panel must not show.
 *
 * Nothing here may stop the page: an administrator whose service is
 * unreachable, or whose token is wrong, needs this form to fix it.
 *
 * @param array $settings Stored plugin configuration.
 * @param object|null $validation Result of a validation already made in this
 *                                 request, if any.
 * @param string|null $validatedtoken The token that result belongs to.
 * @return string HTML, empty when there is no institutional token to describe.
 */
function plagiarism_pchkorg_render_group_panel($settings, $validation, $validatedtoken) {
    $token = isset($settings['pchkorg_token']) ? trim($settings['pchkorg_token']) : '';
    if ('' === $token) {
        return '';
    }

    $apiprovider = new plagiarism_pchkorg_api_provider($token);
    // A personal token has no institution behind it, so there is nothing to
    // describe and nothing worth a request to find that out.
    if (!$apiprovider->is_group_token()) {
        return '';
    }

    // The save above has just asked about this very token. Asking again would
    // be a second identical round trip on every save.
    if (null === $validation || $validatedtoken !== $token) {
        $validation = $apiprovider->validate_token();
    }

    $body = '';
    if (!$validation->reachable) {
        $body = html_writer::div(
            get_string('pchkorg_group_unavailable', 'plagiarism_pchkorg'),
            'pchkorg-group-message'
        );
    } else if (!$validation->ok) {
        $body = html_writer::div(
            get_string('pchkorg_group_unknown', 'plagiarism_pchkorg'),
            'pchkorg-group-message'
        );
    } else if (null === $validation->group) {
        // The token is good; the service just did not describe the account in
        // a way this plugin could read. Saying the token is unrecognised would
        // send the administrator looking for a problem that is not there.
        $body = html_writer::div(
            get_string('pchkorg_group_unavailable', 'plagiarism_pchkorg'),
            'pchkorg-group-message'
        );
    } else {
        $rows = '';
        foreach ($validation->group->to_display_rows() as $row) {
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('th', get_string($row->label, 'plagiarism_pchkorg'), [
                    'scope' => 'row',
                    'class' => 'pchkorg-group-label',
                ])
                . html_writer::tag('td', s($row->value), [
                    'class' => 'pchkorg-group-value' . ($row->warning ? ' pchkorg-group-warning' : ''),
                ])
            );
        }
        $body = html_writer::tag(
            'table',
            html_writer::tag('tbody', $rows),
            ['class' => 'pchkorg-group-table']
        );
    }

    return html_writer::div(
        html_writer::tag('h3', get_string('pchkorg_group_heading', 'plagiarism_pchkorg'), [
            'class' => 'pchkorg-group-heading',
        ])
        . html_writer::div(get_string('pchkorg_group_intro', 'plagiarism_pchkorg'), 'pchkorg-group-intro')
        . $body,
        'pchkorg-group-panel'
    );
}
