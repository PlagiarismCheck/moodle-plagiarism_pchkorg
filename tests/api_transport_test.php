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
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Tests for request building and response parsing in the api provider.
 *
 * These never contact plagiarismcheck.org: every call goes through a fake
 * transport which records the request and replays a canned response.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_transport_test extends \basic_testcase {
    /**
     * A group token routes to the Moodle LMS endpoint and carries the token in
     * the multipart body, which is what the service authenticates on.
     */
    public function test_group_send_uses_lms_endpoint(): void {
        $transport = $this->transport([$this->send_success(4242)]);
        $provider = $this->provider('G-group-token', $transport);

        $textid = $provider->general_send_check(
            'author-hash',
            7,
            11,
            'Essay 1',
            21,
            31,
            'the text',
            'text/plain',
            'x.txt',
            [],
            'student@example.com'
        );

        $this->assertSame(4242, $textid);
        $this->assertSame(1, $transport->request_count());

        $request = $transport->request();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://service.example/lms/moodle/check-text/', $request['url']);
        $this->assertContains('token', $this->body_field_names($request['params']));
        $this->assertContains('hash', $this->body_field_names($request['params']));
    }

    /**
     * A personal token routes to the versioned REST API instead.
     */
    public function test_personal_send_uses_rest_endpoint(): void {
        $transport = $this->transport([$this->send_success(99)]);
        $provider = $this->provider('personal-token', $transport);

        $textid = $provider->general_send_check(
            'author-hash',
            7,
            11,
            'Essay 1',
            21,
            31,
            'the text',
            'text/plain',
            'x.txt'
        );

        $this->assertSame(99, $textid);
        $this->assertSame(
            'https://service.example/api/v1/text',
            $transport->request()['url']
        );
    }

    /**
     * The group auth header binds the token to the acting user, and callers
     * outside that user's session must be able to say who that is. In cron
     * $USER is the cron user, not the submitting student.
     */
    public function test_group_auth_header_uses_supplied_email(): void {
        $transport = $this->transport([$this->send_success(1)]);
        $provider = $this->provider('G-group-token', $transport);

        $provider->general_send_check(
            'author-hash',
            7,
            11,
            'Essay 1',
            21,
            31,
            'the text',
            'text/plain',
            'x.txt',
            [],
            'Student@Example.com'
        );

        $expected = 'X-API-TOKEN: G-group-token::'
            . hash('sha256', 'G-group-token' . 'student@example.com');

        $this->assertContains($expected, $transport->request_headers());
    }

    /**
     * Filters are forwarded, and null-valued filters are omitted entirely
     * rather than sent as empty fields.
     */
    public function test_null_filters_are_omitted(): void {
        $transport = $this->transport([$this->send_success(1)]);
        $provider = $this->provider('personal-token', $transport);

        $provider->general_send_check(
            'author-hash',
            7,
            11,
            'Essay 1',
            21,
            31,
            'the text',
            'text/plain',
            'x.txt',
            ['source_min_percent' => 15, 'exclude_self_plagiarism' => null]
        );

        $names = $this->body_field_names($transport->request()['params']);
        $this->assertContains('source_min_percent', $names);
        $this->assertNotContains('exclude_self_plagiarism', $names);
    }

    /**
     * An error message in the response is surfaced and suppresses the id.
     */
    public function test_send_error_is_recorded(): void {
        $transport = $this->transport([json_encode(['message' => 'Group is disabled'])]);
        $provider = $this->provider('G-group-token', $transport);

        $textid = $provider->general_send_check(
            'author-hash',
            7,
            11,
            'Essay 1',
            21,
            31,
            'the text',
            'text/plain',
            'x.txt',
            [],
            'student@example.com'
        );

        $this->assertNull($textid);
        $this->assertSame('Group is disabled', $provider->get_last_error());
    }

    /**
     * A checked text yields both scores.
     */
    public function test_check_text_parses_checked_state(): void {
        $transport = $this->transport([json_encode([
            'success' => true,
            'data' => [
                'state' => 5,
                'report' => ['id' => 555, 'percent' => '12.50'],
                'ai_report' => ['processed_percent' => '3.25'],
            ],
        ])]);
        $provider = $this->provider('personal-token', $transport);

        $result = $provider->check_text(123);

        $this->assertSame(555, $result->id);
        $this->assertSame(5, $result->state);
        $this->assertSame('12.50', $result->percent);
        $this->assertSame('3.25', $result->percent_ai);
    }

    /**
     * AI detection is optional, so a checked text may arrive with a null
     * ai_report. This must not fatal.
     */
    public function test_check_text_tolerates_missing_ai_report(): void {
        $transport = $this->transport([json_encode([
            'success' => true,
            'data' => [
                'state' => 5,
                'report' => ['id' => 556, 'percent' => '40.00'],
                'ai_report' => null,
            ],
        ])]);
        $provider = $this->provider('personal-token', $transport);

        $result = $provider->check_text(123);

        $this->assertSame('40.00', $result->percent);
        $this->assertNull($result->percent_ai);
    }

    /**
     * Texts which were never checked carry a null report. Dereferencing it
     * unguarded used to fatal on PHP 8.
     *
     * The cases are looped rather than supplied by a data provider: the
     * dataProvider annotation is deprecated in PHPUnit 11 and removed in 12,
     * while its replacement attribute needs PHP 8.0 and does not exist in the
     * PHPUnit 7 shipped with Moodle 3.9.
     */
    public function test_check_text_tolerates_null_report(): void {
        $unchecked = [
            'failed' => 4,
            'temp failed' => 6,
            'dropped' => 7,
            'erased' => 8,
        ];

        foreach ($unchecked as $label => $state) {
            $transport = $this->transport([json_encode([
                'success' => true,
                'data' => ['state' => $state, 'report' => null, 'ai_report' => null],
            ])]);
            $provider = $this->provider('personal-token', $transport);

            $result = $provider->check_text(123);

            $this->assertSame($state, $result->state, $label);
            $this->assertNull($result->id, $label);
            $this->assertNull($result->percent, $label);
            $this->assertNull($result->percent_ai, $label);
        }
    }

    /**
     * Non-final states leave the record alone so the poll tries again.
     */
    public function test_check_text_ignores_non_final_states(): void {
        $transport = $this->transport([json_encode([
            'success' => true,
            'data' => ['state' => 3, 'report' => null],
        ])]);
        $provider = $this->provider('personal-token', $transport);

        $this->assertNull($provider->check_text(123));
    }

    /**
     * Group polling uses the LMS report endpoint and passes the raw group
     * token as a query parameter.
     */
    public function test_group_check_text_uses_lms_endpoint(): void {
        $transport = $this->transport([json_encode([
            'success' => true,
            'data' => [
                'state' => 5,
                'report' => ['id' => 1, 'percent' => '1.00'],
                'ai_report' => null,
            ],
        ])]);
        $provider = $this->provider('G-group-token', $transport);

        $provider->check_text(777);

        $request = $transport->request();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://service.example/lms/check-report/777/', $request['url']);
        $this->assertSame(['token' => 'G-group-token'], $request['params']);
    }

    /**
     * Group membership is resolved over the network and cached per email, so
     * a slow or unavailable service is asked at most once per request.
     */
    public function test_group_membership_is_cached_per_email(): void {
        $transport = $this->transport([
            json_encode(['success' => true, 'is_member' => true, 'is_auto_registration_enabled' => false]),
        ]);
        $provider = $this->provider('G-group-token', $transport);

        $this->assertTrue($provider->is_group_member('someone@example.com'));
        $this->assertTrue($provider->is_group_member('someone@example.com'));
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * Personal tokens have no group, so membership resolves locally.
     */
    public function test_personal_token_needs_no_membership_call(): void {
        $transport = $this->transport();
        $provider = $this->provider('personal-token', $transport);

        $this->assertTrue($provider->is_group_member('someone@example.com'));
        $this->assertSame(0, $transport->request_count());
    }

    /**
     * A response the transport could not deliver (curl failure, timeout,
     * empty body) must not be cached as a negative answer: the whole point is
     * that a later call in the same request gets a fresh chance to reach the
     * service, rather than being stuck with a guess for the rest of the page.
     */
    public function test_unreachable_membership_response_is_unknown_and_not_cached(): void {
        // The provider's membership cache is a PHP function-static, shared by
        // every provider instance for the life of the process, not just this
        // test: the email must be unique across this whole test run.
        $email = 'unreachable-membership@example.com';
        $transport = $this->transport([
            '',
            json_encode(['success' => true, 'is_member' => true, 'is_auto_registration_enabled' => false]),
        ]);
        $provider = $this->provider('G-group-token', $transport);

        $first = $provider->get_group_member_response($email);
        $this->assertFalse($first->is_known);
        $this->assertFalse($first->is_member);
        $this->assertSame(1, $transport->request_count());

        // Not cached: this is a fresh attempt, and this time it succeeds.
        $second = $provider->get_group_member_response($email);
        $this->assertTrue($second->is_known);
        $this->assertTrue($second->is_member);
        $this->assertSame(2, $transport->request_count());

        // Now that a known answer was cached, a third call is served from it.
        $third = $provider->get_group_member_response($email);
        $this->assertTrue($third->is_member);
        $this->assertSame(2, $transport->request_count());
    }

    /**
     * A response that parses as JSON but does not carry the keys the plugin
     * relies on must be treated the same as unreachable, not as a negative
     * answer, and must trigger the PHP 8 "undefined property" warning for
     * neither key.
     */
    public function test_membership_response_missing_keys_is_unknown(): void {
        $transport = $this->transport([json_encode(['success' => false, 'message' => 'can not find group'])]);
        $provider = $this->provider('G-group-token', $transport);

        $result = $provider->get_group_member_response('missing-keys-membership@example.com');

        $this->assertFalse($result->is_known);
    }

    /**
     * A personal token resolves membership locally, without a network call,
     * and is always treated as a known answer.
     */
    public function test_personal_token_membership_response_is_known(): void {
        $transport = $this->transport();
        $provider = $this->provider('personal-token', $transport);

        $result = $provider->get_group_member_response('someone@example.com');

        $this->assertTrue($result->is_known);
        $this->assertTrue($result->is_member);
    }

    /**
     * A confirmed negative answer from the service is cached, same as a
     * confirmed positive one: only "unknown" skips the cache.
     */
    public function test_confirmed_negative_membership_response_is_cached(): void {
        $email = 'confirmed-negative-membership@example.com';
        $transport = $this->transport([
            json_encode(['success' => true, 'is_member' => false, 'is_auto_registration_enabled' => false]),
        ]);
        $provider = $this->provider('G-group-token', $transport);

        $this->assertFalse($provider->is_group_member($email));
        $this->assertFalse($provider->is_group_member($email));
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * Build a provider wired to the fake transport.
     *
     * @param string $token
     * @param \plagiarism_pchkorg_fake_transport $transport
     * @return \plagiarism_pchkorg_api_provider
     */
    private function provider($token, $transport) {
        return new \plagiarism_pchkorg_api_provider($token, 'https://service.example', $transport);
    }

    /**
     * Transport.
     *
     * @param array $responses
     * @return \plagiarism_pchkorg_fake_transport
     */
    private function transport(array $responses = []) {
        return new \plagiarism_pchkorg_fake_transport($responses);
    }

    /**
     * A successful send response carrying a text id.
     *
     * @param int $textid
     * @return string
     */
    private function send_success($textid) {
        return json_encode([
            'success' => true,
            'data' => ['text' => ['id' => $textid]],
        ]);
    }

    /**
     * Extract the form field names from a hand-built multipart body.
     *
     * @param string $body
     * @return array
     */
    private function body_field_names($body) {
        $matches = [];
        preg_match_all('/name="([^"]+)"/', $body, $matches);

        return $matches[1];
    }
}
