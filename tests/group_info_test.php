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
require_once(__DIR__ . '/../classes/group_info.php');
require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * The account panel of the site settings page.
 *
 * No test performs a real request; every call goes through the fake transport.
 *
 * @package   plagiarism_pchkorg
 * @category  test
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_info_test extends \basic_testcase {
    /**
     * A whole group, as the service sends one.
     *
     * @param array $overrides Fields to replace.
     * @return object
     */
    private function response_group(array $overrides = []) {
        return (object) array_merge([
            'id' => 1457,
            'name' => 'Acme University',
            'status' => 1,
            'expired_at' => null,
            'balance_type' => 3,
            'cur_members' => 12,
            'max_members' => 50,
            'group_balance' => (object) [
                'balance' => 400,
                'bonus' => 25,
            ],
        ], $overrides);
    }

    /**
     * A moment in the shape the service sends: milliseconds, as a string.
     *
     * @param int $seconds Unix seconds.
     * @return string
     */
    private function service_time($seconds) {
        return sprintf('%s000', $seconds);
    }

    /**
     * Labels of the rows the panel would show, in order.
     *
     * @param \plagiarism_pchkorg_group_info $info
     * @return array
     */
    private function row_labels(\plagiarism_pchkorg_group_info $info) {
        $labels = [];
        foreach ($info->to_display_rows() as $row) {
            $labels[] = $row->label;
        }

        return $labels;
    }

    /**
     * One row of the panel, by label.
     *
     * @param \plagiarism_pchkorg_group_info $info
     * @param string $label
     * @return object|null
     */
    private function row(\plagiarism_pchkorg_group_info $info, $label) {
        foreach ($info->to_display_rows() as $row) {
            if ($label === $row->label) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A personal token is never sent to an endpoint that only knows groups, so
     * the administrator is not told a working token is invalid.
     */
    public function test_personal_token_is_not_validated(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([]);
        $provider = new \plagiarism_pchkorg_api_provider('personal-token', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertFalse($result->checked);
        $this->assertNull($result->group);
        $this->assertEquals(0, $transport->request_count());
    }

    /**
     * The fields the panel shows are read off the response.
     */
    public function test_group_is_read_from_validation_response(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([
            json_encode(['success' => true, 'data' => ['group' => $this->response_group()]]),
        ]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $group = $provider->validate_token()->group;

        $this->assertInstanceOf(\plagiarism_pchkorg_group_info::class, $group);
        $this->assertSame(1457, $group->id);
        $this->assertSame('Acme University', $group->name);
        $this->assertTrue($group->is_enabled());
        $this->assertNull($group->expiredat);
    }

    /**
     * A subscription is a number of seats.
     */
    public function test_subscription_group_is_seat_based(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'balance_type' => \plagiarism_pchkorg_group_info::BALANCE_TYPE_SUBSCRIPTION,
        ]));

        $this->assertTrue($group->is_seat_based());
        $this->assertTrue($group->has_member_cap());
        $this->assertContains('pchkorg_group_members', $this->row_labels($group));
        $this->assertNotContains('pchkorg_group_pages', $this->row_labels($group));
    }

    /**
     * Per-user is a number of seats too, and is shown the same way.
     */
    public function test_per_user_group_is_seat_based(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'balance_type' => \plagiarism_pchkorg_group_info::BALANCE_TYPE_PER_USER,
        ]));

        $this->assertTrue($group->is_seat_based());
        $this->assertContains('pchkorg_group_members', $this->row_labels($group));
    }

    /**
     * Bonus pages are spent exactly as bought ones are, so the figure the
     * administrator needs is the two together.
     */
    public function test_per_pages_group_shows_pages_including_bonus(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'balance_type' => \plagiarism_pchkorg_group_info::BALANCE_TYPE_PER_PAGES,
        ]));

        $this->assertFalse($group->is_seat_based());
        $this->assertSame(425, $group->pages());
        $this->assertSame('425', $this->row($group, 'pchkorg_group_pages')->value);
        $this->assertNotContains('pchkorg_group_members', $this->row_labels($group));
    }

    /**
     * An uncapped group has a member count but no maximum, and must not be
     * shown as "12 of 0", which reads as a group over its limit.
     */
    public function test_uncapped_group_shows_count_without_maximum(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'max_members' => 0,
        ]));

        $this->assertFalse($group->has_member_cap());
        $this->assertSame('12', $this->row($group, 'pchkorg_group_members')->value);
    }

    /**
     * A group that never expires has no expiry row at all.
     */
    public function test_group_without_expiry_has_no_expiry_row(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'expired_at' => null,
        ]));

        $this->assertFalse($group->is_expired());
        $this->assertNotContains('pchkorg_group_expires', $this->row_labels($group));
        $this->assertNotContains('pchkorg_group_expired', $this->row_labels($group));
    }

    /**
     * An expiry still ahead is shown plainly, not as a problem.
     */
    public function test_future_expiry_is_not_a_warning(): void {
        $expiry = time() + DAYSECS;
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'expired_at' => $this->service_time($expiry),
        ]));

        $this->assertSame($expiry, $group->expiredat);
        $this->assertFalse($group->is_expired());
        $this->assertFalse($this->row($group, 'pchkorg_group_expires')->warning);
    }

    /**
     * An expiry already passed is the reason checks stopped working, so it is
     * flagged rather than shown as an ordinary date.
     */
    public function test_past_expiry_is_flagged(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'expired_at' => $this->service_time(time() - DAYSECS),
        ]));

        $this->assertTrue($group->is_expired());
        $this->assertTrue($this->row($group, 'pchkorg_group_expired')->warning);
    }

    /**
     * The service sends milliseconds, as a string. Read as seconds, an expiry
     * thirty days out would land in the year 55000 and never look overdue.
     */
    public function test_expiry_arrives_in_milliseconds(): void {
        $expiry = 1757376000;
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'expired_at' => $this->service_time($expiry),
        ]));

        $this->assertSame($expiry, $group->expiredat);
    }

    /**
     * A service release that sends plain seconds is read as seconds, so the
     * conversion above cannot turn one into a 1970 date.
     */
    public function test_expiry_in_seconds_is_left_alone(): void {
        $expiry = 1757376000;
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'expired_at' => $expiry,
        ]));

        $this->assertSame($expiry, $group->expiredat);
    }

    /**
     * A group switched off at the service is flagged for the same reason.
     */
    public function test_disabled_group_is_flagged(): void {
        $group = \plagiarism_pchkorg_group_info::from_response($this->response_group([
            'status' => \plagiarism_pchkorg_group_info::STATUS_DISABLED,
        ]));

        $this->assertFalse($group->is_enabled());
        $this->assertTrue($this->row($group, 'pchkorg_group_status')->warning);
    }

    /**
     * An older service release need not send every field. A settings page that
     * fatals is a settings page the administrator cannot fix the token with.
     */
    public function test_sparse_response_is_tolerated(): void {
        $group = \plagiarism_pchkorg_group_info::from_response((object) [
            'id' => 9,
            'name' => 'Minimal',
        ]);

        $this->assertSame(9, $group->id);
        $this->assertTrue($group->is_enabled());
        $this->assertFalse($group->is_expired());
        $this->assertSame(0, $group->pages());
        $this->assertNotEmpty($group->to_display_rows());
    }

    /**
     * Nothing usable in the response means no panel, not a half empty one.
     */
    public function test_response_without_a_group_yields_nothing(): void {
        $this->assertNull(\plagiarism_pchkorg_group_info::from_response(null));
        $this->assertNull(\plagiarism_pchkorg_group_info::from_response((object) ['name' => 'No id']));
    }

    /**
     * An unreachable service leaves the panel with nothing to show, and must
     * not be mistaken for a group that came back empty.
     */
    public function test_unreachable_service_yields_no_group(): void {
        $transport = new \plagiarism_pchkorg_fake_transport([false]);
        $provider = new \plagiarism_pchkorg_api_provider('G-token', 'https://example.org', $transport);

        $result = $provider->validate_token();

        $this->assertTrue($result->checked);
        $this->assertFalse($result->reachable);
        $this->assertNull($result->group);
    }
}
