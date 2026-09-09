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
 * The institution a configured token belongs to.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * What the service says about the group behind the site's API token.
 *
 * The token validation endpoint answers with the service's own group
 * representation, which carries a good deal the plugin has no use for: contact
 * details, the address of whoever created the group, sale status. This reads
 * the handful of fields the settings page shows and drops the rest, so no part
 * of the plugin ends up rendering whatever the service happens to send.
 *
 * Everything here is a snapshot of one response. It is never stored: the
 * settings page asks again each time it renders, because a balance that ran
 * out or a subscription that lapsed is exactly what the administrator is
 * looking at this panel to find out.
 */
class plagiarism_pchkorg_group_info {
    /**
     * The group is usable.
     */
    const STATUS_ENABLED = 1;

    /**
     * The group has been turned off at the service.
     */
    const STATUS_DISABLED = 2;

    /**
     * Paid for by the page; a balance is drawn down as checks are made.
     */
    const BALANCE_TYPE_PER_PAGES = 1;

    /**
     * Paid for by the seat.
     */
    const BALANCE_TYPE_PER_USER = 2;

    /**
     * Paid for by subscription, which is also a number of seats.
     */
    const BALANCE_TYPE_SUBSCRIPTION = 3;

    /**
     * Service id of the group.
     *
     * @var int
     */
    public $id;

    /**
     * Name of the group at the service.
     *
     * @var string
     */
    public $name;

    /**
     * One of the STATUS_* constants.
     *
     * @var int
     */
    public $status;

    /**
     * Unix time the group expires, or null when it does not expire.
     *
     * @var int|null
     */
    public $expiredat;

    /**
     * One of the BALANCE_TYPE_* constants.
     *
     * @var int
     */
    public $balancetype;

    /**
     * Members the group currently has.
     *
     * @var int
     */
    public $curmembers;

    /**
     * Members the group may have, or 0 when it is not capped.
     *
     * @var int
     */
    public $maxmembers;

    /**
     * Pages bought and not yet spent.
     *
     * @var int
     */
    public $balance;

    /**
     * Pages granted as a bonus and not yet spent.
     *
     * @var int
     */
    public $bonus;

    /**
     * Read one group out of a validation response.
     *
     * Defensive about every field: this is another system's payload, an older
     * service release may not send all of it, and a settings page that fatals
     * is a settings page the administrator cannot use to fix the token.
     *
     * @param object|null $group The group object of the response body.
     * @return plagiarism_pchkorg_group_info|null Null when there is no usable group.
     */
    public static function from_response($group) {
        if (!is_object($group) || !isset($group->id)) {
            return null;
        }

        $info = new self();
        $info->id = (int) $group->id;
        $info->name = isset($group->name) ? (string) $group->name : '';
        $info->status = isset($group->status) ? (int) $group->status : self::STATUS_ENABLED;
        // Absent and null both mean "does not expire". Zero is the service
        // saying the same thing in an older shape, and is not a 1970 expiry.
        $info->expiredat = empty($group->expired_at)
            ? null
            : self::seconds_from_service_time($group->expired_at);
        $info->balancetype = isset($group->balance_type)
            ? (int) $group->balance_type
            : self::BALANCE_TYPE_PER_PAGES;
        $info->curmembers = isset($group->cur_members) ? (int) $group->cur_members : 0;
        $info->maxmembers = isset($group->max_members) ? (int) $group->max_members : 0;
        $info->balance = isset($group->group_balance->balance) ? (int) $group->group_balance->balance : 0;
        $info->bonus = isset($group->group_balance->bonus) ? (int) $group->group_balance->bonus : 0;

        return $info;
    }

    /**
     * A service timestamp as Unix seconds.
     *
     * The service formats its times through a helper that appends three zeroes
     * and returns a string, so what arrives is milliseconds -- "1757376000000"
     * for a date in 2025. Taken at face value that is a date fifty thousand
     * years out, which would make every expiry look comfortably in the future.
     *
     * Values that are already in seconds are left alone, so this keeps working
     * whichever shape a given service release sends.
     *
     * @param string|int $moment
     * @return int Unix seconds.
     */
    private static function seconds_from_service_time($moment) {
        // Counted as digits rather than compared as a number, because a
        // millisecond timestamp does not fit in an integer on a 32-bit build
        // and the comparison would be made on whatever the overflow produced.
        // Ten digits covers Unix seconds until 2286; milliseconds have had
        // thirteen since 2001.
        $digits = preg_replace('/\D/', '', (string) $moment);
        if (strlen($digits) > 11) {
            return (int) substr($digits, 0, strlen($digits) - 3);
        }

        return (int) $digits;
    }

    /**
     * Whether the group is switched on at the service.
     *
     * @return bool
     */
    public function is_enabled() {
        return self::STATUS_DISABLED !== $this->status;
    }

    /**
     * Whether the group's expiry has already passed.
     *
     * @return bool False when it does not expire at all.
     */
    public function is_expired() {
        return null !== $this->expiredat && $this->expiredat < time();
    }

    /**
     * Whether this group pays by the seat rather than by the page.
     *
     * Per-user and subscription are both counts of people, and are shown the
     * same way. Per-pages is the only plan measured in pages.
     *
     * @return bool
     */
    public function is_seat_based() {
        return in_array(
            $this->balancetype,
            [self::BALANCE_TYPE_PER_USER, self::BALANCE_TYPE_SUBSCRIPTION],
            true
        );
    }

    /**
     * Whether the number of seats is capped.
     *
     * An uncapped group reports no maximum, and showing "3 of 0" would read as
     * a group that is over its limit rather than one that has none.
     *
     * @return bool
     */
    public function has_member_cap() {
        return $this->maxmembers > 0;
    }

    /**
     * Pages left to spend.
     *
     * Bonus pages are spent exactly as bought ones are, so the useful figure is
     * the two together -- the same sum the service itself uses to decide
     * whether a check can be afforded.
     *
     * @return int
     */
    public function pages() {
        return $this->balance + $this->bonus;
    }

    /**
     * The panel's rows, in the order they are shown.
     *
     * Presentation only. What each row means is decided by the accessors above,
     * which is where the behaviour is tested.
     *
     * @return array Each an object with label, value and warning.
     */
    public function to_display_rows() {
        $rows = [
            $this->row('pchkorg_group_name', $this->name),
            $this->row('pchkorg_group_id', $this->id),
            $this->row(
                'pchkorg_group_status',
                get_string(
                    $this->is_enabled() ? 'pchkorg_group_status_enabled' : 'pchkorg_group_status_disabled',
                    'plagiarism_pchkorg'
                ),
                !$this->is_enabled()
            ),
        ];

        if ($this->is_seat_based()) {
            $rows[] = $this->row(
                'pchkorg_group_members',
                $this->has_member_cap()
                    ? get_string('pchkorg_group_members_of', 'plagiarism_pchkorg', (object) [
                        'current' => $this->curmembers,
                        'max' => $this->maxmembers,
                    ])
                    : $this->curmembers
            );
        } else {
            $rows[] = $this->row('pchkorg_group_pages', $this->pages());
        }

        // A group that never expires has nothing to say here, and an empty
        // "Expires: -" row would only invite the question.
        if (null !== $this->expiredat) {
            $rows[] = $this->row(
                $this->is_expired() ? 'pchkorg_group_expired' : 'pchkorg_group_expires',
                userdate($this->expiredat, get_string('strftimedate', 'langconfig')),
                $this->is_expired()
            );
        }

        return $rows;
    }

    /**
     * One row of the panel.
     *
     * @param string $label String key of the row's label.
     * @param mixed $value Already formatted for display.
     * @param bool $warning Whether the value is something to act on.
     * @return object
     */
    private function row($label, $value, $warning = false) {
        return (object) [
            'label' => $label,
            'value' => (string) $value,
            'warning' => $warning,
        ];
    }
}
