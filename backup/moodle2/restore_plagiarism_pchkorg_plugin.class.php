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

/**
 * Restore support for the plugin.
 *
 * @package   plagiarism_pchkorg
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_plagiarism_pchkorg_plugin extends restore_plagiarism_plugin {
    /**
     * Restore one site-level configuration row.
     */
    public function process_pchkorg_config($data) {
        $data = (object) $data;

        set_config($this->task->get_courseid(), $data->value, $data->plugin);
    }


    /**


     * Restore one per-activity configuration row.


     */


    /**
     * Process pchkorgconfigmod.
     */
    public function process_pchkorgconfigmod($data) {
        global $DB;

        $data = (object) $data;
        $data->cm = $this->task->get_moduleid();

        $DB->insert_record('plagiarism_pchkorg_config', $data);
    }

    /**

     * Restore one queued submission record.

     */

    /**
     * Process pchkorgfiles.
     */
    public function process_pchkorgfiles($data) {
        global $DB;

        $data = (object) $data;
        $data->cm = $this->task->get_moduleid();
        $data->userid = $this->get_mappingid('user', $data->userid);

        $DB->insert_record('plagiarism_pchkorg_files', $data);
    }


    /**


     * Declare what is restored at course level.


     */


    /**
     * Define course plugin structure.
     */
    protected function define_course_plugin_structure() {
        $paths = [];

        $elename = 'pchkorg_config';
        $elepath = $this->get_pathfor('/pchkorg_configs/pchkorg_config');

        $paths[] = new restore_path_element($elename, $elepath);

        return $paths; // And we return the interesting paths.
    }


    /**


     * Declare what is restored at activity level.


     */


    /**
     * Define module plugin structure.
     */
    protected function define_module_plugin_structure() {
        $paths = [];

        $elename = 'pchkorgconfigmod';
        $elepath = $this->get_pathfor('/pchkorg_activities_configs/pchkorg_activities_config');
        $paths[] = new restore_path_element($elename, $elepath);

        $elename = 'pchkorgfiles';
        $elepath = $this->get_pathfor('/pchkorg_file/pchkorg_file');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }
}
