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
 * the same information grouped by kind of document.
 *
 * Registered as an element type by plagiarism_pchkorg_ignore_template_form.
 */
class plagiarism_pchkorg_ignore_template_filemanager extends MoodleQuickForm_filemanager {
    /** Element type name to register with MoodleQuickForm. */
    const ELEMENT_TYPE = 'pchkorg_ignore_template_filemanager';

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
     * Render the element, replacing core's mime-type list with a grouped one.
     *
     * @return string
     */
    public function toHtml() {
        $html = parent::toHtml();
        $cut = self::descriptions_offset($html);
        if (null !== $cut) {
            $html = substr($html, 0, $cut);
        }

        return $html . self::accepted_types_html();
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
