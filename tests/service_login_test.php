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

global $CFG;
require_once($CFG->dirroot . '/plagiarism/pchkorg/lib.php');
require_once($CFG->dirroot . '/plagiarism/pchkorg/tests/fixtures/fake_transport.php');

/**
 * Which string identifies a Moodle user to the service.
 *
 * The service knows a person by exactly one string, and it is the identity for
 * every call rather than only for registration, so the risk this file guards
 * against is a split: registering somebody under one string and looking them up
 * by another leaves an account nothing can ever find again.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_login_test extends \advanced_testcase {
    /** @var string A group token; a personal one has no members to resolve. */
    const TOKEN = 'G-group-token';

    protected function setUp(): void {
        parent::setUp();
        \plagiarism_pchkorg_config_model::reset_caches();
        \plagiarism_pchkorg_service_login::reset_cache();
        \plagiarism_pchkorg_api_provider::reset_caches();
    }

    protected function tearDown(): void {
        \plagiarism_pchkorg_service_login::reset_cache();
        \plagiarism_pchkorg_api_provider::reset_caches();
        parent::tearDown();
    }

    /**
     * A member response body.
     *
     * @param bool $ismember
     * @param bool $autoregistration
     * @return string
     */
    private function member_json($ismember, $autoregistration = false) {
        return json_encode([
            'is_member' => $ismember,
            'is_auto_registration_enabled' => $autoregistration,
        ]);
    }

    /**
     * Build a provider over a fake transport.
     *
     * @param array $responses
     * @return array [provider, transport]
     */
    private function provider(array $responses) {
        $transport = new \plagiarism_pchkorg_fake_transport($responses);
        $provider = new \plagiarism_pchkorg_api_provider(self::TOKEN, 'https://plagiarismcheck.org', $transport);

        return [$provider, $transport];
    }

    /**
     * Turn course scoping on or off.
     *
     * @param bool $on
     * @return \plagiarism_pchkorg_config_model
     */
    private function configmodel($on) {
        $configmodel = new \plagiarism_pchkorg_config_model();
        $configmodel->set_system_config('pchkorg_course_access', $on ? '1' : '0');

        return $configmodel;
    }

    /**
     * A user record with a distinct email and username.
     *
     * @return \stdClass
     */
    private function user() {
        return $this->getDataGenerator()->create_user([
            'username' => 'jsmith',
            'email' => 'j.smith@example.edu',
        ]);
    }

    /**
     * An institution-wide site keeps the email as the identity, byte for byte.
     */
    public function test_institution_mode_uses_the_email(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider([$this->member_json(true)]);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(false));

        $this->assertSame('j.smith@example.edu', $result->login);
        $this->assertTrue($result->member->is_member);

        // One lookup, and it asked about the address.
        $this->assertSame(1, $transport->request_count());
        $request = $transport->request(0);
        $this->assertSame(
            $provider->user_email_to_hash('j.smith@example.edu'),
            $request['params']['hash']
        );
    }

    /**
     * The prefix is stable, site-specific, and shaped for a SQL literal.
     */
    public function test_site_prefix_is_a_fixed_width_digest(): void {
        $this->resetAfterTest(true);

        $prefix = \plagiarism_pchkorg_service_login::site_prefix();

        // Asserted with preg_match because assertMatchesRegularExpression needs
        // PHPUnit 9, and Moodle 3.9 ships 7.
        $this->assertSame(
            1,
            preg_match('/^moodle-[0-9a-f]{12}-$/', $prefix),
            "unexpected shape: {$prefix}"
        );
        $this->assertSame($prefix, \plagiarism_pchkorg_service_login::site_prefix());
        $this->assertSame(
            'moodle-' . substr(sha1(get_site_identifier()), 0, 12) . '-',
            $prefix
        );
    }

    /**
     * A bare username is never the identity.
     *
     * The service's account table is unique on this string across every
     * institution it serves, so an unprefixed "jsmith" would attach somebody
     * else's account to this site's group.
     */
    public function test_namespaced_login_carries_the_site_prefix(): void {
        $this->resetAfterTest(true);
        $user = $this->user();

        $login = \plagiarism_pchkorg_service_login::namespaced($user);

        $this->assertSame(\plagiarism_pchkorg_service_login::site_prefix() . 'jsmith', $login);
        $this->assertStringNotContainsString('@', $login);
    }

    /**
     * Course mode identifies a known member by their namespaced username.
     */
    public function test_course_mode_uses_the_namespaced_login(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider([$this->member_json(true)]);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertSame(\plagiarism_pchkorg_service_login::namespaced($user), $result->login);
        $this->assertTrue($result->member->is_member);
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * A teacher the service already knows by address keeps that identity.
     *
     * Without this fallback, turning the setting on would register every
     * existing teacher a second time and orphan their existing reports.
     */
    public function test_course_mode_falls_back_to_a_known_email(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider([
            $this->member_json(false),
            $this->member_json(true),
        ]);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertSame('j.smith@example.edu', $result->login);
        $this->assertTrue($result->member->is_member);

        // Login first, then the address.
        $this->assertSame(2, $transport->request_count());
        $this->assertSame(
            $provider->user_email_to_hash(\plagiarism_pchkorg_service_login::namespaced($user)),
            $transport->request(0)['params']['hash']
        );
        $this->assertSame(
            $provider->user_email_to_hash('j.smith@example.edu'),
            $transport->request(1)['params']['hash']
        );
    }

    /**
     * Somebody the service knows under neither string is new, so gets a login.
     *
     * This is what makes the setting do anything at all: a person nobody has
     * registered yet is the one who ends up named by their username.
     */
    public function test_course_mode_registers_an_unknown_person_under_the_login(): void {
        $this->resetAfterTest(true);
        [$provider] = $this->provider([
            $this->member_json(false),
            $this->member_json(false),
        ]);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertSame(\plagiarism_pchkorg_service_login::namespaced($user), $result->login);
        $this->assertFalse($result->member->is_member);
    }

    /**
     * An account with no username keeps the address rather than becoming
     * impossible to identify.
     */
    public function test_course_mode_falls_back_when_there_is_no_username(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider([$this->member_json(true)]);
        $user = new \stdClass();
        $user->id = -1;
        $user->email = 'j.smith@example.edu';
        $user->username = '';

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertSame('j.smith@example.edu', $result->login);
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * An unreachable service is passed through, never resolved to a guess.
     *
     * Every existing outage path keys off is_known, and picking an identity
     * during an outage would register duplicates that outlive it.
     */
    public function test_an_outage_is_propagated_rather_than_guessed(): void {
        $this->resetAfterTest(true);
        [$provider] = $this->provider(['']);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertFalse($result->member->is_known);
        $this->assertFalse($result->member->is_member);
        $this->assertSame(\plagiarism_pchkorg_service_login::namespaced($user), $result->login);
    }

    /**
     * An outage on the login lookup does not silently fall through to the email.
     *
     * "Not a member" and "could not ask" are different answers, and only the
     * first is a reason to try the other identity.
     */
    public function test_an_outage_does_not_trigger_the_email_fallback(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider(['', $this->member_json(true)]);
        $user = $this->user();

        $result = \plagiarism_pchkorg_service_login::resolve($provider, $user, $this->configmodel(true));

        $this->assertFalse($result->member->is_known);
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * An outage is not cached, so a later call gets a fresh chance.
     */
    public function test_an_outage_is_not_remembered(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider(['', $this->member_json(true)]);
        $user = $this->user();
        $configmodel = $this->configmodel(true);

        $first = \plagiarism_pchkorg_service_login::resolve($provider, $user, $configmodel);
        $this->assertFalse($first->member->is_known);

        $second = \plagiarism_pchkorg_service_login::resolve($provider, $user, $configmodel);
        $this->assertTrue($second->member->is_member);
        $this->assertSame(2, $transport->request_count());
    }

    /**
     * A resolved answer is reused rather than asked again.
     */
    public function test_a_resolution_is_cached_per_user(): void {
        $this->resetAfterTest(true);
        [$provider, $transport] = $this->provider([$this->member_json(true)]);
        $user = $this->user();
        $configmodel = $this->configmodel(true);

        $first = \plagiarism_pchkorg_service_login::resolve($provider, $user, $configmodel);
        $second = \plagiarism_pchkorg_service_login::resolve($provider, $user, $configmodel);

        $this->assertSame($first->login, $second->login);
        $this->assertSame(1, $transport->request_count());
    }

    /**
     * The address travels beside a login, and never in place of one.
     */
    public function test_additional_email_accompanies_a_login_only(): void {
        $this->resetAfterTest(true);
        $user = $this->user();
        $login = \plagiarism_pchkorg_service_login::namespaced($user);

        $this->assertSame(
            'j.smith@example.edu',
            \plagiarism_pchkorg_service_login::additional_email($user, $login)
        );

        // The identity is already the address; sending it twice says nothing.
        $this->assertNull(
            \plagiarism_pchkorg_service_login::additional_email($user, 'j.smith@example.edu')
        );
    }
}
