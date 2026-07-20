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
 * Default HTTP transport, backed by Moodle curl.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/transport.php');

/**
 * Default transport: Moodle's curl wrapper.
 */
class plagiarism_pchkorg_curl_transport implements plagiarism_pchkorg_transport {
    /**
     * Perform an HTTP POST.
     *
     * @param string $url
     * @param string|array $params
     * @param array $options
     * @return string|bool
     */
    public function post($url, $params = '', $options = []) {
        $curl = new curl();

        return $curl->post($url, $params, $options);
    }

    /**
     * Perform an HTTP GET.
     *
     * @param string $url
     * @param array $params
     * @param array $options
     * @return string|bool
     */
    public function get($url, $params = [], $options = []) {
        $curl = new curl();

        return $curl->get($url, $params, $options);
    }
}
