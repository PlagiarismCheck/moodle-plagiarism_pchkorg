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

require_once(__DIR__ . '/../classes/site_version.php');

/**
 * Tests for the versions reported to the service.
 *
 * The reported Moodle version decides which releases keep being supported, so
 * the parsing is pinned against the shapes $CFG->release has actually taken
 * across release lines rather than against the one Moodle the tests run on.
 *
 * @package    plagiarism_pchkorg
 * @category   test
 * @copyright  PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_version_test extends \advanced_testcase {
    /**
     * Every release line reports its major version, including the two-digit
     * minors from 3.10 on and the dev builds between them.
     *
     * The cases are looped rather than supplied by a data provider: the
     * dataProvider annotation is deprecated in PHPUnit 11 and removed in 12,
     * while its replacement attribute needs PHP 8.0 and does not exist in the
     * PHPUnit 7 shipped with Moodle 3.9.
     */
    public function test_major_version_is_parsed_from_the_release(): void {
        global $CFG;
        $this->resetAfterTest(true);

        $releases = [
            'current stable' => ['5.0 (Build: 20250414)', '5.0'],
            'point release' => ['4.5.2+ (Build: 20241010)', '4.5'],
            'two digit minor' => ['3.11.4+ (Build: 20220520)', '3.11'],
            'oldest supported' => ['3.9.24+ (Build: 20230123)', '3.9'],
            'dev build' => ['5.1dev (Build: 20250601)', '5.1'],
            'bare version' => ['4.0', '4.0'],
        ];

        foreach ($releases as $label => $case) {
            [$release, $expected] = $case;
            $CFG->release = $release;

            $this->assertSame($expected, \plagiarism_pchkorg_site_version::moodle_major(), $label);
        }
    }

    /**
     * A site whose release cannot be read still reports a version: the branch
     * is the same number with the dot dropped, and putting it back is
     * unambiguous because only the major digit ever leads.
     */
    public function test_major_version_falls_back_to_the_branch(): void {
        global $CFG;
        $this->resetAfterTest(true);

        $branches = [
            'current stable' => ['500', '5.0'],
            'four series' => ['405', '4.5'],
            'two digit minor' => ['311', '3.11'],
            'single digit minor' => ['39', '3.9'],
        ];

        unset($CFG->release);

        foreach ($branches as $label => $case) {
            [$branch, $expected] = $case;
            $CFG->branch = $branch;

            $this->assertSame($expected, \plagiarism_pchkorg_site_version::moodle_major(), $label);
        }
    }

    /**
     * With neither readable, nothing is reported rather than something wrong.
     * The field is optional on the wire, so the submission is checked as usual.
     */
    public function test_unknown_version_is_reported_as_nothing(): void {
        global $CFG;
        $this->resetAfterTest(true);

        unset($CFG->release);
        unset($CFG->branch);

        $this->assertNull(\plagiarism_pchkorg_site_version::moodle_major());
    }

    /**
     * A release that parses as neither shape is not guessed at.
     */
    public function test_unparseable_release_is_reported_as_nothing(): void {
        global $CFG;
        $this->resetAfterTest(true);

        $CFG->release = 'Moodle';
        unset($CFG->branch);

        $this->assertNull(\plagiarism_pchkorg_site_version::moodle_major());
    }

    /**
     * The plugin reports the release from its own version.php, which is what
     * administrators see in the plugin overview and quote in support requests.
     */
    public function test_plugin_release_matches_version_php(): void {
        global $CFG;
        $this->resetAfterTest(true);
        \plagiarism_pchkorg_site_version::reset_cache();

        $plugin = new \stdClass();
        require($CFG->dirroot . '/plagiarism/pchkorg/version.php');

        $this->assertSame($plugin->release, \plagiarism_pchkorg_site_version::plugin_release());
        $this->assertNotEmpty($plugin->release);
    }
}
