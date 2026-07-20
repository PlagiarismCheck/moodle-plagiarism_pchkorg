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
 * Client for the PlagiarismCheck.org HTTP API.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/state.php');
require_once(__DIR__ . '/transport.php');
require_once(__DIR__ . '/curl_transport.php');

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
    private $lasterror = null;

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
     * HTTP transport.
     *
     * @var plagiarism_pchkorg_transport
     */
    private $transport;

    /**
     * Constructor for api provider.
     *
     * @param $token
     * @param string $endpoint
     * @param plagiarism_pchkorg_transport|null $transport Defaults to Moodle curl.
     */
    public function __construct(
        $token,
        $endpoint = 'https://plagiarismcheck.org',
        $transport = null
    ) {
        $this->token = $token;
        $this->endpoint = $endpoint;
        if (null === $transport) {
            $transport = new plagiarism_pchkorg_curl_transport();
        }
        $this->transport = $transport;
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
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     *
     * @return int|null
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
        $filters = [],
        $email = null
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
                $filters,
                $email
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
                $filters,
                $email
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
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     *
     * @return int|null
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
        $filters = [],
        $email = null
    ) {

        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $response = $this->transport->post(
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
            [
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_TIMEOUT' => 50,
                        'CURLOPT_HTTPHEADER' => [
                                'X-API-TOKEN: ' . $this->generate_api_token($email),
                                'Content-Type: multipart/form-data; boundary=' . $boundary,
                        ],
                ]
        );
        $this->set_last_error(null);
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
        $filters = []
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
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     *
     * @return int|null
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
        $filters = [],
        $email = null
    ) {

        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));

        $response = $this->transport->post(
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
            [
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_TIMEOUT' => 50,
                        'CURLOPT_POST' => true,
                        'CURLOPT_HTTPHEADER' => [
                                'X-API-TOKEN: ' . $this->generate_api_token($email),
                                'Content-Type: multipart/form-data; boundary=' . $boundary,
                        ],
                ]
        );
        $this->set_last_error(null);
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

        $this->transport->post(
            $this->endpoint . '/api/v1/agreement/create/moodle-plugin/2019-04-11/',
            '',
            [
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_POST' => true,
                        'CURLOPT_HTTPHEADER' => [
                                'X-API-TOKEN: ' . $token,
                        ],
                ]
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
        $filters = []
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
        return hash('sha256', trim($this->token) . trim(strtolower($email)));
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
     * Membership is unknown while the service is unreachable, and this
     * boolean signature has no way to say so. Callers that need to tell
     * "confirmed non-member" apart from "could not ask" must use
     * {@see get_group_member_response()} and check is_known instead.
     *
     * @param string $email
     * @return bool
     */
    public function is_group_member($email = '') {
        return $this->get_group_member_response($email)->is_member;
    }

    /**
     * Check that user belongs to group when it is group account.
     * And receive the auto_registration option.
     *
     * @param string $email
     * @return object is_member, is_auto_registration_enabled, and is_known.
     *                 is_known is false when the service could not be reached
     *                 or answered with something that could not be parsed; in
     *                 that case is_member and is_auto_registration_enabled are
     *                 placeholders and must not be trusted or persisted.
     */
    public function get_group_member_response($email = '') {
        if (!$this->is_group_token()) {
            $result = new \stdClass();
            $result->is_member = true;
            $result->is_auto_registration_enabled = false;
            $result->is_known = true;

            return $result;
        }

        static $resultmap = [];

        if (array_key_exists($email, $resultmap)) {
            return $resultmap[$email];
        }

        $response = $this->transport->post($this->endpoint . '/lms/moodle/is-group-member/', [
                'token' => $this->token,
                'hash' => $this->user_email_to_hash($email),
        ], [
                'CURLOPT_RETURNTRANSFER' => true,
            // The maximum number of seconds to allow cURL functions to execute.
                'CURLOPT_TIMEOUT' => 8,
        ]);

        $result = $this->decode_group_member_response($response);
        if (null === $result) {
            // Unreachable or unparseable. Deliberately not cached: a later
            // call in the same request gets a fresh chance to reach the
            // service, rather than being stuck with this answer.
            $result = new \stdClass();
            $result->is_member = false;
            $result->is_auto_registration_enabled = false;
            $result->is_known = false;

            return $result;
        }

        $resultmap[$email] = $result;

        return $result;
    }

    /**
     * Decode an is-group-member response body.
     *
     * @param string|bool $response
     * @return object|null is_member and is_auto_registration_enabled, or
     *                      null when the response cannot be trusted (curl
     *                      failure, HTTP error body, truncated or unrelated
     *                      JSON, or a response missing either key).
     */
    private function decode_group_member_response($response) {
        if (false === $response || null === $response || '' === $response) {
            return null;
        }

        $json = json_decode($response);
        if (
            !is_object($json)
            || !property_exists($json, 'is_member')
            || !property_exists($json, 'is_auto_registration_enabled')
        ) {
            return null;
        }

        $result = new \stdClass();
        $result->is_member = (bool) $json->is_member;
        $result->is_auto_registration_enabled = (bool) $json->is_auto_registration_enabled;
        $result->is_known = true;

        return $result;
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
        $response = $this->transport->post($this->endpoint . '/lms/moodle/auto-registration/', [
                'token' => $this->token,
                'name' => $name,
                'email' => $email,
                'role' => $role,
        ], [
                'CURLOPT_RETURNTRANSFER' => true,
            // The maximum number of seconds to allow cURL functions to execute.
                'CURLOPT_TIMEOUT' => 30,
        ]);

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

        $response = $this->transport->get($this->endpoint . '/api/v1/text/' . $textid, [], [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 30,
                'CURLOPT_POST' => false,
                'CURLOPT_HTTPHEADER' => [
                        'X-API-TOKEN: ' . $this->generate_api_token(),
                        'Content-Type: application/x-www-form-urlencoded',
                ],
        ]);

        if ($json = json_decode($response)) {
            if (
                isset($json->data)
                    && \in_array($json->data->state, plagiarism_pchkorg_state::remote_final_states(), true)
            ) {
                // The service returns a null report for texts which were never checked
                // (failed, dropped, erased), and a null ai_report whenever AI detection
                // did not run. Neither may be dereferenced unguarded.
                $result = new stdClass();
                $result->id = isset($json->data->report->id) ? $json->data->report->id : null;
                $result->state = $json->data->state;
                if (plagiarism_pchkorg_state::REMOTE_CHECKED === $json->data->state) {
                    $result->percent = isset($json->data->report->percent)
                        ? $json->data->report->percent
                        : null;
                    $result->percent_ai = isset($json->data->ai_report->processed_percent)
                        ? $json->data->ai_report->processed_percent
                        : null;
                } else {
                    $result->percent = null;
                    $result->percent_ai = null;
                }

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
        $response = $this->transport->get("{$this->endpoint}/lms/check-report/{$textid}/", [
                'token' => $this->token,
        ], [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 30,
                'CURLOPT_POST' => false,
                'CURLOPT_HTTPHEADER' => [
                        'Content-Type: application/x-www-form-urlencoded',
                ],
        ]);
        if ($json = json_decode($response)) {
            if (
                isset($json->data)
                    && \in_array($json->data->state, plagiarism_pchkorg_state::remote_final_states(), true)
            ) {
                // The service returns a null report for texts which were never checked
                // (failed, dropped, erased), and a null ai_report whenever AI detection
                // did not run. Neither may be dereferenced unguarded.
                $result = new stdClass();
                $result->id = isset($json->data->report->id) ? $json->data->report->id : null;
                $result->state = $json->data->state;
                if (plagiarism_pchkorg_state::REMOTE_CHECKED === $json->data->state) {
                    $result->percent = isset($json->data->report->percent)
                        ? $json->data->report->percent
                        : null;
                    $result->percent_ai = isset($json->data->ai_report->processed_percent)
                        ? $json->data->ai_report->processed_percent
                        : null;
                } else {
                    $result->percent = null;
                    $result->percent_ai = null;
                }

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
     * For a group token the credential is bound to the acting user's email. Callers which
     * do not run in the context of that user (scheduled tasks, where $USER is the cron
     * user rather than the submitting student) must pass the email explicitly.
     *
     * @param string|null $email Email of the acting user. Defaults to the logged in user.
     * @return string
     */
    public function generate_api_token($email = null) {
        global $USER;

        if (!$this->is_group_token()) {
            // A personal token is the credential on its own; no user is involved.
            return $this->token;
        }

        if (null === $email) {
            $email = isset($USER->email) ? $USER->email : '';
        }

        return $this->token . '::' . hash('sha256', $this->token . strtolower($email));
    }

    /**
     * List of supported mime.
     *
     * @param $mime
     * @return bool
     */
    public function is_supported_mime($mime) {
        return in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'application/pdf',
        ], true);
    }

    /**
     * Return maximum size of document.
     *
     * @return int
     */
    public function get_max_filesize() {
        return 20 * 1048576;
    }

    /**
     * Check the configured token against the service.
     *
     * Called only when plugin settings are saved. The three outcomes are kept
     * apart on purpose: an unreachable service must not be reported to the
     * administrator as an invalid token.
     *
     * @return object With ok (bool), reachable (bool) and group (object|null).
     */
    public function validate_token() {
        $result = new \stdClass();
        $result->ok = false;
        $result->reachable = true;
        $result->group = null;

        $response = $this->transport->post($this->endpoint . '/lms/moodle/token/validate/', [
            'token' => $this->token,
        ], [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 10,
        ]);

        if (false === $response || null === $response || '' === $response) {
            $result->reachable = false;

            return $result;
        }

        $json = json_decode($response);
        if (!is_object($json)) {
            $result->reachable = false;

            return $result;
        }

        if (isset($json->success) && $json->success) {
            $result->ok = true;
            if (isset($json->data->group)) {
                $result->group = $json->data->group;
            }
        }

        return $result;
    }

    /**
     * Active ignore templates of an assignment.
     *
     * @param string $assignmentkey
     * @param string $email Email of the acting user.
     * @return array|null Array of template objects, or null when the call failed.
     */
    public function ignore_template_list($assignmentkey, $email) {
        $json = $this->ignore_template_request('', [
            'token' => $this->token,
            'hash' => $this->user_email_to_hash($email),
            'assignment_key' => $assignmentkey,
        ]);

        if (null === $json || !isset($json->data->templates)) {
            return null;
        }

        return $json->data->templates;
    }

    /**
     * Delete one ignore template.
     *
     * @param string $assignmentkey
     * @param int $templateid
     * @param string $email
     * @return bool
     */
    public function ignore_template_delete($assignmentkey, $templateid, $email) {
        $json = $this->ignore_template_request('delete/', [
            'token' => $this->token,
            'hash' => $this->user_email_to_hash($email),
            'assignment_key' => $assignmentkey,
            'template_id' => (int) $templateid,
        ]);

        return null !== $json;
    }

    /**
     * Original bytes of one ignore template, for the download proxy.
     *
     * @param string $assignmentkey
     * @param int $templateid
     * @param string $email
     * @return string|null
     */
    public function ignore_template_download($assignmentkey, $templateid, $email) {
        $response = $this->transport->post(
            $this->endpoint . '/lms/moodle/assignment/ignore-templates/download/',
            [
                'token' => $this->token,
                'hash' => $this->user_email_to_hash($email),
                'assignment_key' => $assignmentkey,
                'template_id' => (int) $templateid,
            ],
            [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 60,
            ]
        );

        if (false === $response || null === $response || '' === $response) {
            $this->set_last_error('unreachable');

            return null;
        }

        // Errors are JSON; a template is the original file, which may be
        // anything. Only treat it as an error when it parses and says so.
        $json = json_decode($response);
        if (is_object($json) && isset($json->success) && !$json->success) {
            $this->set_last_error(isset($json->code) ? $json->code : 'template_not_found');

            return null;
        }

        return $response;
    }

    /**
     * Apply deletions, uploads and pasted text in one save.
     *
     * @param string $assignmentkey
     * @param array $files Each an array with filename, mime and content keys.
     * @param string $text Pasted template text, or empty.
     * @param array $deleteids Template ids to remove.
     * @param string $email
     * @return bool True on success; on failure see get_last_error().
     */
    public function ignore_template_save($assignmentkey, array $files, $text, array $deleteids, $email) {
        $boundary = sprintf('PLAGCHECKBOUNDARY-%s', uniqid(time()));
        $eol = "\r\n";

        $body = '';
        $body .= $this->get_part('token', $this->token, $boundary);
        $body .= $this->get_part('hash', $this->user_email_to_hash($email), $boundary);
        $body .= $this->get_part('assignment_key', $assignmentkey, $boundary);
        if ('' !== trim((string) $text)) {
            $body .= $this->get_part('template_text', $text, $boundary);
        }
        foreach (array_values($deleteids) as $index => $deleteid) {
            $body .= $this->get_part(sprintf('delete[%d]', $index), (int) $deleteid, $boundary);
        }
        foreach (array_values($files) as $index => $file) {
            $body .= $this->get_file_part(
                sprintf('templates[%d]', $index),
                $file['content'],
                $file['mime'],
                $file['filename'],
                $boundary
            );
        }
        $body .= '--' . $boundary . '--' . $eol;

        $response = $this->transport->post(
            $this->endpoint . '/lms/moodle/assignment/ignore-templates/save/',
            $body,
            [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 120,
                'CURLOPT_HTTPHEADER' => [
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                ],
            ]
        );

        return null !== $this->decode_ignore_template_response($response);
    }

    /**
     * POST to an ignore-template endpoint with simple form fields.
     *
     * @param string $path Appended to the ignore-templates base path.
     * @param array $fields
     * @return object|null Decoded response, or null on failure.
     */
    private function ignore_template_request($path, array $fields) {
        $response = $this->transport->post(
            $this->endpoint . '/lms/moodle/assignment/ignore-templates/' . $path,
            $fields,
            [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 15,
            ]
        );

        return $this->decode_ignore_template_response($response);
    }

    /**
     * Decode a response, recording the service's error code when it failed.
     *
     * @param string|bool $response
     * @return object|null
     */
    private function decode_ignore_template_response($response) {
        if (false === $response || null === $response || '' === $response) {
            $this->set_last_error('unreachable');

            return null;
        }

        $json = json_decode($response);
        if (!is_object($json)) {
            $this->set_last_error('unreachable');

            return null;
        }

        if (!isset($json->success) || !$json->success) {
            $this->set_last_error(isset($json->code) ? $json->code : 'template_processing_failed');

            return null;
        }

        $this->set_last_error(null);

        return $json;
    }
}
