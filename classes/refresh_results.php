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
 * Re-queues finished checks so their scores are fetched from the service again.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/state.php');
require_once(__DIR__ . '/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/permissions/capability.class.php');

use plagiarism_pchkorg\classes\permissions\capability;

/**
 * Moves finished checks back into the poll queue.
 *
 * PlagiarismCheck.org can revise a report after this plugin has already stored
 * its scores: a source is added to the index, or the AI model is re-run. Moodle
 * has no way to hear about that, because the poll stops asking about a text as
 * soon as it resolves to LOCAL_CHECKED.
 *
 * Putting a record back to LOCAL_SENT is enough to make it ask again. The
 * update_reports task picks it up on its next run, re-reads the text from the
 * service and writes the current scores back. Nothing is re-checked and no
 * document is uploaded a second time, so this costs the institution nothing.
 *
 * Only the state column is touched. score, scoreai and reportid keep the values
 * from the previous report until the poll overwrites them, so a refresh that
 * never completes leaves the stored data intact.
 */
class plagiarism_pchkorg_refresh_results {
    /**
     * Records eligible for a refresh: finished, and with a text to ask about.
     *
     * The textid guard is defensive. A record only reaches LOCAL_CHECKED after
     * the service returned a text id, but a null one would make the poll ask
     * for /text/ and strand the record at LOCAL_SENT for good.
     */
    const SELECT = 'cm = :cm AND state = :state AND textid IS NOT NULL';

    /**
     * Whether the button should be offered at all.
     *
     * Deliberately requires a course module id, unlike the ignored-templates
     * section: an activity being created has no submissions to refresh.
     *
     * @param plagiarism_pchkorg_config_model $configmodel
     * @param context $context Module context.
     * @param int|null $cmid Course module id, or null while creating.
     * @return bool
     */
    public static function is_available($configmodel, $context, $cmid) {
        if (empty($cmid)) {
            return false;
        }

        if ('1' !== $configmodel->get_system_config('pchkorg_use')) {
            return false;
        }

        if (!$configmodel->is_enabled_for_module($cmid)) {
            return false;
        }

        return has_capability(capability::ENABLE, $context);
    }

    /**
     * How many submissions in this activity could be refreshed.
     *
     * @param int $cmid Course module id.
     * @return int
     */
    public static function count_refreshable($cmid) {
        global $DB;

        return $DB->count_records_select(
            'plagiarism_pchkorg_files',
            self::SELECT,
            self::conditions($cmid)
        );
    }

    /**
     * Queue every finished check in this activity to be polled again.
     *
     * @param int $cmid Course module id.
     * @return int Number of records queued.
     */
    public static function refresh($cmid) {
        global $DB;

        // Counted before the update because set_field_select() reports only
        // whether it succeeded, not how many rows it touched.
        $count = self::count_refreshable($cmid);
        if (0 === $count) {
            return 0;
        }

        $DB->set_field_select(
            'plagiarism_pchkorg_files',
            'state',
            plagiarism_pchkorg_state::LOCAL_SENT,
            self::SELECT,
            self::conditions($cmid)
        );

        return $count;
    }

    /**
     * What to tell the teacher after a refresh.
     *
     * Built here rather than in the browser so that the AJAX endpoint can
     * return a message already in the site language.
     *
     * @param int $count Number of records queued.
     * @return string
     */
    public static function result_message($count) {
        $count = (int) $count;

        if (0 === $count) {
            return get_string('pchkorg_refresh_results_none', 'plagiarism_pchkorg');
        }
        if (1 === $count) {
            return get_string('pchkorg_refresh_results_queued_one', 'plagiarism_pchkorg');
        }

        return get_string('pchkorg_refresh_results_queued', 'plagiarism_pchkorg', $count);
    }

    /**
     * Bound parameters for SELECT.
     *
     * @param int $cmid Course module id.
     * @return array
     */
    private static function conditions($cmid) {
        return [
            'cm' => $cmid,
            'state' => plagiarism_pchkorg_state::LOCAL_CHECKED,
        ];
    }
}
