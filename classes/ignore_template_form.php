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
 * Ignored templates section of the activity settings form.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/assignment_key.php');
require_once(__DIR__ . '/ignore_template_filemanager.php');
require_once(__DIR__ . '/permissions/capability.class.php');
require_once(__DIR__ . '/plagiarism_pchkorg_api_provider.php');
require_once(__DIR__ . '/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/refresh_results.php');

use plagiarism_pchkorg\classes\permissions\capability;

/**
 * Builds and saves the ignored-templates part of an activity settings form.
 *
 * Kept out of lib.php, which is already long, and out of the API provider,
 * which knows nothing about forms.
 */
class plagiarism_pchkorg_ignore_template_form {
    /** Client-side cap. The service enforces the authoritative limit. */
    const MAX_COUNT = 5;

    /** Form field holding newly uploaded templates. */
    const FIELD_FILES = 'pchkorg_ignore_template_files';

    /** Form field holding pasted template text. */
    const FIELD_TEXT = 'pchkorg_ignore_template_text';

    /** Form field prefix for the delete checkboxes. */
    const FIELD_DELETE = 'pchkorg_ignore_template_delete_';

    /**
     * Whether the section should appear at all.
     *
     * Requires the site setting, an institutional (group) token and the
     * capability. Deliberately not a course module id: while an activity is
     * being created there is no id yet, but the templates can still be
     * collected and posted once the module row exists.
     *
     * @param plagiarism_pchkorg_config_model $configmodel
     * @param context $context Module context when editing, course context when
     *                         creating.
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests.
     * @return bool
     */
    public static function is_available($configmodel, $context, $apiprovider = null) {
        if ('1' !== $configmodel->get_system_config('pchkorg_enable_ignore_templates')) {
            return false;
        }

        if (null === $apiprovider) {
            $apiprovider = new plagiarism_pchkorg_api_provider($configmodel->get_system_config('pchkorg_token'));
        }
        // Templates belong to an institution, and a personal token has none.
        if (!$apiprovider->is_group_token()) {
            return false;
        }

        return has_capability(capability::MANAGE_IGNORE_TEMPLATES, $context);
    }

    /**
     * Add the section to an activity settings form.
     *
     * @param MoodleQuickForm $mform
     * @param int|null $cmid Course module id, or null while creating.
     * @param plagiarism_pchkorg_config_model $configmodel
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests.
     * @return void
     */
    public static function add_elements($mform, $cmid, $configmodel, $apiprovider = null) {
        global $USER;

        // A new activity has no course module id yet, so there is nothing to
        // list and nothing to delete: only the upload and paste fields are
        // shown, and save() posts them once the module row exists.
        //
        // Deliberately no list call in that case. The key would name a course
        // module that does not exist, and it would cost a request on every
        // activity creation form on the site.
        $iscreating = empty($cmid);

        $mform->addElement(
            'header',
            'pchkorg_ignore_templates',
            get_string('pchkorg_ignore_templates', 'plagiarism_pchkorg')
        );
        $mform->addHelpButton(
            'pchkorg_ignore_templates',
            'pchkorg_ignore_templates',
            'plagiarism_pchkorg'
        );

        $templates = $iscreating ? [] : self::fetch_templates($cmid, $configmodel, $USER->email, $apiprovider);

        // A short lead-in and a slot counter, so the section explains itself
        // without the teacher having to open the help popup first.
        $mform->addElement(
            'static',
            'pchkorg_ignore_template_intro',
            '',
            self::intro_html($templates)
        );

        if ($iscreating) {
            $mform->addElement(
                'static',
                'pchkorg_ignore_template_create_note',
                '',
                html_writer::div(
                    get_string('pchkorg_ignore_template_create_note', 'plagiarism_pchkorg'),
                    'pchkorg-tpl-empty'
                )
            );
        } else if (null === $templates) {
            // The list could not be loaded. Say so rather than implying the
            // activity has no templates, which would invite a re-upload.
            $mform->addElement(
                'static',
                'pchkorg_ignore_template_list',
                get_string('pchkorg_ignore_template_existing', 'plagiarism_pchkorg'),
                html_writer::div(
                    get_string('pchkorg_ignore_template_unavailable', 'plagiarism_pchkorg'),
                    'alert alert-warning'
                )
            );
        } else if (empty($templates)) {
            $mform->addElement(
                'static',
                'pchkorg_ignore_template_list',
                get_string('pchkorg_ignore_template_existing', 'plagiarism_pchkorg'),
                html_writer::div(
                    get_string('pchkorg_ignore_template_none', 'plagiarism_pchkorg'),
                    'pchkorg-tpl-empty'
                )
            );
        } else {
            self::add_existing_elements($mform, $cmid, $templates);
        }

        MoodleQuickForm::registerElementType(
            plagiarism_pchkorg_ignore_template_filemanager::ELEMENT_TYPE,
            __DIR__ . '/ignore_template_filemanager.php',
            'plagiarism_pchkorg_ignore_template_filemanager'
        );
        $mform->addElement(
            plagiarism_pchkorg_ignore_template_filemanager::ELEMENT_TYPE,
            self::FIELD_FILES,
            get_string('pchkorg_ignore_template_add_files', 'plagiarism_pchkorg'),
            null,
            self::filemanager_options()
        );
        $mform->addHelpButton(
            self::FIELD_FILES,
            'pchkorg_ignore_template_add_files',
            'plagiarism_pchkorg'
        );

        $mform->addElement(
            'textarea',
            self::FIELD_TEXT,
            get_string('pchkorg_ignore_template_paste_text', 'plagiarism_pchkorg'),
            [
                'rows' => 6,
                'cols' => 60,
                'placeholder' => get_string('pchkorg_ignore_template_paste_placeholder', 'plagiarism_pchkorg'),
            ]
        );
        $mform->setType(self::FIELD_TEXT, PARAM_TEXT);
        $mform->addHelpButton(
            self::FIELD_TEXT,
            'pchkorg_ignore_template_paste_text',
            'plagiarism_pchkorg'
        );

        // Open the section when there is something to look at, so attached
        // templates are not hidden behind a collapsed header. A creation form
        // has nothing to show yet and is long enough already.
        $mform->setExpanded(
            'pchkorg_ignore_templates',
            !$iscreating && (!empty($templates) || null === $templates)
        );
    }

    /**
     * Lead-in text, slot counter and (at the limit) a heads-up note.
     *
     * @param array|null $templates Current templates, or null when unknown.
     * @return string HTML.
     */
    private static function intro_html($templates) {
        $html = html_writer::div(
            get_string('pchkorg_ignore_template_intro', 'plagiarism_pchkorg'),
            'pchkorg-tpl-lead'
        );

        if (!is_array($templates)) {
            // Nothing reliable to count.
            return html_writer::div($html, 'pchkorg-tpl-intro');
        }

        $used = count($templates);
        $a = new stdClass();
        $a->used = $used;
        $a->max = self::MAX_COUNT;
        $html .= html_writer::div(
            html_writer::span(
                get_string('pchkorg_ignore_template_count', 'plagiarism_pchkorg', $a),
                'pchkorg-tpl-badge'
            ),
            'pchkorg-tpl-counter'
        );

        if ($used > 0) {
            $html .= html_writer::div(
                get_string('pchkorg_ignore_template_delete_hint', 'plagiarism_pchkorg'),
                'pchkorg-tpl-hint'
            );
        }
        if ($used >= self::MAX_COUNT) {
            $html .= html_writer::div(
                get_string('pchkorg_ignore_template_limit_reached', 'plagiarism_pchkorg', self::MAX_COUNT),
                'alert alert-info pchkorg-tpl-note'
            );
        }

        return html_writer::div($html, 'pchkorg-tpl-intro');
    }

    /**
     * Render the existing templates, each with a download link and a delete box.
     *
     * The rows share a single "Existing templates" label so they read as one
     * list rather than as several unrelated form fields.
     *
     * @param MoodleQuickForm $mform
     * @param int $cmid
     * @param array $templates
     * @return void
     */
    private static function add_existing_elements($mform, $cmid, array $templates) {
        $first = true;

        foreach ($templates as $template) {
            if (!isset($template->id)) {
                continue;
            }

            $id = (int) $template->id;
            $filename = self::template_name($template);

            $mform->addElement(
                'advcheckbox',
                self::FIELD_DELETE . $id,
                $first ? get_string('pchkorg_ignore_template_existing', 'plagiarism_pchkorg') : '',
                self::template_row_html($cmid, $template),
                [
                    'group' => 'pchkorg_ignore_template_delete',
                    'class' => 'pchkorg-tpl-check',
                    'aria-label' => get_string(
                        'pchkorg_ignore_template_delete_named',
                        'plagiarism_pchkorg',
                        $filename
                    ),
                ]
            );
            $mform->setDefault(self::FIELD_DELETE . $id, 0);
            $first = false;
        }
    }

    /**
     * One template, rendered as an icon, a download link and its size.
     *
     * @param int $cmid
     * @param object $template
     * @return string HTML.
     */
    private static function template_row_html($cmid, $template) {
        global $CFG, $OUTPUT;

        // file_extension_icon() is not guaranteed to be loaded on every page.
        require_once($CFG->libdir . '/filelib.php');

        $filename = self::template_name($template);
        $downloadtitle = get_string('pchkorg_ignore_template_download', 'plagiarism_pchkorg');
        $downloadurl = new moodle_url('/plagiarism/pchkorg/ignoretemplate.php', [
            'cmid' => $cmid,
            'id' => $template->id,
            'sesskey' => sesskey(),
        ]);

        // The separators are literal text, not margins, so the row still reads
        // as "name (size)" when the plugin stylesheet is not in play.
        $row = $OUTPUT->pix_icon(file_extension_icon($filename), '', 'moodle', ['class' => 'pchkorg-tpl-icon']);
        $row .= ' ' . html_writer::link($downloadurl, s($filename), [
            'class' => 'pchkorg-tpl-name',
            'title' => $downloadtitle,
        ]);
        if (isset($template->size)) {
            $row .= ' ' . html_writer::span(
                '(' . display_size((int) $template->size) . ')',
                'pchkorg-tpl-size'
            );
        }
        $row .= ' ' . html_writer::link(
            $downloadurl,
            $OUTPUT->pix_icon('t/download', $downloadtitle),
            ['class' => 'pchkorg-tpl-download', 'title' => $downloadtitle]
        );

        // A span, not a div: without the stylesheet an inline element still
        // sits on the same line as the checkbox that precedes it.
        return html_writer::span($row, 'pchkorg-tpl-row');
    }

    /**
     * Display name of a template, never empty.
     *
     * @param object $template
     * @return string
     */
    private static function template_name($template) {
        if (!empty($template->filename)) {
            return (string) $template->filename;
        }

        return get_string('pchkorg_ignore_template_untitled', 'plagiarism_pchkorg');
    }

    /**
     * Apply the submitted section when the activity form is saved.
     *
     * Runs for both a new and an existing activity: by the time the caller
     * reaches this point the module row exists either way, so coursemodule is
     * the id the templates should hang off.
     *
     * @param object $data Submitted form data; must carry coursemodule.
     * @param plagiarism_pchkorg_config_model $configmodel
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests.
     * @return stdClass ->error holds the error code to show, or null on success
     *                  or no-op; ->requeued holds how many of this activity's
     *                  finished checks were queued to be read again.
     */
    public static function save($data, $configmodel, $apiprovider = null) {
        global $USER;

        if (empty($data->coursemodule)) {
            return self::result(null);
        }
        if ('1' !== $configmodel->get_system_config('pchkorg_enable_ignore_templates')) {
            return self::result(null);
        }

        $deleteids = self::submitted_delete_ids($data);
        $text = isset($data->{self::FIELD_TEXT}) ? trim((string) $data->{self::FIELD_TEXT}) : '';
        $files = [];
        if (!empty($data->{self::FIELD_FILES})) {
            $draftitemid = (int) $data->{self::FIELD_FILES};
            if (self::has_oversized_file($draftitemid)) {
                return self::result('file_too_large');
            }
            $files = self::draft_files($draftitemid);
        }

        if ([] === $files && '' === $text && [] === $deleteids) {
            return self::result(null);
        }

        if ([] !== $files && '' !== $text) {
            return self::result('files_and_text_conflict');
        }

        if (null === $apiprovider) {
            $apiprovider = new plagiarism_pchkorg_api_provider($configmodel->get_system_config('pchkorg_token'));
        }
        $saved = $apiprovider->ignore_template_save(
            plagiarism_pchkorg_assignment_key::for_cmid($data->coursemodule),
            $files,
            $text,
            $deleteids,
            $USER->email
        );

        if (!$saved) {
            $error = $apiprovider->get_last_error();

            return self::result(empty($error) ? 'template_processing_failed' : $error);
        }

        // The set of templates that applies to this activity has just changed,
        // so every report already stored against it was produced under the old
        // set and no longer says what it should. The service re-checks its own
        // copies when it accepts this call; queueing the local records is what
        // makes Moodle ask for the revised scores, instead of leaving the ones
        // taken under the previous templates on screen for good.
        //
        // Deliberately not gated on plagiarism_pchkorg_refresh_results::
        // is_available(). That guards a teacher's explicit "refresh now"
        // instruction and asks for the capability behind it; this is a
        // consequence of a change they have already been allowed to make, to
        // the records of the very activity they made it on.
        return self::result(null, plagiarism_pchkorg_refresh_results::refresh($data->coursemodule));
    }

    /**
     * The outcome of a save, in the shape the caller expects.
     *
     * @param string|null $error Error code to show, or null.
     * @param int $requeued Finished checks queued to be read again.
     * @return stdClass
     */
    private static function result($error, $requeued = 0) {
        $result = new stdClass();
        $result->error = $error;
        $result->requeued = (int) $requeued;

        return $result;
    }

    /**
     * What to tell the teacher when a template change queued their submissions.
     *
     * Separate from plagiarism_pchkorg_refresh_results::result_message(), which
     * answers a teacher who asked for a refresh and can say so plainly. Here
     * they asked to change templates and the queueing is a consequence, so the
     * message has to name the cause or it reads as though the form did
     * something of its own accord.
     *
     * @param int $count Number of submissions queued. Callers only report a
     *                   positive count; there is nothing to say about zero.
     * @return string
     */
    public static function requeue_message($count) {
        $count = (int) $count;
        $key = 1 === $count
            ? 'pchkorg_ignore_template_requeued_one'
            : 'pchkorg_ignore_template_requeued';

        return get_string($key, 'plagiarism_pchkorg', $count);
    }

    /**
     * Ids ticked for deletion in the submitted form.
     *
     * @param object $data
     * @return int[]
     */
    private static function submitted_delete_ids($data) {
        $ids = [];
        foreach (get_object_vars($data) as $field => $value) {
            if (0 !== strpos($field, self::FIELD_DELETE)) {
                continue;
            }
            if (empty($value)) {
                continue;
            }
            $id = (int) substr($field, strlen(self::FIELD_DELETE));
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether the draft area holds a file the service would refuse on size.
     *
     * The upload element caps files at MAX_FILESIZE_BYTES, but core lifts that
     * cap for anyone holding moodle/course:ignorefilesizelimits — an
     * administrator, typically — so a file well over the limit can still reach
     * this point. Checked on the stored metadata, before draft_files() reads
     * the contents, so an oversized file is never pulled into memory and never
     * posted only to be rejected.
     *
     * @param int $draftitemid
     * @return bool
     */
    private static function has_oversized_file($draftitemid) {
        global $USER;

        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $limit = plagiarism_pchkorg_ignore_template_filemanager::MAX_FILESIZE_BYTES;

        foreach ($fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false) as $file) {
            if ($file->get_filesize() > $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the files a teacher just uploaded out of their draft area.
     *
     * @param int $draftitemid
     * @return array Each entry has filename, mime and content.
     */
    private static function draft_files($draftitemid) {
        global $USER;

        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = [];

        foreach ($fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false) as $file) {
            $files[] = [
                'filename' => $file->get_filename(),
                'mime' => $file->get_mimetype(),
                'content' => $file->get_content(),
            ];
        }

        return $files;
    }

    /**
     * Current templates, or null when the service could not be reached.
     *
     * @param int $cmid
     * @param plagiarism_pchkorg_config_model $configmodel
     * @param string $email
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests.
     * @return array|null
     */
    private static function fetch_templates($cmid, $configmodel, $email, $apiprovider = null) {
        if (null === $apiprovider) {
            $apiprovider = new plagiarism_pchkorg_api_provider($configmodel->get_system_config('pchkorg_token'));
        }

        return $apiprovider->ignore_template_list(
            plagiarism_pchkorg_assignment_key::for_cmid($cmid),
            $email
        );
    }

    /**
     * Options for the upload element.
     *
     * @return array
     */
    private static function filemanager_options() {
        return [
            'subdirs' => 0,
            'maxfiles' => self::MAX_COUNT,
            'maxbytes' => plagiarism_pchkorg_ignore_template_filemanager::MAX_FILESIZE_BYTES,
            'accepted_types' => plagiarism_pchkorg_ignore_template_filemanager::accepted_types(),
        ];
    }

    /**
     * Map a service error code to a translated message.
     *
     * @param string $code
     * @return string
     */
    public static function error_message($code) {
        $key = 'pchkorg_ignore_template_error_' . $code;
        $manager = get_string_manager();
        if (!$manager->string_exists($key, 'plagiarism_pchkorg')) {
            $key = 'pchkorg_ignore_template_error_template_processing_failed';
        }

        $a = null;
        if ('template_limit_exceeded' === $code) {
            $a = self::MAX_COUNT;
        }
        if ('file_too_large' === $code) {
            $a = plagiarism_pchkorg_ignore_template_filemanager::max_filesize_label();
        }

        return get_string($key, 'plagiarism_pchkorg', $a);
    }
}
