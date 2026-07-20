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
 * Capability definitions.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

$capabilities = [
    'plagiarism/pchkorg:enable' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
            'coursecreator'  => CAP_ALLOW,
        ],
    ],
    'plagiarism/pchkorg:viewsimilarity' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
            'student'        => CAP_ALLOW,
            'coursecreator'  => CAP_ALLOW,
        ],
    ],
    'plagiarism/pchkorg:changeminpercentfilter' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
            'student'        => CAP_ALLOW,
            'coursecreator'  => CAP_ALLOW,
        ],
    ],
];
