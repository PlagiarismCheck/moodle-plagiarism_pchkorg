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
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

if (interface_exists('\core_privacy\local\request\userlist')) {
    interface my_userlist extends \core_privacy\local\request\userlist{}
} else {
    interface my_userlist {};
}

/**
 * Class provider
 *
 * @package plagiarism_pchkorg\privacy
 */
class provider implements
        my_userlist,
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

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
                        'fileid' => 'privacy:metadata:plagiarism_pchkorg_files:fileid',
                        'userid' => 'privacy:metadata:plagiarism_pchkorg_files:userid',
                        'state' => 'privacy:metadata:plagiarism_pchkorg_files:state',
                        'score' => 'privacy:metadata:plagiarism_pchkorg_files:score',
                        'scoreai' => 'privacy:metadata:plagiarism_pchkorg_files:scoreai',
                        'created_at' => 'privacy:metadata:plagiarism_pchkorg_files:created_at',
                        'textid' => 'privacy:metadata:plagiarism_pchkorg_files:textid',
                        'reportid' => 'privacy:metadata:plagiarism_pchkorg_files:reportid',
                        'signature' => 'privacy:metadata:plagiarism_pchkorg_files:signature',
                        'attempt' => 'privacy:metadata:plagiarism_pchkorg_files:attempt',
                        'itemid' => 'privacy:metadata:plagiarism_pchkorg_files:itemid',

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

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   int         $userid     The user to search.
     * @return  contextlist   $contextlist  The contextlist containing the list of contexts used in this plugin.
     */
    public static function _get_contexts_for_userid(int $userid) : contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT cm FROM {plagiarism_pchkorg_files} WHERE userid = :userid";
        $params = [
            'userid' => $userid
        ];
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = [
            'cm'    => $context->instanceid,
        ];
        $sql = "SELECT DISTINCT userid FROM {plagiarism_pchkorg_files} WHERE cm = :cm";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function _export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }


    /**
     * Export all user preferences for the plugin.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     */
    public static function _export_user_preferences(int $userid) {
    }


    /**
     * Delete all data for all users in the specified context.
     *
     * @param   context         $context   The specific context to delete data for.
     */
    public static function _delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function _delete_data_for_user(approved_contextlist $contextlist) {
    }
}
