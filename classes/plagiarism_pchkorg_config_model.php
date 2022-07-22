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

/**
 * Class plagiarism_pchkorg_config_model
 */
class plagiarism_pchkorg_config_model {

    /**
     *
     * Check if plugin is enable for some specific module.
     * Result is static.
     *
     * @param $module
     * @return bool
     */
    public function is_enabled_for_module($module) {
        global $DB;

        static $resultmap = array();
        // This will be called only once per module.
        if (!array_key_exists($module, $resultmap)) {
            $configs = $DB->get_records('plagiarism_pchkorg_config', array(
                    'cm' => $module,
                    'name' => 'pchkorg_module_use'
            ));

            $enabled = false;
            foreach ($configs as $record) {
                switch ($record->name) {
                    case 'pchkorg_module_use':
                        $enabled = '1' == $record->value;
                        break;
                    default:
                        break;
                }
            }

            $resultmap[$module] = $enabled;
        }

        return $resultmap[$module];
    }

    /**
     *
     * Check if plugin show widget to student.
     * Result is static.
     *
     * @param $module
     * @return bool
     */
    public function show_widget_for_student($module) {
        global $DB;

        static $resultmap = array();
        // This will be called only once per module.
        if (!array_key_exists($module, $resultmap)) {
            $configs = $DB->get_records('plagiarism_pchkorg_config', array(
                'cm' => $module,
                'name' => 'pchkorg_student_can_see_widget'
            ));

            $enabled = null;
            foreach ($configs as $record) {
                switch ($record->name) {
                    case 'pchkorg_student_can_see_widget':
                        $enabled = '1' == $record->value;
                        break;
                    default:
                        break;
                }
            }

            $resultmap[$module] = $enabled;
        }

        return $resultmap[$module];
    }

    /**
     *
     * Check if plugin show report to student.
     * Result is static.
     *
     * @param $module
     * @return bool
     */
    public function show_report_for_student($module) {
        global $DB;

        static $resultmap = array();
        // This will be called only once per module.
        if (!array_key_exists($module, $resultmap)) {
            $configs = $DB->get_records('plagiarism_pchkorg_config', array(
                'cm' => $module,
                'name' => 'pchkorg_student_can_see_report'
            ));

            $enabled = null;
            foreach ($configs as $record) {
                switch ($record->name) {
                    case 'pchkorg_student_can_see_report':
                        $enabled = '1' == $record->value;
                        break;
                    default:
                        break;
                }
            }

            $resultmap[$module] = $enabled;
        }

        return $resultmap[$module];
    }

    /**
     *
     * Get value for search setting
     *
     * @param $module
     * @param $name
     * @return bool
     */
    public function get_filter_for_module($module, $name) {
        global $DB;

        $configs = $DB->get_records('plagiarism_pchkorg_config', array(
            'cm' => $module,
            'name' => $name,
        ));

        $value = null;
        foreach ($configs as $record) {
            if ($record->name === $name) {
                $value = $record->value;
                break;
            }
        }

        return $value;
    }

    /**
     *
     * Save plugin settings.
     *
     * @param $name
     * @param $value
     */
    public function set_system_config($name, $value) {
        global $DB;

        $DB->delete_records('plagiarism_pchkorg_config', array(
                'cm' => 0,
                'name' => $name,
        ));

        $record = new \stdClass();
        $record->cn = 0;
        $record->name = $name;
        $record->value = $value;

        $DB->insert_record('plagiarism_pchkorg_config', $record);
    }

    /**
     * @param $name
     * @return |null
     */
    public function get_system_config($name) {
        global $DB;

        // SQL query will be called only one per one setting name.
        static $resultsmap = array();
        if (!array_key_exists($name, $resultsmap)) {
            $records = $DB->get_records('plagiarism_pchkorg_config', array(
                    'cm' => 0,
                    'name' => $name,
            ));
            $resultsmap[$name] = null;
            foreach ($records as $record) {
                $resultsmap[$name] = $record->value;
                break;
            }
        }

        return $resultsmap[$name];
    }

    /**
     *
     * Fetch all plugin settings as array.
     * Result is static.
     *
     * @return array
     */
    public function get_all_system_config() {
        global $DB;

        static $map = array();
        if (empty($map)) {
            $records = $DB->get_records('plagiarism_pchkorg_config', array(
                    'cm' => 0,
            ));

            foreach ($records as $record) {
                $map[$record->name] = $record->value;
            }
        }

        return $map;
    }
}
