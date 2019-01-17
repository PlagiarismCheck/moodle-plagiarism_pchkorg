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
 * pl * plagiarism.php - allows the admin to configure plagiarism stuff
 *
 * @package   plagiarism_pchkorg
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

global $DB;

$pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);

require_login();
admin_externalpage_setup('plagiarismpchkorg');

$context = context_system::instance(CONTEXT_SYSTEM);

require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");

$mform = new plagiarism_pchkorg_setup_form();
$plagiarismplugin = new plagiarism_plugin_pchkorg();

if ($mform->is_cancelled()) {
    redirect('');
}
echo $OUTPUT->header();

if (($data = $mform->get_data()) && confirm_sesskey()) {

    if (!isset($data->pchkorg_use)) {
        $data->pchkorg_use = 0;
    }

    foreach ($data as $field => $value) {
        if (strpos($field, 'pchkorg') === 0) {
            set_config($field, $value, 'plagiarism');
            $pchkorgconfigmodel->set_system_config($field, $value);
        }
    }
    $OUTPUT->notification(get_string('savedconfigsuccess', 'plagiarism_pchkorg'), 'notifysuccess');
}

$pchkorgconfigmodel = new plagiarism_pchkorg_config_model($DB);

$plagiarismsettings = $pchkorgconfigmodel->get_all_system_config();

$mform->set_data($plagiarismsettings);

echo $OUTPUT->box_start('generalbox boxaligncenter', 'intro');
$mform->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();
