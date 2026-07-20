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

namespace plagiarism_pchkorg;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/plagiarism_pchkorg_api_provider.php');

/**
 * Characterization tests for deterministic API provider behavior.
 *
 * These tests must not perform HTTP requests.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_provider_test extends \basic_testcase {
    /**
     * Group tokens are identified by the exact, case-sensitive G- prefix.
     */
    public function test_group_token_detection(): void {
        $this->assertTrue($this->provider('G-institution-token')->is_group_token());
        $this->assertFalse($this->provider('institution-token')->is_group_token());
        $this->assertFalse($this->provider('g-institution-token')->is_group_token());
        $this->assertFalse($this->provider(' G-institution-token')->is_group_token());
    }

    /**
     * Email hashes retain the established trimming and normalization behavior.
     */
    public function test_user_email_hash_normalization(): void {
        $provider = $this->provider(' test-token ');

        $this->assertSame(
            '8f5b33cffaba3985edc98d46d3c2778cba884b90281295b616e194a0db96b8c3',
            $provider->user_email_to_hash(' Student@Example.COM ')
        );
    }

    /**
     * The provider accepts exactly the MIME types supported in production.
     */
    public function test_supported_mime_types(): void {
        $provider = $this->provider('token');
        $supportedmimes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'application/pdf',
        ];

        foreach ($supportedmimes as $mime) {
            $this->assertTrue($provider->is_supported_mime($mime), $mime);
        }

        $this->assertFalse($provider->is_supported_mime('application/zip'));
        $this->assertFalse($provider->is_supported_mime('text/html'));
        $this->assertFalse($provider->is_supported_mime(''));
    }

    /**
     * The maximum upload size remains 20 MiB.
     */
    public function test_maximum_file_size(): void {
        $this->assertSame(20 * 1048576, $this->provider('token')->get_max_filesize());
    }

    /**
     * Report actions preserve the configured endpoint and established path.
     */
    public function test_report_action(): void {
        $provider = $this->provider('token', 'https://service.example');

        $this->assertSame(
            'https://service.example/lms/public-report/12345/',
            $provider->get_report_action(12345)
        );
    }

    /**
     * Last-error storage remains local and deterministic.
     */
    public function test_last_error_storage(): void {
        $provider = $this->provider('token');

        $this->assertNull($provider->get_last_error());
        $provider->set_last_error('Service unavailable');
        $this->assertSame('Service unavailable', $provider->get_last_error());
        $provider->set_last_error(null);
        $this->assertNull($provider->get_last_error());
    }

    /**
     * Create an API provider without making a network request.
     *
     * @param string $token API token.
     * @param string $endpoint Service endpoint.
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider(
        string $token,
        string $endpoint = 'https://plagiarismcheck.org'
    ): \plagiarism_pchkorg_api_provider {
        return new \plagiarism_pchkorg_api_provider($token, $endpoint);
    }
}
