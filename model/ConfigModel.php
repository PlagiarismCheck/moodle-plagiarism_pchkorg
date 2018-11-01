<?php

class ConfigModel
{
    private $db;

    public function __construct($DB)
    {
        $this->db = $DB;
    }

    public function fetchByModule($module)
    {
        return $this->db->get_records('plagiarism_pchkorg_config', array(
            'cm' => $module,
        ));
    }

    public function isEnabledForModule($module)
    {
        $configs = $this->fetchByModule($module);
        $enabled = false; // disabled by default
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

    public function setSystemConfig($name, $value)
    {
        $this->db->delete_records('plagiarism_pchkorg_config', [
            'cm' => 0,
            'name' => $name,
        ]);

        $record = new \stdClass();
        $record->cn = 0;
        $record->name = $name;
        $record->value = $value;

        $this->db->insert_record('plagiarism_pchkorg_config', $record);
    }

    public function getSystemConfig($name)
    {
        $records = $this->db->get_records('plagiarism_pchkorg_config', array(
            'cm' => 0,
            'name' => $name,
        ));

        foreach ($records as $record) {
            return $record->value;
        }

        return null;
    }

    public function getAllSystemConfig()
    {
        $records = $this->db->get_records('plagiarism_pchkorg_config', array(
            'cm' => 0,
        ));
        $map = [];
        foreach ($records as $record) {
            $map[$record->name] = $record->value;
        }

        return $map;
    }
}
