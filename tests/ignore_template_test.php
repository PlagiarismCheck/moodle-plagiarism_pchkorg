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
require_once(__DIR__ . '/../classes/assignment_key.php');
require_once(__DIR__ . '/../classes/ignore_template_form.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Ignored assignment templates: key construction and API calls.
 *
 * No test performs a real request; every call goes through the fake transport.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_template_test extends \basic_testcase {
    /**
     * The key carries the course module id and a site segment.
     */
    public function test_assignment_key_contains_cmid_and_site_segment(): void {
        $key = \plagiarism_pchkorg_assignment_key::for_cmid(8753);

        $this->assert_matches_regular_expression_compat('/^moodle-[0-9a-f]{12}-8753$/', $key);
    }

    /**
     * Two activities on one site differ; the site segment does not.
     */
    public function test_assignment_key_differs_per_course_module(): void {
        $first = \plagiarism_pchkorg_assignment_key::for_cmid(1);
        $second = \plagiarism_pchkorg_assignment_key::for_cmid(2);

        $this->assertNotEquals($first, $second);
        $this->assertEquals(
            \plagiarism_pchkorg_assignment_key::site_hash(),
            \plagiarism_pchkorg_assignment_key::site_hash()
        );
    }

    /**
     * The same activity always produces the same key, so templates uploaded
     * today are still found tomorrow.
     */
    public function test_assignment_key_is_stable(): void {
        $this->assertEquals(
            \plagiarism_pchkorg_assignment_key::for_cmid(42),
            \plagiarism_pchkorg_assignment_key::for_cmid(42)
        );
    }

    /**
     * A valid token reports ok, and carries the group back.
     */
    public function test_validate_token_accepts_valid_token(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":true,"data":{"group":{"id":1457,"name":"group1"}}}',
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertTrue($result->ok);
        $this->assertTrue($result->reachable);
        $this->assertEquals(1457, $result->group->id);
    }

    /**
     * A rejected token is invalid but the service was reachable. The two must
     * stay apart so the administrator is not told a good token is bad.
     */
    public function test_validate_token_rejects_invalid_token(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":false,"code":"invalid_token"}',
        ]);
        // Deliberately a group token: a personal one is never sent, so it would
        // pass this test without the rejection it is meant to be about.
        $provider = new \plagiarism_pchkorg_api_provider('G-nope', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertTrue($result->checked);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->reachable);
    }

    /**
     * No response at all is an availability problem, not a bad token.
     */
    public function test_validate_token_reports_unreachable_service(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([false]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reachable);
    }

    /**
     * Garbage that is not JSON is treated as unreachable, not as a bad token.
     */
    public function test_validate_token_treats_non_json_as_unreachable(): void {
        $transport = new \plagiarism_pchkorg_fake_transport(['<html>502</html>']);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertFalse($result->ok);
        $this->assertFalse($result->reachable);
    }

    /**
     * Listing returns the templates and posts to the documented endpoint.
     */
    public function test_template_list_returns_templates(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":true,"data":{"templates":[{"id":7,"filename":"rubric.docx","size":120}]}}',
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $templates = $provider->ignore_template_list('moodle-abc-1', 'teacher@example.org');

        $this->assertCount(1, $templates);
        $this->assertEquals('rubric.docx', $templates[0]->filename);

        $request = $transport->request(0);
        $this->assertEquals(
            'https://example.org/lms/moodle/assignment/ignore-templates/',
            $request['url']
        );
        $this->assertEquals('moodle-abc-1', $request['params']['assignment_key']);
    }

    /**
     * A failed list is null, not an empty array: "no templates" and "could not
     * ask" must not look the same to the form.
     */
    public function test_template_list_returns_null_when_unavailable(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([false]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $this->assertNull($provider->ignore_template_list('moodle-abc-1', 'teacher@example.org'));
        $this->assertEquals('unreachable', $provider->get_last_error());
    }

    /**
     * The service's error code is kept so the plugin can translate it.
     */
    public function test_save_records_service_error_code(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":false,"code":"duplicate_template","message":"already attached"}',
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $saved = $provider->ignore_template_save(
            'moodle-abc-1',
            [['filename' => 'a.txt', 'mime' => 'text/plain', 'content' => 'body']],
            '',
            [],
            'teacher@example.org'
        );

        $this->assertFalse($saved);
        $this->assertEquals('duplicate_template', $provider->get_last_error());
    }

    /**
     * A save posts one multipart body carrying the files, the pasted text and
     * every id marked for deletion.
     */
    public function test_save_builds_multipart_body(): void {
        $transport = new \plagiarism_pchkorg_fake_transport(['{"success":true,"data":{"templates":[]}}']);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $saved = $provider->ignore_template_save(
            'moodle-abc-1',
            [['filename' => 'rubric.txt', 'mime' => 'text/plain', 'content' => 'template body']],
            '',
            [11, 12],
            'teacher@example.org'
        );

        $this->assertTrue($saved);

        $request = $transport->request(0);
        $this->assertEquals(
            'https://example.org/lms/moodle/assignment/ignore-templates/save/',
            $request['url']
        );
        $params = $request['params'];
        $this->assertSame('moodle-abc-1', $params['assignment_key']);
        $this->assertInstanceOf('CURLFile', $params['templates[0]']);
        $this->assertSame('rubric.txt', $params['templates[0]']->getPostFilename());
        $this->assertSame('template body', file_get_contents($params['templates[0]']->getFilename()));
        // Several deletions in one save is what the assignment form does.
        $this->assertSame(11, $params['delete[0]']);
        $this->assertSame(12, $params['delete[1]']);
    }

    /**
     * Deleting several templates in one save sends every id.
     */
    public function test_save_sends_every_deletion(): void {
        $transport = new \plagiarism_pchkorg_fake_transport(['{"success":true,"data":{"templates":[]}}']);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $provider->ignore_template_save('moodle-abc-1', [], '', [3, 4, 5, 6, 7], 'teacher@example.org');

        $params = $transport->request(0)['params'];
        foreach ([3, 4, 5, 6, 7] as $index => $templateid) {
            $this->assertSame($templateid, $params[sprintf('delete[%d]', $index)]);
        }
    }

    /**
     * The token never travels in a URL.
     */
    public function test_token_is_never_placed_in_a_url(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":true,"data":{"templates":[]}}',
            '{"success":true,"data":{"templates":[]}}',
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-secret-token', 'https://example.org', $transport);

        $provider->ignore_template_list('moodle-abc-1', 'teacher@example.org');
        $provider->ignore_template_delete('moodle-abc-1', 7, 'teacher@example.org');

        for ($index = 0; $index < $transport->request_count(); $index++) {
            $this->assertStringNotContainsString('G-secret-token', $transport->request($index)['url']);
        }
    }

    /**
     * A download returns the original bytes untouched, even when they happen to
     * look like something else.
     */
    public function test_download_returns_raw_bytes(): void {
        $transport = new \plagiarism_pchkorg_fake_transport(["PK\x03\x04 binary payload"]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $content = $provider->ignore_template_download('moodle-abc-1', 7, 'teacher@example.org');

        $this->assertEquals("PK\x03\x04 binary payload", $content);
    }

    /**
     * A JSON error body during download is an error, not file content.
     */
    public function test_download_detects_error_response(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            '{"success":false,"code":"template_not_found"}',
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $this->assertNull($provider->ignore_template_download('moodle-abc-1', 7, 'teacher@example.org'));
        $this->assertEquals('template_not_found', $provider->get_last_error());
    }

    /**
     * A template that is genuinely a JSON file must still download. Only a
     * body that parses AND reports failure counts as an error.
     */
    public function test_download_passes_through_json_shaped_file(): void {
        $transport = new \plagiarism_pchkorg_fake_transport(['{"success":true,"note":"a real json template"}']);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $content = $provider->ignore_template_download('moodle-abc-1', 7, 'teacher@example.org');

        $this->assertEquals('{"success":true,"note":"a real json template"}', $content);
    }

    /**
     * Unknown service codes still produce a translated message rather than a
     * missing-string error.
     */
    public function test_error_message_falls_back_for_unknown_code(): void {
        $message = \plagiarism_pchkorg_ignore_template_form::error_message('something_new_from_the_service');

        $this->assertNotEmpty($message);
        $this->assertStringNotContainsString('[[', $message);
    }

    /**
     * Known codes map to their own message.
     */
    public function test_error_message_uses_code_specific_string(): void {
        $duplicate = \plagiarism_pchkorg_ignore_template_form::error_message('duplicate_template');
        $toolarge = \plagiarism_pchkorg_ignore_template_form::error_message('file_too_large');

        $this->assertNotEquals($duplicate, $toolarge);
        $this->assertStringNotContainsString('[[', $duplicate);
        $this->assertStringNotContainsString('[[', $toolarge);
    }

    /**
     * Regex assertion that works across the supported PHPUnit range.
     *
     * assertRegExp was removed in PHPUnit 10 and
     * assertMatchesRegularExpression only exists from PHPUnit 9.
     *
     * @param string $pattern
     * @param string $string
     * @return void
     */
    private function assert_matches_regular_expression_compat($pattern, $string) {
        $this->assertSame(1, preg_match($pattern, $string), "Expected {$string} to match {$pattern}");
    }
}
