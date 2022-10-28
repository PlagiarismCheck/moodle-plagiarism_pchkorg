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

class backup_plagiarism_pchkorg_plugin extends backup_plagiarism_plugin {
    /**
     * define_module_plugin_structure
     *
     * @return mixed
     */
    public function define_module_plugin_structure() {
        // Define the virtual plugin element without conditions as the global class checks already.
        $plugin = $this->get_plugin_element();

        // Create one standard named plugin element (the visible container).
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Connect the visible container ASAP.
        $plugin->add_child($pluginwrapper);

        $configs = new backup_nested_element('pchkorg_configs');
        $config = new backup_nested_element('pchkorg_config', ['id'], ['name', 'value']);
        $pluginwrapper->add_child($configs);
        $configs->add_child($config);
        $config->set_source_table('plagiarism_pchkorg_config', ['cm' => backup::VAR_PARENTID]);

        // Now information about files to module.
        $ufiles = new backup_nested_element('pchkorg_files');
        $ufile = new backup_nested_element('pchkorg_file', ['id'], [
            'cm', 'fileid', 'userid', 
            'state', 'score', 'created_at', 
            'textid', 'reportid', 'signature', 
            'attempt', 'itemid'
        ]);

        $pluginwrapper->add_child($ufiles);
        $ufiles->add_child($ufile);

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');
        if ($userinfo) {
            $ufile->set_source_table('plagiarism_pchkorg_files', ['cm' => backup::VAR_PARENTID]);
        }

        return $plugin;
    }

    /**
     * define_course_plugin_structure
     *
     * @return mixed
     */
    public function define_course_plugin_structure() {
        // Define the virtual plugin element without conditions as the global class checks already.
        $plugin = $this->get_plugin_element();

        // Create one standard named plugin element (the visible container).
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Connect the visible container ASAP.
        $plugin->add_child($pluginwrapper);
        // Save id from pchkorg course.
        $unconfigs = new backup_nested_element('pchkorg_configs');
        $unconfig = new backup_nested_element('pchkorg_config', ['id'], ['plugin', 'name', 'value']);
        $pluginwrapper->add_child($unconfigs);
        $unconfigs->add_child($unconfig);
        $unconfig->set_source_table('config_plugins', [
            'name' => backup::VAR_PARENTID, 'plugin' => 'plagiarism',
        ]);

        return $plugin;
    }
}
