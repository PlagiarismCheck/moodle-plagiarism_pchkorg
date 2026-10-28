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
 * Test double for the HTTP transport.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../classes/transport.php');

/**
 * Transport which records requests and replays canned responses.
 *
 * Written by hand rather than built with createMock() because the supported
 * Moodle range spans PHPUnit 7 to 11, whose mocking APIs are not compatible.
 */
class plagiarism_pchkorg_fake_transport implements plagiarism_pchkorg_transport {
    /** @var array Queued responses, returned in order. */
    private $responses = [];

    /** @var callable|null Answers by request instead of by order, when set. */
    private $responder = null;

    /** @var int Status of the most recent response. */
    private $lasthttpstatus = 0;

    /** @var array Every request made, in order. */
    private $requests = [];

    /**
     * Construct.
     *
     * A response is either a body string, answered with status 200, or
     * ['status' => int, 'body' => string]. Once the queue runs dry every call
     * answers with an empty body and status 0, as an unreachable service would.
     *
     * @param array $responses Responses to return, one per call.
     * @param callable|null $responder fn(string $method, string $url, $params): response,
     *                                 used instead of the queue when given.
     */
    public function __construct(array $responses = [], $responder = null) {
        $this->responses = $responses;
        $this->responder = $responder;
    }

    /**
     * Record a POST and return the next canned response.
     *
     * @param string $url
     * @param string|array $params
     * @param array $options
     * @return string
     */
    public function post($url, $params = '', $options = []) {
        return $this->record('POST', $url, $params, $options);
    }

    /**
     * Record a GET and return the next canned response.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return string
     */
    public function get($url, $params = [], $options = []) {
        return $this->record('GET', $url, $params, $options);
    }

    /**
     * Record.
     *
     * @param string $method
     * @param string $url
     * @param string|array $params
     * @param array $options
     * @return string
     */
    private function record($method, $url, $params, $options) {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'params' => $params,
            'options' => $options,
        ];

        if (null !== $this->responder) {
            $response = call_user_func($this->responder, $method, $url, $params);
        } else if (empty($this->responses)) {
            $response = ['status' => 0, 'body' => ''];
        } else {
            $response = array_shift($this->responses);
        }

        if (is_array($response)) {
            $this->lasthttpstatus = $response['status'];
            return $response['body'];
        }

        $this->lasthttpstatus = 200;
        return $response;
    }

    /**
     * Status of the most recent response.
     *
     * @return int
     */
    public function last_http_status() {
        return $this->lasthttpstatus;
    }

    /**
     * Request count.
     *
     * @return int Number of requests made.
     */
    public function request_count() {
        return count($this->requests);
    }

    /**
     * Request.
     *
     * @param int $index
     * @return array|null The recorded request, or null if it was never made.
     */
    public function request($index = 0) {
        if (!array_key_exists($index, $this->requests)) {
            return null;
        }

        return $this->requests[$index];
    }

    /**
     * Headers sent with a request, as a flat list of "Name: value" strings.
     *
     * @param int $index
     * @return array
     */
    public function request_headers($index = 0) {
        $request = $this->request($index);
        if (null === $request || !isset($request['options']['CURLOPT_HTTPHEADER'])) {
            return [];
        }

        return $request['options']['CURLOPT_HTTPHEADER'];
    }
}
