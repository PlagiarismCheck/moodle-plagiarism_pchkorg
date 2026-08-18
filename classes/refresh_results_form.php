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
 * The refresh-results checkbox in the activity settings form.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/refresh_results.php');
require_once(__DIR__ . '/plagiarism_pchkorg_config_model.php');

/**
 * Builds and applies the refresh-results part of an activity settings form.
 *
 * The checkbox lives in the plugin's existing settings section rather than one
 * of its own. Unlike everything else there it is an instruction rather than a
 * setting: it is acted on once when the form is saved and is deliberately not
 * stored, so it comes back unticked the next time the form is opened.
 *
 * That is why it is absent from the field list in
 * plagiarism_pchkorg_coursemodule_edit_post_actions(): a stored value would
 * re-run the refresh on every later save of the activity.
 */
class plagiarism_pchkorg_refresh_results_form {
    /** Form field holding the instruction to refresh. */
    const FIELD = 'pchkorg_refresh_results';

    /**
     * Id of the checkbox itself.
     *
     * Set explicitly rather than left to the form to generate, because the
     * wording beside the box is a label pointing at this id and the two have
     * to agree. It is the same value _generateId() would produce.
     */
    const ELEMENT_ID = 'id_' . self::FIELD;

    /**
     * Add the checkbox to the plugin's section of an activity settings form.
     *
     * @param MoodleQuickForm $mform
     * @param int $cmid Course module id. Never null: the caller checks
     *                  is_available() first, which requires one.
     * @return void
     */
    public static function add_elements($mform, $cmid) {
        $count = plagiarism_pchkorg_refresh_results::count_refreshable($cmid);

        // Deliberately always usable, even with nothing to refresh. A disabled
        // box invites the teacher to click at it and work out for themselves
        // why it will not move; ticking it and being told there was nothing to
        // refresh answers the question they actually asked.
        //
        // The wording beside the box is wrapped in a label of our own. Moodle
        // renders that argument into a plain description span tied to the
        // checkbox by aria-describedby, which reads correctly but cannot be
        // clicked; a label pointing at the same id makes the whole sentence a
        // click target, as a checkbox caption is expected to be.
        $mform->addElement(
            'advcheckbox',
            self::FIELD,
            get_string('pchkorg_refresh_results', 'plagiarism_pchkorg'),
            html_writer::label(
                get_string('pchkorg_refresh_results_label', 'plagiarism_pchkorg'),
                self::ELEMENT_ID,
                false,
                ['class' => 'pchkorg-refresh-label']
            ),
            ['id' => self::ELEMENT_ID]
        );
        $mform->setType(self::FIELD, PARAM_BOOL);
        $mform->addHelpButton(
            self::FIELD,
            'pchkorg_refresh_results',
            'plagiarism_pchkorg'
        );

        // Never carry a tick over from the values the form was populated with.
        // The box is an instruction, and a pre-ticked one would refresh on a
        // save the teacher made for some entirely unrelated reason.
        $mform->setDefault(self::FIELD, 0);

        // How much there is to refresh, so the teacher can tell whether ticking
        // the box will do anything before they save. This is what explains an
        // activity with nothing to fetch, in place of a disabled control.
        $mform->addElement(
            'static',
            self::FIELD . '_count',
            '',
            html_writer::div(
                html_writer::span(self::count_text($count), 'pchkorg-refresh-badge'),
                'pchkorg-refresh-counter'
            )
        );
    }

    /**
     * How many submissions could be refreshed, in words.
     *
     * Three strings rather than one, because a single "{$a} submissions" reads
     * as "1 submissions" and states the zero case in the least helpful way.
     *
     * @param int $count
     * @return string
     */
    private static function count_text($count) {
        if (0 === $count) {
            return get_string('pchkorg_refresh_results_count_none', 'plagiarism_pchkorg');
        }
        if (1 === $count) {
            return get_string('pchkorg_refresh_results_count_one', 'plagiarism_pchkorg');
        }

        return get_string('pchkorg_refresh_results_count', 'plagiarism_pchkorg', $count);
    }

    /**
     * Act on the checkbox when the activity settings form is saved.
     *
     * Re-checks the capability rather than trusting the form: the field could
     * have been posted by someone who never saw the section.
     *
     * @param object $data Submitted form data.
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return int Number of submissions queued. Zero when the box was not
     *             ticked, when there was nothing to queue, or when the caller
     *             may not do it.
     */
    public static function save($data, $configmodel = null) {
        if (empty($data->coursemodule) || empty($data->{self::FIELD})) {
            return 0;
        }

        $cmid = $data->coursemodule;
        $context = context_module::instance($cmid);

        if (null === $configmodel) {
            $configmodel = new plagiarism_pchkorg_config_model();
        }

        if (!plagiarism_pchkorg_refresh_results::is_available($configmodel, $context, $cmid)) {
            return 0;
        }

        return plagiarism_pchkorg_refresh_results::refresh($cmid);
    }
}
