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
 * Class plagiarism_pchkorg_api_provider
 */
class plagiarism_pchkorg_api_provider {

    /**
     * @var
     */
    private $token;
    /**
     * @var string
     */
    private $endpoint;

    /**
     * @var
     */
    private $lasterror;

    /**
     * @return mixed
     */
    public function get_last_error() {
        return $this->lasterror;
    }

    /**
     * @param mixed $lasterror
     */
    public function set_last_error($lasterror) {
        $this->lasterror = $lasterror;
    }

    /**
     * plagiarism_pchkorg_api_provider constructor.
     *
     * @param $token
     * @param string $endpoint
     */
    public function __construct($token, $endpoint = 'https://plagiarismcheck.org') {
        $this->token = $token;
        $this->endpoint = $endpoint;
    }

    /**
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     * @return |null
     */
    public function send_group_text($authorhash, $cousereid, $assignmentid, $submissionid, $attachmentid, $content, $mime,
            $filename) {

        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $curl = new curl();
        $response = $curl->post(
                $this->endpoint . '/lms/moodle/check-text/',
                $this->get_body_for_group(
                        $boundary,
                        $authorhash,
                        $cousereid,
                        $assignmentid,
                        $submissionid,
                        $attachmentid,
                        $content,
                        $mime,
                        $filename),
                array(
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_FOLLOWLOCATION' => true,
                        'CURLOPT_SSL_VERIFYHOST' => false,
                        'CURLOPT_SSL_VERIFYPEER' => false,
                        'CURLOPT_HTTPHEADER' => array(
                                'X-API-TOKEN: ' . $this->generate_api_token(),
                                'Content-Type: multipart/form-data; boundary=' . $boundary
                        ),
                )
        );
        $id = null;
        if ($json = json_decode($response)) {
            if (isset($json->message)) {
                $this->set_last_error($json->message);
                return null;
            }
            if (isset($json->success) && $json->success) {
                $id = $json->data->text->id;
            }
        }

        return $id;
    }

    /**
     * @param $boundary
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     * @return string
     */
    private function get_body_for_group($boundary,
            $authorhash,
            $cousereid,
            $assignmentid,
            $submissionid,
            $attachmentid,
            $content,
            $mime,
            $filename) {
        $eol = "\r\n";

        $body = '';
        $body .= $this->get_part('token', $this->token, $boundary);
        $body .= $this->get_part('hash', $authorhash, $boundary);
        $body .= $this->get_part('course_id', $cousereid, $boundary);
        $body .= $this->get_part('assignment_id', $assignmentid, $boundary);
        $body .= $this->get_part('submission_id', $submissionid, $boundary);
        $body .= $this->get_part('attachment_id', $attachmentid, $boundary);
        $body .= $this->get_part('filename', $filename, $boundary);
        $body .= $this->get_part('language', 'en', $boundary);
        $body .= $this->get_file_part('content', $content, $mime, $filename, $boundary);
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * @param $content
     * @param $mime
     * @param $filename
     * @return |null
     */
    public function send_text($content, $mime, $filename) {
        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $curl = new curl();
        $response = $curl->post(
                $this->endpoint . '/api/v1/text',
                $this->get_body($boundary, $content, $mime, $filename),
                array(
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_FOLLOWLOCATION' => true,
                        'CURLOPT_SSL_VERIFYHOST' => false,
                        'CURLOPT_SSL_VERIFYPEER' => false,
                        'CURLOPT_POST' => true,
                        'CURLOPT_HTTPHEADER' => array(
                                'X-API-TOKEN: ' . $this->generate_api_token(),
                                'Content-Type: multipart/form-data; boundary=' . $boundary
                        ),
                )
        );
        $id = null;
        if ($json = json_decode($response)) {
            if (isset($json->message)) {
                $this->set_last_error($json->message);
                return null;
            }
            if (isset($json->success) && $json->success) {
                $id = $json->data->text->id;
            }
        }

        return $id;
    }

    /**
     * @param $name
     * @param $value
     * @param $boundary
     * @return string
     */
    private function get_part($name, $value, $boundary) {
        $eol = "\r\n";

        $part = '--' . $boundary . $eol;
        $part .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
        $part .= $value . $eol;

        return $part;
    }

    /**
     * @param $name
     * @param $value
     * @param $mime
     * @param $filename
     * @param $boundary
     * @return string
     */
    private function get_file_part($name, $value, $mime, $filename, $boundary) {
        $eol = "\r\n";

        $part = '--' . $boundary . $eol;
        $part .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '";' . $eol;
        $part .= 'Content-Type: ' . $mime . $eol;
        $part .= 'Content-Length: ' . strlen($value) . $eol . $eol;
        $part .= $value . $eol;

        return $part;
    }

    /**
     * @param $boundary
     * @param $content
     * @param $mime
     * @param $filename
     * @return string
     */
    private function get_body($boundary, $content, $mime, $filename) {
        $eol = "\r\n";

        $body = '';
        $body .= $this->get_part('language', 'en', $boundary);
        $body .= $this->get_file_part('text', $content, $mime, $filename, $boundary);
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * @param $email
     * @return string
     */
    public function user_email_to_hash($email) {
        return hash('sha256', $this->token . $email);
    }

    /**
     * @return bool
     */
    public function is_group_token() {
        return 'G-' === \substr($this->token, 0, 2);
    }

    /**
     * @param string $email
     * @return bool
     */
    public function is_group_member($email = '') {
        if (!$this->is_group_token()) {
            return true;
        }

        $curl = new curl();
        $response = $curl->post($this->endpoint . '/lms/moodle/is-group-member/', array(
                'token' => $this->token,
                'hash' => $this->user_email_to_hash($email)
        ), array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_FOLLOWLOCATION' => true,
                'CURLOPT_SSL_VERIFYHOST' => false,
                'CURLOPT_SSL_VERIFYPEER' => false,
        ));

        if ($json = json_decode($response)) {
            if (true == $json->is_member) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $textid
     * @return |null
     */
    public function check_text($textid) {
        $curl = new curl();
        $response = $curl->get($this->endpoint . '/api/v1/text/' . $textid, array(), array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_FOLLOWLOCATION' => true,
                'CURLOPT_SSL_VERIFYHOST' => false,
                'CURLOPT_SSL_VERIFYPEER' => false,
                'CURLOPT_POST' => false,
                'CURLOPT_HTTPHEADER' => array(
                        'X-API-TOKEN: ' . $this->generate_api_token(),
                        'Content-Type: application/x-www-form-urlencoded'
                ),
        ));
        if ($json = json_decode($response)) {
            if (isset($json->data) && 5 == $json->data->state) {
                return $json->data->report;
            }
        }

        return null;
    }

    /**
     * @param $id
     * @return string
     */
    public function get_report_action($id) {
        return "{$this->endpoint}/lms/public-report/{$id}/";
    }

    /**
     * @return string
     */
    public function generate_api_token() {
        global $USER;

        if ($this->is_group_token()) {
            return $this->token . '::' . hash('sha256', $this->token . $USER->email);
        }

        return $this->token;
    }

    /**
     * @param $mime
     * @return bool
     */
    public function is_supported_mime($mime) {
        return in_array($mime, array(
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf',
                'application/vnd.oasis.opendocument.text',
                'text/plain',
                'application/pdf',
        ), true);
    }
}
