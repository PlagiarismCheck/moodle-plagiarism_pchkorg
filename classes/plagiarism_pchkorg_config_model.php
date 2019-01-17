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

class plagiarism_pchkorg_config_model {
    private $db;

    public function __construct($DB) {
        $this->db = $DB;
    }

    public function fetch_by_module($module) {
        return $this->db->get_records('plagiarism_pchkorg_config', array(
                'cm' => $module,
        ));
    }

    public function is_enabled_for_module($module) {
        $configs = $this->fetch_by_module($module);
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

        return $enabled;
    }

    public function set_system_config($name, $value) {
        $this->db->delete_records('plagiarism_pchkorg_config', array(
                'cm' => 0,
                'name' => $name,
        ));

        $record = new \stdClass();
        $record->cn = 0;
        $record->name = $name;
        $record->value = $value;

        $this->db->insert_record('plagiarism_pchkorg_config', $record);
    }

    public function get_system_config($name) {
        $records = $this->db->get_records('plagiarism_pchkorg_config', array(
                'cm' => 0,
                'name' => $name,
        ));

        foreach ($records as $record) {
            return $record->value;
        }

        return null;
    }

    public function get_all_system_config() {
        $records = $this->db->get_records('plagiarism_pchkorg_config', array(
                'cm' => 0,
        ));
        $map = array();
        foreach ($records as $record) {
            $map[$record->name] = $record->value;
        }

        return $map;
    }
}
