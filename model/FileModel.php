<?php

class FileModel
{
    private $db;

    const STATE_SENT = 1;
    const STATE_CHECKED = 2;

    public function __construct($DB)
    {
        $this->db = $DB;
    }

    public function findFileByModuleAndFile($module, $fileid)
    {
        $where         = new \stdClass();
        $where->cm     = $module;
        $where->fileid = $fileid;

        return $this->db->get_record('plagiarism_pchkorg_files', (array)$where);
    }

    public function create($fileRecord)
    {
        return $this->db->insert_record('plagiarism_pchkorg_files', $fileRecord);
    }

    public function update($fileRecord)
    {
        return $this->db->update_record('plagiarism_pchkorg_files', $fileRecord);
    }
}
