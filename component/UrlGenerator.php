<?php

class UrlGenerator
{
    public function getCheckUrl($cmid, $fileid)
    {
        return new moodle_url(sprintf(
                '/plagiarism/pchkorg/page/report.php?cmid=%s&file=%s',
                intval($cmid),
                intval($fileid)
            )
        );
    }

    public function getStatusUrl()
    {
        return new moodle_url('/plagiarism/pchkorg/page/status.php');
    }
}
