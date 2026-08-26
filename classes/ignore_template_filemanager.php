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
 * File manager that lists accepted file types by kind instead of by mime type.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/form/filemanager.php');

/**
 * A file manager whose "Accepted file types" list is grouped and readable.
 *
 * The stock element derives that list from the accepted extensions, one line
 * per mime type: nine lines for this plugin, sorted alphabetically, one of
 * them a raw "application/vnd.oasis.opendocument.presentation" because .odp
 * has no description string in core. This element drops that block and prints
 * the same information grouped by kind of document, followed by the per-file
 * size limit.
 *
 * Registered as an element type by plagiarism_pchkorg_ignore_template_form.
 */
class plagiarism_pchkorg_ignore_template_filemanager extends MoodleQuickForm_filemanager {
    /** Element type name to register with MoodleQuickForm. */
    const ELEMENT_TYPE = 'pchkorg_ignore_template_filemanager';

    /**
     * Largest template file the service accepts, in bytes.
     *
     * Decimal megabytes, matching the service's own limit exactly. Moodle
     * renders it in binary megabytes and so calls it 24 MB; the block this
     * element prints states it the way the service does.
     */
    const MAX_FILESIZE_BYTES = 25000000;

    /**
     * Accepted extensions, grouped by the kind of document they are.
     *
     * The keys name a language string suffix; the values are the extensions
     * the service accepts. This is the single source of truth for both the
     * upload filter and the list shown under it.
     *
     * @return array
     */
    public static function accepted_type_groups() {
        return [
            'documents' => ['.doc', '.docx', '.odt', '.rtf', '.txt'],
            'pdf' => ['.pdf'],
            'presentations' => ['.ppt', '.pptx', '.odp'],
        ];
    }

    /**
     * Every accepted extension, flattened, for the filemanager options.
     *
     * @return array
     */
    public static function accepted_types() {
        $types = [];
        foreach (self::accepted_type_groups() as $extensions) {
            $types = array_merge($types, $extensions);
        }

        return $types;
    }

    /**
     * The per-file size limit, worded the way the service counts it.
     *
     * Decimal megabytes, so the number a teacher reads here is the number in
     * MAX_FILESIZE_BYTES. display_size() is deliberately not used: it divides
     * by 1024 and would call the same limit 24 MB.
     *
     * @return string
     */
    public static function max_filesize_label() {
        return (string) round(self::MAX_FILESIZE_BYTES / 1000000) . ' ' . get_string('sizemb');
    }

    /**
     * Render the element, replacing core's mime-type list with a grouped one
     * and correcting its size restriction where core waives it.
     *
     * @return string
     */
    public function toHtml() {
        $html = parent::toHtml();
        $cut = self::descriptions_offset($html);
        if (null !== $cut) {
            $html = substr($html, 0, $cut);
        }

        return $this->corrected_restrictions($html) . self::accepted_types_html();
    }

    /**
     * Core's restriction line, with the real limit where core waived it.
     *
     * Core prints the smallest of the PHP, site, course and element limits,
     * except that get_user_max_upload_file_size() waives all of them for
     * anyone holding moodle/course:ignorefilesizelimits — an administrator,
     * typically — for whom it prints "Maximum file size: Unlimited". That
     * capability waives Moodle's own storage limits; it cannot waive the
     * service's, which still refuses anything over MAX_FILESIZE_BYTES.
     *
     * The line is therefore rewritten in the two cases where core is speaking
     * for our limit rather than a stricter one of its own: when it has been
     * waived, and when the figure shown is MAX_FILESIZE_BYTES itself, which
     * display_size() renders in binary megabytes and so calls 24 MB. Core's
     * own sentence is reused, so it stays translated and keeps the file count
     * it also carries.
     *
     * A site, course or PHP limit below ours is left alone: it is stricter,
     * and it is the limit the teacher is actually held to.
     *
     * The needle is built with the same functions, arguments and order core
     * used to print it. If it does not match — a Moodle version that words or
     * marks up the line differently — the html is returned untouched, on the
     * same reasoning as descriptions_offset(): a stale figure beside our own
     * is a much smaller problem than a mangled element.
     *
     * @param string $html
     * @return string
     */
    private function corrected_restrictions($html) {
        global $CFG, $PAGE;

        $unlimited = defined('USER_CAN_IGNORE_FILE_SIZE_LIMITS') ? USER_CAN_IGNORE_FILE_SIZE_LIMITS : -1;

        // Repeats what form_filemanager does to the element's maxbytes, which
        // is where the waiver is applied and why the option alone cannot fix
        // this.
        [$context, $course] = get_context_info_array($PAGE->context->id);
        $coursebytes = is_object($course) && isset($course->maxbytes) ? $course->maxbytes : 0;
        $shownbytes = get_user_max_upload_file_size(
            $context,
            $CFG->maxbytes,
            $coursebytes,
            $this->_options['maxbytes']
        );

        if ($unlimited !== $shownbytes && self::MAX_FILESIZE_BYTES !== $shownbytes) {
            return $html;
        }

        $shown = new stdClass();
        $shown->size = display_size($shownbytes, 0);
        $shown->attachments = $this->getMaxfiles();
        $real = clone $shown;
        $real->size = self::max_filesize_label();

        // A bare span, exactly as core_files_renderer::fm_print_restrictions
        // builds it. maxsizeandattachments is the wording it picks for an
        // element with a file count and no area limit, which is this one.
        return str_replace(
            '<span>' . get_string('maxsizeandattachments', 'moodle', $shown) . '</span>',
            '<span>' . get_string('maxsizeandattachments', 'moodle', $real) . '</span>',
            $html
        );
    }

    /**
     * Where core's accepted-types block starts in the rendered element.
     *
     * Returns null when it is not there, in which case nothing is removed:
     * a duplicated list is a much smaller problem than a mangled element.
     *
     * @param string $html
     * @return int|null
     */
    private static function descriptions_offset($html) {
        $offsets = [];

        $heading = strpos($html, html_writer::tag('p', get_string('filesofthesetypes', 'form')));
        if (false !== $heading) {
            $offsets[] = $heading;
        }
        $list = strpos($html, '<div class="form-filetypes-descriptions');
        if (false !== $list) {
            $offsets[] = $list;
        }

        if ([] === $offsets) {
            return null;
        }

        return min($offsets);
    }

    /**
     * The accepted file types, as a heading and one row per kind of document.
     *
     * The size limit is not repeated here: corrected_restrictions() has
     * already made core's own restriction line state it correctly.
     *
     * Built from elements that carry their own default rendering — a table for
     * the two columns, <code> for the extensions, core icons for the kind — so
     * the block still reads correctly if the plugin stylesheet has not been
     * picked up yet (a stale theme CSS cache, for instance). The stylesheet
     * only refines what is already legible.
     *
     * @return string HTML.
     */
    public static function accepted_types_html() {
        $rows = '';

        foreach (self::accepted_type_groups() as $name => $group) {
            $extensions = [];
            foreach ($group as $extension) {
                $extensions[] = html_writer::tag('code', $extension, ['class' => 'pchkorg-tpl-ext']);
            }

            $rows .= html_writer::tag(
                'tr',
                html_writer::tag(
                    'th',
                    get_string('pchkorg_ignore_template_types_' . $name, 'plagiarism_pchkorg'),
                    ['scope' => 'row', 'class' => 'pchkorg-tpl-kind']
                )
                . html_writer::tag('td', implode(', ', $extensions), ['class' => 'pchkorg-tpl-exts'])
            );
        }

        $html = html_writer::tag(
            'p',
            get_string('pchkorg_ignore_template_types', 'plagiarism_pchkorg'),
            ['class' => 'pchkorg-tpl-types-title']
        );
        $html .= html_writer::tag(
            'table',
            html_writer::tag('tbody', $rows),
            ['class' => 'pchkorg-tpl-types-table']
        );
        return html_writer::div($html, 'pchkorg-tpl-types');
    }
}
