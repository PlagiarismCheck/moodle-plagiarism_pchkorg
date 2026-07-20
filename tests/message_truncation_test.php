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

require_once(__DIR__ . '/../lib.php');

/**
 * Tests for fitting an error message into plagiarism_pchkorg_files.message.
 *
 * The column is char(130). On a database in strict mode an over-length value
 * aborts the insert, which loses the submission record entirely, so a shortened
 * message is always preferable. Every write to that column in lib.php goes
 * through truncate_message().
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_truncation_test extends \basic_testcase {
    /**
     * The limit has to keep matching db/install.xml and db/upgrade.php.
     */
    public function test_limit_matches_the_column_width(): void {
        $this->assertSame(130, \plagiarism_plugin_pchkorg::MESSAGE_MAX_LENGTH);
    }

    /**
     * A message that already fits is stored exactly as received.
     */
    public function test_short_message_is_unchanged(): void {
        $message = 'We could not read any text from this document.';

        $this->assertSame($message, \plagiarism_plugin_pchkorg::truncate_message($message));
    }

    /**
     * A message of exactly the column width is not shortened.
     */
    public function test_message_of_exactly_the_limit_is_unchanged(): void {
        $message = str_repeat('a', 130);

        $result = \plagiarism_plugin_pchkorg::truncate_message($message);

        $this->assertSame($message, $result);
        $this->assertSame(130, \core_text::strlen($result));
    }

    /**
     * An over-length message is cut to the column width rather than rejected.
     */
    public function test_over_length_message_is_cut(): void {
        $result = \plagiarism_plugin_pchkorg::truncate_message(str_repeat('b', 500));

        $this->assertSame(130, \core_text::strlen($result));
        $this->assertSame(str_repeat('b', 130), $result);
    }

    /**
     * The historical worst case: a service message carrying a deployment path.
     * These are no longer sent, but stored history and older service versions
     * still produce them.
     */
    public function test_long_service_message_is_cut(): void {
        $message = 'Can not convert file to pdf convert /var/www/plagiarismcheck.org/'
            . 'public_html_20260619_145755/app/../var/tmp/prod/formatted/220d72a4-1f3b-4c8e-9a2d-7e5f1b3c8d90';

        $result = \plagiarism_plugin_pchkorg::truncate_message($message);

        $this->assertSame(130, \core_text::strlen($result));
    }

    /**
     * A locally built message naming a long user email still fits.
     *
     * 'User %s is not a member of group' plus a 100-character email, the widest
     * value Moodle's user.email column allows, lands exactly on the limit.
     */
    public function test_local_not_a_member_message_fits(): void {
        $email = str_repeat('e', 88) . '@example.com';
        $this->assertSame(100, \core_text::strlen($email));

        $message = sprintf('User %s is not a member of group', $email);
        $result = \plagiarism_plugin_pchkorg::truncate_message($message);

        $this->assertLessThanOrEqual(130, \core_text::strlen($result));
    }

    /**
     * Cutting a multibyte message must not split a character, which would store
     * invalid UTF-8 and can itself fail the insert.
     */
    public function test_multibyte_message_is_not_cut_mid_character(): void {
        // These characters are 3 bytes each and 130 is not a multiple of 3, so
        // a byte-based substr() at 130 lands inside the 44th character and
        // yields invalid UTF-8. Asserted below so the test keeps proving that.
        // preg_match returns false, not 0, when the subject is not valid UTF-8.
        $message = str_repeat('日本語文書', 40);

        $this->assertFalse(preg_match('//u', substr($message, 0, 130)));

        $result = \plagiarism_plugin_pchkorg::truncate_message($message);

        $this->assertSame(130, \core_text::strlen($result));
        $this->assertSame(1, preg_match('//u', $result), 'Result must be valid UTF-8');
    }

    /**
     * Null and empty pass through, so an absent message is not turned into ''.
     */
    public function test_null_and_empty_are_preserved(): void {
        $this->assertNull(\plagiarism_plugin_pchkorg::truncate_message(null));
        $this->assertSame('', \plagiarism_plugin_pchkorg::truncate_message(''));
    }
}
