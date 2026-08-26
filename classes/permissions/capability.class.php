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
 * Names of the capabilities this plugin defines.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg\classes\permissions;

use context_course;
use context_module;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

/**
 * Capability names defined by this plugin.
 */
class capability {
    /**
     * ENABLE
     */
    const ENABLE = 'plagiarism/pchkorg:enable';
    /**
     * VIEW_SIMILARITY
     */
    const VIEW_SIMILARITY = 'plagiarism/pchkorg:viewsimilarity';
    /**
     * CHANGE_MIN_PERCENT_FILTER
     */
    const CHANGE_MIN_PERCENT_FILTER = 'plagiarism/pchkorg:changeminpercentfilter';
    /**
     * MANAGE_IGNORE_TEMPLATES
     *
     * Attaching, deleting and downloading the template material an activity's
     * submissions are checked against. Its own capability rather than
     * moodle/course:manageactivities, so a site can hand templates to a role
     * that does not edit activities, or withhold them from one that does.
     */
    const MANAGE_IGNORE_TEMPLATES = 'plagiarism/pchkorg:manageignoretemplates';
}
