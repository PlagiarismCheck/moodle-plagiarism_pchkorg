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
 * Contract for performing HTTP requests to the service.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP transport used by the api provider.
 *
 * Exists so that tests can exercise request building and response parsing
 * without reaching plagiarismcheck.org. Production code always uses
 * plagiarism_pchkorg_curl_transport, which is Moodle's own curl class.
 *
 * The signatures mirror \curl deliberately, so the default implementation can
 * stay a thin pass-through.
 */
interface plagiarism_pchkorg_transport {
    /**
     * Perform an HTTP POST.
     *
     * @param string $url
     * @param string|array $params Raw body, or fields to encode.
     * @param array $options curl options.
     * @return string|bool Response body, or false on failure.
     */
    public function post($url, $params = '', $options = []);

    /**
     * Perform an HTTP GET.
     *
     * @param string $url
     * @param array $params Query string fields.
     * @param array $options curl options.
     * @return string|bool Response body, or false on failure.
     */
    public function get($url, $params = [], $options = []);
}
