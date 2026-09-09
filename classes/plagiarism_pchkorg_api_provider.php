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
require_once(__DIR__ . '/group_info.php');

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
     * Membership answers already fetched, keyed by identity string.
     *
     * Shared by every instance so that repeated checks within one request cost
     * one HTTP call. {@see reset_caches()} clears it.
     *
     * @var array
     */
    private static $membercache = [];

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
     * @param string|stored_file $content
     * @param $mime
     * @param $filename
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     * @param string|null $coursename Course fullname, shown in service reports.
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
        $email = null,
        $coursename = null
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
                $email,
                $coursename
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
                $email,
                $coursename
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
     * @param string|stored_file $content
     * @param $mime
     * @param $filename
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     * @param string|null $coursename Course fullname, shown in service reports.
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
        $email = null,
        $coursename = null
    ) {

        $response = $this->transport->post(
            $this->endpoint . '/lms/moodle/check-text/',
            $this->get_fields_for_group(
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
                $coursename
            ),
            [
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_TIMEOUT' => 50,
                        'CURLOPT_HTTPHEADER' => [
                                'X-API-TOKEN: ' . $this->generate_api_token($email),
                            // Content-Type is deliberately absent: curl sets it,
                            // with the boundary it generated for the body.
                                'Expect:',
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
     * Build the form fields of a group send.
     *
     * @param $authorhash
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param string|stored_file $content
     * @param $mime
     * @param $filename
     * @param array $filters
     * @param string|null $coursename Course fullname, shown in service reports.
     * @return array
     */
    private function get_fields_for_group(
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
        $coursename = null
    ) {
        $fields = array_merge($this->common_fields(), [
            'token' => $this->token,
            'hash' => $authorhash,
            'course_id' => $cousereid,
            'assignment_id' => $assignmentid,
            'assignment_name' => $assignmentname,
            'submission_id' => $submissionid,
            'attachment_id' => $attachmentid,
            'filename' => $filename,
        ]);

        $fields = array_merge($fields, $this->course_name_field($coursename));
        $fields = array_merge($fields, $this->filter_fields($filters));
        $fields['content'] = $this->file_field($content, $mime, $filename);

        return $fields;
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
     * @param string|stored_file $content
     * @param $mime
     * @param $filename
     * @param array $filters
     * @param string|null $email Email of the acting user, for group token auth.
     * @param string|null $coursename Course fullname, shown in service reports.
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
        $email = null,
        $coursename = null
    ) {

        $response = $this->transport->post(
            $this->endpoint . '/api/v1/text',
            $this->get_fields(
                $cousereid,
                $assignmentid,
                $assignmentname,
                $submissionid,
                $attachmentid,
                $content,
                $mime,
                $filename,
                $filters,
                $coursename
            ),
            [
                        'CURLOPT_RETURNTRANSFER' => true,
                        'CURLOPT_TIMEOUT' => 50,
                        'CURLOPT_HTTPHEADER' => [
                                'X-API-TOKEN: ' . $this->generate_api_token($email),
                            // Content-Type is deliberately absent: curl sets it,
                            // with the boundary it generated for the body.
                                'Expect:',
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
     * Fields every send carries, whatever the token type.
     *
     * @return array
     */
    private function common_fields() {
        return [
            'language' => 'en',
            'skip_english_words_validation' => '1',
            'skip_percentage_words_validation' => '1',
            'lms' => 'moodle',
        ];
    }

    /**
     * The course name as a form field, if there is one to send.
     *
     * Omitted from the request entirely when absent or blank, rather than sent
     * empty: the service treats a missing course_name as "this site does not
     * report one", which is exactly how every plugin release before this one
     * reads to it.
     *
     * @param string|null $coursename
     * @return array
     */
    private function course_name_field($coursename) {
        if (null === $coursename) {
            return [];
        }

        $coursename = trim($coursename);
        if ('' === $coursename) {
            return [];
        }

        return ['course_name' => $coursename];
    }

    /**
     * Search filters as form fields.
     *
     * A null filter means "the activity does not say", which is not the same as
     * saying no, so it is left out of the request entirely rather than sent as
     * an empty field the service would have to interpret.
     *
     * @param array $filters
     * @return array
     */
    private function filter_fields($filters) {
        $fields = [];
        foreach ($filters as $filtername => $filtervalue) {
            if ($filtervalue !== null) {
                $fields[$filtername] = $filtervalue;
            }
        }

        return $fields;
    }

    /**
     * A file form field.
     *
     * curl uploads from a path, so what this returns is always a path plus the
     * name and type to declare for it. Where the caller has a stored_file, that
     * path is the file pool's own, and a submission is never read into PHP
     * memory on its way to the service.
     *
     * Content the plugin holds only as a string -- online text, a forum post --
     * has no pool file behind it and is spooled to a temporary one.
     * make_request_directory() hands back a fresh directory per call, so two
     * parts of the same request cannot collide, and Moodle removes it when the
     * request ends; there is nothing to clean up here.
     *
     * The name on disk is never transmitted. What the service sees is the third
     * argument, and on the personal-token path that filename is the only thing
     * telling it which parser to use, so it is always passed explicitly rather
     * than left to be inferred from the path.
     *
     * @param string|stored_file $content
     * @param string $mime
     * @param string $filename Name the service should see.
     * @return CURLFile
     */
    private function file_field($content, $mime, $filename) {
        if ($content instanceof stored_file) {
            // Fetches a local copy first on a remote file system, which is what
            // Moodle's own stored_file-to-curl integration does.
            $path = get_file_storage()
                ->get_file_system()
                ->get_local_path_from_storedfile($content, true);

            return curl_file_create($path, $mime, $filename);
        }

        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);

        return curl_file_create($path, $mime, $filename);
    }

    /**
     * Build the form fields of a personal send.
     *
     * @param $cousereid
     * @param $assignmentid
     * @param $assignmentname
     * @param $submissionid
     * @param $attachmentid
     * @param string|stored_file $content
     * @param $mime
     * @param $filename
     * @param array $filters
     * @param string|null $coursename Course fullname, shown in service reports.
     * @return array
     */
    private function get_fields(
        $cousereid,
        $assignmentid,
        $assignmentname,
        $submissionid,
        $attachmentid,
        $content,
        $mime,
        $filename,
        $filters = [],
        $coursename = null
    ) {
        $fields = array_merge($this->common_fields(), [
            'course_id' => $cousereid,
            'assignment_id' => $assignmentid,
            'assignment_name' => $assignmentname,
            'submission_id' => $submissionid,
            'attachment_id' => $attachmentid,
        ]);

        $fields = array_merge($fields, $this->course_name_field($coursename));
        $fields = array_merge($fields, $this->filter_fields($filters));
        // This endpoint takes no filename field: it reads the name off the part
        // itself, which is why file_field() is always given one.
        $fields['text'] = $this->file_field($content, $mime, $filename);

        return $fields;
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

        if (array_key_exists($email, self::$membercache)) {
            return self::$membercache[$email];
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

        self::$membercache[$email] = $result;

        return $result;
    }

    /**
     * Forget cached membership answers.
     *
     * The cache is process-wide and keyed by identity string, which is right for
     * a web request and wrong for anything longer. Tests share one process, so
     * one test's answer would otherwise be served to the next; cron runs long
     * enough that a membership could change underneath it.
     *
     * @return void
     */
    public static function reset_caches() {
        self::$membercache = [];
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
     * The `email` field is the identity the service will know this person by,
     * which on a course-scoped site is their namespaced Moodle username rather
     * than an address. Their real address goes in `additional_email`, where the
     * service treats it as somewhere to write to and never as an identifier.
     * Omitted entirely when there is none, so an institution-wide site posts
     * byte for byte what it always has.
     *
     * @param string $name
     * @param string $login Identity to register under: an email, or a login.
     * @param int $role
     * @param string|null $additionalemail Delivery address, when the login is not one.
     *
     * @return bool
     */
    public function auto_registrate_member($name, $login, $role, $additionalemail = null) {
        $fields = [
                'token' => $this->token,
                'name' => $name,
                'email' => $login,
                'role' => $role,
        ];

        if (!empty($additionalemail)) {
            $fields['additional_email'] = $additionalemail;
        }

        $response = $this->transport->post($this->endpoint . '/lms/moodle/auto-registration/', $fields, [
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
     * Tell the service that a member teaches one course.
     *
     * The service stores one role per member for the whole institution, which
     * cannot describe somebody who teaches one course and studies in another.
     * This records the missing per-course fact, and the service treats it as an
     * extra way to allow a report, never as a reason to refuse one.
     *
     * Sent server to server, so nothing about the grant passes through a page
     * the viewer could edit. Grants expire at the service after 48 hours and
     * this is called afresh on every report open, so re-issuing one is the
     * normal case rather than an error.
     *
     * @param string $email Email of the teacher, hashed before it is sent.
     * @param int|string $courseid Moodle course id.
     * @param int $role Service role id; only teacher is accepted.
     *
     * @return bool Whether the service recorded the grant.
     */
    public function grant_course_role($email, $courseid, $role) {
        // A personal token has no members, so there is nobody to grant
        // anything to and no group whose reports could be scoped.
        if (!$this->is_group_token()) {
            return false;
        }

        $response = $this->transport->post($this->endpoint . '/lms/moodle/course-role/', [
                'token' => $this->token,
                'hash' => $this->user_email_to_hash($email),
                'course_id' => $courseid,
                'role' => $role,
        ], [
                'CURLOPT_RETURNTRANSFER' => true,
            // The maximum number of seconds to allow cURL functions to execute.
                'CURLOPT_TIMEOUT' => 30,
        ]);

        if (false === $response || null === $response || '' === $response) {
            return false;
        }

        $json = json_decode($response);
        if (!is_object($json) || !property_exists($json, 'success')) {
            return false;
        }

        return (bool) $json->success;
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
     * Called when plugin settings are saved, and again whenever the settings
     * page renders, so the administrator sees the state of the account as it is
     * now rather than as it was when the token was pasted in. Nowhere else: not
     * per submission, not per activity edit, not from scheduled tasks.
     *
     * The three reachable outcomes are kept apart on purpose: an unreachable
     * service must not be reported to the administrator as an invalid token.
     *
     * @return object With checked (bool), ok (bool), reachable (bool) and
     *                 group (plagiarism_pchkorg_group_info|null). When checked
     *                 is false nothing was asked and the rest mean nothing.
     */
    public function validate_token() {
        $result = new \stdClass();
        $result->checked = true;
        $result->ok = false;
        $result->reachable = true;
        $result->group = null;

        // This endpoint resolves institutional tokens only, so a personal token
        // would come back rejected and the administrator would be told a
        // working token is broken. Nothing is asked for one instead. The cost
        // is that a genuinely mistyped personal token is no longer caught here;
        // it surfaces on the first submission, as it did before any validation
        // existed.
        if (!$this->is_group_token()) {
            $result->checked = false;

            return $result;
        }

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
                $result->group = plagiarism_pchkorg_group_info::from_response($json->data->group);
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
     * @param array $files Each an array with filename, mime and content keys,
     *                      where content is a stored_file or the raw bytes.
     * @param string $text Pasted template text, or empty.
     * @param array $deleteids Template ids to remove.
     * @param string $email
     * @return bool True on success; on failure see get_last_error().
     */
    public function ignore_template_save($assignmentkey, array $files, $text, array $deleteids, $email) {
        $fields = [
            'token' => $this->token,
            'hash' => $this->user_email_to_hash($email),
            'assignment_key' => $assignmentkey,
        ];
        if ('' !== trim((string) $text)) {
            $fields['template_text'] = $text;
        }
        // The bracketed names are literal field names, not PHP arrays: curl
        // sends them as written and PHP reassembles the arrays on the far side.
        foreach (array_values($deleteids) as $index => $deleteid) {
            $fields[sprintf('delete[%d]', $index)] = (int) $deleteid;
        }
        foreach (array_values($files) as $index => $file) {
            $fields[sprintf('templates[%d]', $index)] = $this->file_field(
                $file['content'],
                $file['mime'],
                $file['filename']
            );
        }

        $response = $this->transport->post(
            $this->endpoint . '/lms/moodle/assignment/ignore-templates/save/',
            $fields,
            [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT' => 120,
                // Content-Type is deliberately absent: curl sets it, with the
                // boundary it generated for the body.
                'CURLOPT_HTTPHEADER' => [
                    'Expect:',
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
