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
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

/**
 * Class provider
 *
 * @package plagiarism_pchkorg\privacy
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider {

    // This trait must be included.
    use \core_privacy\local\legacy_polyfill;

    /**
     * @param collection $collection
     * @return collection
     */
    public static function _get_metadata(collection $collection) {

        $collection->add_subsystem_link(
                'core_files',
                array(),
                'privacy:metadata:core_files'
        );

        $collection->add_database_table(
                'plagiarism_pchkorg_files',
                array(
                        'cm' => 'privacy:metadata:plagiarism_pchkorg_files:cm',
                        'fileid' => 'privacy:metadata:forum_discussion_subs:fileid',
                        'userid' => 'privacy:metadata:forum_discussion_subs:userid',
                        'score' => 'privacy:metadata:forum_discussion_subs:score',
                        'textid' => 'privacy:metadata:forum_discussion_subs:textid',
                        'reportid' => 'privacy:metadata:forum_discussion_subs:reportid',

                ),
                'privacy:metadata:plagiarism_pchkorg_files'
        );

        $collection->add_database_table(
                'plagiarism_pchkorg_config',
                array(
                        'cm' => 'privacy:metadata:plagiarism_pchkorg_config:cm',
                        'name' => 'privacy:metadata:plagiarism_pchkorg_config:name',
                        'value' => 'privacy:metadata:plagiarism_pchkorg_config:value',

                ),
                'privacy:metadata:plagiarism_pchkorg_config'
        );

        $collection->add_external_location_link(
                'plagiarism_pchkorg',
                array(
                        'file' => 'privacy:metadata:plagiarism_pchkorg:file',
                ),
                'privacy:metadata:plagiarism_pchkorg'
        );

        return $collection;
    }
}
