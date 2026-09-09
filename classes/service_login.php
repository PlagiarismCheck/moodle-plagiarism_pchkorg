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
 * Who a Moodle user is to PlagiarismCheck.org.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Who a Moodle user is to PlagiarismCheck.org.
 *
 * The service knows a person by exactly one string, and that one string is the
 * identity for every call, not merely for registration: membership checks, the
 * report credential, the author hash on a submission and the ignore-template
 * calls all hash it. Registering somebody under one string and looking them up
 * by another would create an account this plugin could never find again, so
 * every producer has to agree. This class is where they agree, the way
 * {@see plagiarism_pchkorg_course_access} is where the mode question is settled.
 *
 * On an institution-wide site the identity is the email address, exactly as it
 * has always been. On a course-scoped site it is the Moodle username, namespaced
 * per site, and the address travels separately as a delivery address only.
 *
 * Why the username at all: the service's account table is globally unique on
 * that string, so two people sharing one address -- or a teacher who already has
 * a personal PlagiarismCheck.org account under their work address -- collide
 * with each other today. A username does not.
 */
class plagiarism_pchkorg_service_login {
    /**
     * Length of the site digest in the login prefix.
     *
     * Twelve hex characters, so the whole prefix is a fixed 20 and the longest
     * possible login is 120 characters against the service's 250-character
     * column. Long enough that two sites colliding is not a practical worry.
     */
    const DIGEST_LENGTH = 12;

    /**
     * Per-user cache of {@see resolve()}, keyed by Moodle user id.
     *
     * @var array
     */
    private static $resolved = [];

    /**
     * The per-site prefix every namespaced login carries.
     *
     * A bare username cannot be the identity. The service's account table is
     * unique on this string across every institution it serves, and its
     * auto-registration looks accounts up by it, so a bare "jsmith" would
     * silently attach some other institution's account to this site's group.
     * The prefix makes the string this site's own.
     *
     * A digest of the site identifier rather than the identifier itself:
     * get_site_identifier() returns random_string(32) . $_SERVER['HTTP_HOST'],
     * which is variable length and contains dots, and the cron query has to
     * inline it as a SQL literal. Twelve hex characters are fixed width,
     * SQL-safe, and stable for the life of the site.
     *
     * @return string
     */
    public static function site_prefix() {
        return 'moodle-' . substr(sha1(get_site_identifier()), 0, self::DIGEST_LENGTH) . '-';
    }

    /**
     * This site's namespaced login for a Moodle user.
     *
     * @param stdClass $user Moodle user record; needs username.
     * @return string
     */
    public static function namespaced($user) {
        return self::site_prefix() . $user->username;
    }

    /**
     * Which string identifies this user to the service, and what it knows of them.
     *
     * Course mode looks the namespaced login up first and falls back to the
     * email, because a site that turns the setting on has teachers the service
     * already knows by address. Without the fallback every one of them would be
     * registered a second time and their existing reports would stay under an
     * account nothing points at any more.
     *
     * An unreachable service is passed through untouched rather than resolved
     * to a guess: is_known false keeps every existing outage path behaving as it
     * does now, and picking the wrong identity during an outage would register
     * duplicates that outlive it.
     *
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @param stdClass $user Moodle user record; needs email and username.
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return stdClass {login: string, member: stdClass} where member is a
     *                  {@see plagiarism_pchkorg_api_provider::get_group_member_response()}
     *                  object describing the login that was chosen.
     */
    public static function resolve($apiprovider, $user, $configmodel = null) {
        $cachekey = isset($user->id) ? $user->id : null;
        if (null !== $cachekey && array_key_exists($cachekey, self::$resolved)) {
            return self::$resolved[$cachekey];
        }

        $result = self::resolve_uncached($apiprovider, $user, $configmodel);

        // An unreachable service is not an answer, so it is not remembered. A
        // later call in the same request gets a fresh chance to reach it, which
        // is how get_group_member_response() already treats the same case.
        if (null !== $cachekey && $result->member->is_known) {
            self::$resolved[$cachekey] = $result;
        }

        return $result;
    }

    /**
     * {@see resolve()} without the cache.
     *
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @param stdClass $user
     * @param plagiarism_pchkorg_config_model|null $configmodel
     * @return stdClass
     */
    private static function resolve_uncached($apiprovider, $user, $configmodel = null) {
        $email = isset($user->email) ? $user->email : '';

        if (!plagiarism_pchkorg_course_access::is_course_scoped($configmodel)) {
            return self::result($email, $apiprovider->get_group_member_response($email));
        }

        // Nothing to build a login out of. Such an account cannot be identified
        // by username at all, so it keeps the address as its identity rather
        // than becoming unregisterable.
        if (empty($user->username)) {
            return self::result($email, $apiprovider->get_group_member_response($email));
        }

        $login = self::namespaced($user);
        $bylogin = $apiprovider->get_group_member_response($login);
        if (!$bylogin->is_known || $bylogin->is_member) {
            return self::result($login, $bylogin);
        }

        // Not known by their login. They may predate the setting being turned
        // on, in which case the service still knows them by address.
        $byemail = $apiprovider->get_group_member_response($email);
        if (!$byemail->is_known || $byemail->is_member) {
            return self::result($email, $byemail);
        }

        // The service knows them under neither string, so this is somebody to
        // register: they get the login, which is the point of the setting.
        return self::result($login, $bylogin);
    }

    /**
     * Pair an identity with what the service said about it.
     *
     * @param string $login
     * @param stdClass $member
     * @return stdClass
     */
    private static function result($login, $member) {
        $result = new \stdClass();
        $result->login = $login;
        $result->member = $member;

        return $result;
    }

    /**
     * The address to send alongside a registration, or null when there is none.
     *
     * Only meaningful when the login is not itself the address: the service
     * stores this as a delivery address and never as an identifier, so sending
     * it when the login is already the email would only duplicate it.
     *
     * A value that is not an address is dropped rather than sent. Being
     * identified by username is exactly what makes a teacher with a junk
     * address registerable, so this must not turn into a reason to refuse
     * them -- they simply have nowhere to be written to.
     *
     * @param stdClass $user Moodle user record.
     * @param string $login The identity being registered under.
     * @return string|null
     */
    public static function additional_email($user, $login) {
        $email = isset($user->email) ? $user->email : '';

        if ('' === $email || $login === $email || !validate_email($email)) {
            return null;
        }

        return $email;
    }

    /**
     * Forget cached resolutions.
     *
     * The cache lives for one request, which for a web request is short enough
     * that a membership changing underneath it is not a concern. Tests and the
     * cron task, which run far longer and switch between users, reset it.
     *
     * @return void
     */
    public static function reset_cache() {
        self::$resolved = [];
    }
}
