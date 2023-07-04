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
 * Class provider HTTP-API methods.
 */
class plagiarism_pchkorg_api_provider {

    /**
     * Auth token.
     *
     * @var string
     */
    private $token;

    /**
     * Url of api.
     *
     * @var string
     */
    private $endpoint;

    /**
     * Last api error.
     *
     * @var string|null
     */
    private $lasterror;

    /**
     * Fetch last api error.
     *
     * @return mixed
     */
    public function get_last_error() {
        return $this->lasterror;
    }

    /**
     * Setup last api error.
     *
     * @param mixed $lasterror
     */
    public function set_last_error($lasterror) {
        $this->lasterror = $lasterror;
    }

    /**
     * Constructor for api provider.
     *
     * @param $token
     * @param string $endpoint
     */
    public function __construct($token, $endpoint = 'https://plagiarismcheck.org') {
        $this->token = $token;
        $this->endpoint = $endpoint;
    }

    /**
     * Send text for originality check.
     *
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     *
     * @return |null
     */
    public function general_send_check(
        $authorhash,
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = array()
    ) {
        if ($this->is_group_token()) {
            return $this->send_group_text(
                $authorhash,
                $cousereid,
                $assignmentid,
                $assignmentname,
                $submissionid,
                $attachmentid,
                $content,
                $mime,
                $filename,
                $filters
            );
        } else {
            return $this->send_text(
                $cousereid,
                $assignmentid,
                $assignmentname,
                $submissionid,
                $attachmentid,
                $content,
                $mime,
                $filename,
                $filters
            );
        }
    }

    /**
     * Send text for originality check.
     *
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     *
     * @return |null
     */
    public function send_group_text(
        $authorhash,
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = array()
    ) {

        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $curl = new curl();
        $response = $curl->post(
                $this->endpoint . '/lms/moodle/check-text/',
                $this->get_body_for_group(
                        $boundary,
                        $authorhash,
                        $cousereid,
                        $assignmentid,
                        $assignmentname,
                        $submissionid,
                        $attachmentid,
                        $content,
                        $mime,
                        $filename,
                        $filters
                ),
                array(
                        'CURLOPT_RETURNTRANSFER' => true,
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
     * Build HTTP body of request.
     *
     * @param $boundary
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     * @return string
     */
    private function get_body_for_group(
        $boundary,
        $authorhash,
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = array()
    ) {
        $eol = "\r\n";

        $body = '';
        $body .= $this->get_part('token', $this->token, $boundary);
        $body .= $this->get_part('hash', $authorhash, $boundary);
        $body .= $this->get_part('course_id', $cousereid, $boundary);
        $body .= $this->get_part('assignment_id', $assignmentid, $boundary);
        $body .= $this->get_part('assignment_name', $assignmentname, $boundary);
        $body .= $this->get_part('submission_id', $submissionid, $boundary);
        $body .= $this->get_part('attachment_id', $attachmentid, $boundary);
        $body .= $this->get_part('filename', $filename, $boundary);
        $body .= $this->get_part('language', 'en', $boundary);
        $body .= $this->get_part('skip_english_words_validation', '1', $boundary);
        $body .= $this->get_part('skip_percentage_words_validation', '1', $boundary);
        $body .= $this->get_part('lms', 'moodle', $boundary);
        foreach ($filters as $filtername => $filtervalue) {
            if ($filtervalue !== null) {
                $body .= $this->get_part($filtername, $filtervalue, $boundary);
            }
        }
        $body .= $this->get_file_part('content', $content, $mime, $filename, $boundary);
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * Send text for originality check.
     *
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     *
     * @return |null
     */
    public function send_text(
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = array()) {

        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $curl = new curl();
        $response = $curl->post(
                $this->endpoint . '/api/v1/text',
                $this->get_body(
                    $boundary,
                    $cousereid,
                    $assignmentid,
                    $assignmentname,
                    $submissionid,
                    $attachmentid,
                    $content,
                    $mime,
                    $filename,
                    $filters
                ),
                array(
                        'CURLOPT_RETURNTRANSFER' => true,
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
     *
     * Method send information to service thar agreement had been accepted.
     * Method will be called only for personal account type.
     *
     * @param string $email User email
     * @return void
     */
    public function save_accepted_agreement($email) {
        $token = $this->token;
        if ($this->is_group_token()) {
            $token = $this->token . '::' . hash('sha256', $this->token . $email);
        }

        $curl = new curl();
        $curl->post(
                $this->endpoint . '/api/v1/agreement/create/moodle-plugin/2019-04-11/',
                '',
                array(
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_POST' => true,
                        'CURLOPT_HTTPHEADER' => array(
                                'X-API-TOKEN: ' . $token,
                        ),
                )
        );
    }

    /**
     * Build part of HTTP body.
     *
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
     * Build part of HTTP body. This part contains file.
     *
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
     * Build HTTP body of request.
     *
     * @param $boundary
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param $content
     * @param $mime
     * @param $filename
     * @return string
     */
    private function get_body(
        $boundary,
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = array()
    ) {
        $eol = "\r\n";

        $body = '';
        $body .= $this->get_part('language', 'en', $boundary);
        $body .= $this->get_part('skip_english_words_validation', '1', $boundary);
        $body .= $this->get_part('skip_percentage_words_validation', '1', $boundary);
        $body .= $this->get_part('course_id', $cousereid, $boundary);
        $body .= $this->get_part('assignment_id', $assignmentid, $boundary);
        $body .= $this->get_part('assignment_name', $assignmentname, $boundary);
        $body .= $this->get_part('submission_id', $submissionid, $boundary);
        $body .= $this->get_part('attachment_id', $attachmentid, $boundary);
        $body .= $this->get_part('lms', 'moodle', $boundary);
        foreach ($filters as $filtername => $filtervalue) {
            if ($filtervalue !== null) {
                $body .= $this->get_part($filtername, $filtervalue, $boundary);
            }
        }
        $body .= $this->get_file_part('text', $content, $mime, $filename, $boundary);
        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * Convert user email to sha256 salted hash.
     *
     * @param $email
     * @return string
     */
    public function user_email_to_hash($email) {
        // We don't send raw user email to the service.
        return hash('sha256', $this->token . strtolower($email));
    }

    /**
     * Check type of service account.
     * There are two types of accounts: personal and group.
     *
     * @return bool
     */
    public function is_group_token() {
        return 'G-' === \substr($this->token, 0, 2);
    }

    /**
     * Check that user belongs to group when it is group account.
     *
     * @param string $email
     * @return bool
     */
    public function is_group_member($email = '') {
        if (!$this->is_group_token()) {
            return true;
        }

        static $resultmap = array();

        if (!array_key_exists($email, $resultmap)) {
            $resultmap[$email] = false;
            $curl = new curl();
            $response = $curl->post($this->endpoint . '/lms/moodle/is-group-member/', array(
                    'token' => $this->token,
                    'hash' => $this->user_email_to_hash($email)
            ), array(
                    'CURLOPT_RETURNTRANSFER' => true,
                // The maximum number of seconds to allow cURL functions to execute.
                    'CURLOPT_TIMEOUT' => 8
            ));

            if ($json = json_decode($response)) {
                if (true == $json->is_member) {
                    $resultmap[$email] = true;
                }
            }
        }

        return $resultmap[$email];
    }

    /**
     * Check that user belongs to group when it is group account.
     * And Receive auto_registration_option.
     *
     * @param string $email
     * @return object
     */
    public function get_group_member_response($email = '') {
        if (!$this->is_group_token()) {
            $result = new \stdClass;
            $result->is_member = true;
            $result->is_auto_registration_enabled = false;

            return $result;
        }

        static $resultmap = array();

        if (!array_key_exists($email, $resultmap)) {
            //default result. For case when we can not receive response.
            $result = new \stdClass;
            $result->is_member = false;
            $result->is_auto_registration_enabled = false;
            $resultmap[$email] = $result;

            $curl = new curl();
            $response = $curl->post($this->endpoint . '/lms/moodle/is-group-member/', array(
                    'token' => $this->token,
                    'hash' => $this->user_email_to_hash($email)
            ), array(
                    'CURLOPT_RETURNTRANSFER' => true,
                // The maximum number of seconds to allow cURL functions to execute.
                    'CURLOPT_TIMEOUT' => 8
            ));

            if ($json = json_decode($response)) {
                $result->is_member = $json->is_member;
                $result->is_auto_registration_enabled = $json->is_auto_registration_enabled;
                $resultmap[$email] = $result;
            }
        }

        return $resultmap[$email];
    }

   /**
     * Auto registration is enabled for this university,
     *  so we registrate a user and user can check submissions.
     *
     * @param $name
     * @param $email
     * @param $role
     *
     * @return bool
     */
    public function auto_registrate_member($name, $email, $role) {
        $curl = new curl();
        $response = $curl->post($this->endpoint . '/lms/moodle/auto-registration/', array(
                'token' => $this->token,
                'name' => $name,
                'email' => $email,
                'role' => $role,
        ), array(
                'CURLOPT_RETURNTRANSFER' => true,
            // The maximum number of seconds to allow cURL functions to execute.
                'CURLOPT_TIMEOUT' => 8
        ));


        if ($json = json_decode($response)) {
            return $json->success;
        }

        return false;
    }

    /**
     * Check status of document.
     * If document has been checked, state is 5.
     *
     * @param $textid
     * @return object|null
     */
    public function check_text($textid) {
        if ($this->is_group_token()) {
            // The same method but for group users.
            // It uses different auth.
            return $this->group_check_text($textid);
        }

        $curl = new curl();
        $response = $curl->get($this->endpoint . '/api/v1/text/' . $textid, array(), array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_POST' => false,
                'CURLOPT_HTTPHEADER' => array(
                        'X-API-TOKEN: ' . $this->generate_api_token(),
                        'Content-Type: application/x-www-form-urlencoded'
                ),
        ));
        if ($json = json_decode($response)) {
            if (isset($json->data) && 5 == $json->data->state) {
                $result = new stdClass;
                $result->id = $json->data->report->id;
                $result->percent = $json->data->report->percent;
                $result->percent_ai = $json->data->ai_report->processed_percent;

                return $result;
            }
        }

        return null;
    }

    /**
     * Check status of document for Group User
     * If document has been checked, state is 5.
     *
     * @param $textid
     * @return object|null
     */
    public function group_check_text($textid) {
        $curl = new curl();
        $response = $curl->get("{$this->endpoint}/lms/check-report/{$textid}/", array(
                'token' => $this->token
        ), array(
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_POST' => false,
                'CURLOPT_HTTPHEADER' => array(
                        'Content-Type: application/x-www-form-urlencoded'
                ),
        ));
        if ($json = json_decode($response)) {
            if (isset($json->data) && 5 == $json->data->state) {
                $result = new stdClass;
                $result->id = $json->data->report->id;
                $result->percent = $json->data->report->percent;
                $result->percent_ai = $json->data->ai_report->processed_percent;

                return $result;
            }
        }

        return null;
    }

    /**
     * Build url for the api.
     *
     * @param $id
     * @return string
     */
    public function get_report_action($id) {
        return "{$this->endpoint}/lms/public-report/{$id}/";
    }

    /**
     * Generate token for API auth.
     *
     * @return string
     */
    public function generate_api_token() {
        global $USER;

        if ($this->is_group_token()) {
            return $this->token . '::' . hash('sha256', $this->token . strtolower($USER->email));
        }

        return $this->token;
    }

    /**
     * List of supported mime.
     *
     * @param $mime
     * @return bool
     */
    public function is_supported_mime($mime) {
        return in_array($mime, array(
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'application/pdf'
        ), true);
    }

    /**
     * Return maximum size of document.
     *
     * @return int
     */
    public function get_max_filesize() {
        return 20 * 1048576;
    }
}
