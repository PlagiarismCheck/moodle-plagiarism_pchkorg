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
 * Privacy API implementation.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace plagiarism_pchkorg\privacy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/plagiarism/pchkorg/classes/state.php');

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

if (interface_exists('\core_privacy\local\request\userlist')) {
    interface my_userlist extends \core_privacy\local\request\userlist{
    }
} else {
    interface my_userlist {
    };
}

/**
 * Class provider
 *
 * @package plagiarism_pchkorg\privacy
 */
class provider implements
    \core_plagiarism\privacy\plagiarism_provider,
    \core_plagiarism\privacy\plagiarism_user_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    my_userlist
{
    // This trait must be included.
    use \core_privacy\local\legacy_polyfill;

    /**
     * Get metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function _get_metadata(collection $collection) {

        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:core_files'
        );

        $collection->add_database_table(
            'plagiarism_pchkorg_files',
            [
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
                        'message' => 'privacy:metadata:plagiarism_pchkorg_files:message',

                ],
            'privacy:metadata:plagiarism_pchkorg_files'
        );

        $collection->add_database_table(
            'plagiarism_pchkorg_config',
            [
                        'cm' => 'privacy:metadata:plagiarism_pchkorg_config:cm',
                        'name' => 'privacy:metadata:plagiarism_pchkorg_config:name',
                        'value' => 'privacy:metadata:plagiarism_pchkorg_config:value',

                ],
            'privacy:metadata:plagiarism_pchkorg_config'
        );

        // Registered users are remembered so the auto-registration task does not
        // re-send the same person on every run. The row is the email itself, so
        // it is personal data and is declared and deleted like any other.
        $collection->add_database_table(
            'plagiarism_pchkorg_users',
            [
                        'email' => 'privacy:metadata:plagiarism_pchkorg_users:email',

                ],
            'privacy:metadata:plagiarism_pchkorg_users'
        );

        // One external location for the service, extended rather than duplicated:
        // ignored activity templates travel to the same place as submissions.
        // Nothing about a template is stored in Moodle, so there is no matching
        // database table here and nothing extra to export or delete. Templates
        // are institutional data owned by the activity, not by the teacher who
        // uploaded them, so they survive that teacher's account being deleted.
        //
        // The email is listed once but leaves in two forms, and both are
        // described in its string: hashed for membership and template calls,
        // and in full, with the user's name, when auto-registration creates an
        // account for them on the service.
        $collection->add_external_location_link(
            'plagiarism_pchkorg',
            [
                        'file' => 'privacy:metadata:plagiarism_pchkorg:file',
                        'email' => 'privacy:metadata:plagiarism_pchkorg:email',
                        'name' => 'privacy:metadata:plagiarism_pchkorg:name',
                        'assignment_key' => 'privacy:metadata:plagiarism_pchkorg:assignment_key',
                        'template_filename' => 'privacy:metadata:plagiarism_pchkorg:template_filename',
                        'template_content' => 'privacy:metadata:plagiarism_pchkorg:template_content',
                ],
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
    public static function _get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // The cm column holds a course module id, and add_from_sql() wants
        // context ids: the two must be joined, not used interchangeably.
        $sql = "SELECT DISTINCT pchkorgctx.id
                  FROM {plagiarism_pchkorg_files} pchkorgfiles
                  JOIN {course_modules} pchkorgcm ON pchkorgcm.id = pchkorgfiles.cm
                  JOIN {context} pchkorgctx
                       ON pchkorgctx.instanceid = pchkorgcm.id
                      AND pchkorgctx.contextlevel = :contextlevel
                 WHERE pchkorgfiles.userid = :userid";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
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
     * This is the complete record. The plagiarism subsystem also asks for the
     * same data through export_plagiarism_user_data(), which places it beside
     * the submission it belongs to, but only some activity types call that hook
     * — mod_quiz does not — so this is what guarantees nothing is left out.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function _export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $records = $DB->get_records(
                'plagiarism_pchkorg_files',
                ['cm' => $context->instanceid, 'userid' => $userid],
                'id'
            );
            if (empty($records)) {
                continue;
            }

            $checks = [];
            foreach ($records as $record) {
                $checks[] = self::describe_record($record);
            }

            $data = new \stdClass();
            $data->checks = $checks;

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'plagiarism_pchkorg')],
                $data
            );
        }
    }

    /**
     * Export the checks of one submission, beside the submission itself.
     *
     * Called by the plagiarism subsystem from the activity that owns the
     * submission, so the scores appear where a reader expects them rather than
     * in a separate list they have to correlate by hand.
     *
     * @param int $userid
     * @param \context $context
     * @param array $subcontext Where the activity is exporting this submission.
     * @param array $linkarray The submission being exported.
     */
    public static function export_plagiarism_user_data(int $userid, \context $context, array $subcontext, array $linkarray) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $conditions = ['cm' => $context->instanceid, 'userid' => (int) $userid];
        if (!empty($linkarray['file']) && \is_object($linkarray['file'])) {
            $conditions['fileid'] = $linkarray['file']->get_id();
        } else {
            // Everything that is not an attached file is text, and a text
            // record carries no file id.
            $conditions['fileid'] = null;
        }

        $records = $DB->get_records('plagiarism_pchkorg_files', $conditions, 'id');
        if (empty($records)) {
            return;
        }

        $checks = [];
        foreach ($records as $record) {
            $checks[] = self::describe_record($record);
        }

        $data = new \stdClass();
        $data->checks = $checks;

        writer::with_context($context)->export_data(
            \array_merge($subcontext, [get_string('pluginname', 'plagiarism_pchkorg')]),
            $data
        );
    }

    /**
     * One queue record, as a reader should see it.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private static function describe_record($record) {
        $check = new \stdClass();
        $check->status = self::describe_state($record->state);
        $check->similarity_score = $record->score;
        $check->ai_score = $record->scoreai;
        $check->report_id = $record->reportid;
        $check->text_id = $record->textid;
        if (!empty($record->created_at)) {
            $check->submitted = transform::datetime($record->created_at);
        }
        // Declared in the metadata, so it has to appear here too. It explains a
        // failed check and can carry the submitter's email address.
        if (!empty($record->message)) {
            $check->error_message = $record->message;
        }

        return $check;
    }

    /**
     * A state column value in words.
     *
     * @param int $state
     * @return string
     */
    private static function describe_state($state) {
        switch ((int) $state) {
            case \plagiarism_pchkorg_state::LOCAL_QUEUED:
                return get_string('privacy:state:queued', 'plagiarism_pchkorg');
            case \plagiarism_pchkorg_state::LOCAL_SENT:
                return get_string('privacy:state:sent', 'plagiarism_pchkorg');
            case \plagiarism_pchkorg_state::LOCAL_CHECKED:
                return get_string('privacy:state:checked', 'plagiarism_pchkorg');
            case \plagiarism_pchkorg_state::LOCAL_ERROR:
                return get_string('privacy:state:error', 'plagiarism_pchkorg');
            default:
                return get_string('privacy:state:unknown', 'plagiarism_pchkorg');
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param   approved_userlist       $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        self::delete_plagiarism_for_users($userlist->get_userids(), $userlist->get_context());
    }

    /**
     * Delete the checks of several users in one activity.
     *
     * @param array $userids
     * @param \context $context
     */
    public static function delete_plagiarism_for_users(array $userids, \context $context) {
        foreach ($userids as $userid) {
            self::delete_plagiarism_for_user((int) $userid, $context);
        }
    }

    /**
     * Export all user preferences for the plugin.
     *
     * The plugin sets no user preferences.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     */
    public static function _export_user_preferences(int $userid) {
    }


    /**
     * Delete all data for all users in the specified context.
     *
     * @param   \context         $context   The specific context to delete data for.
     */
    public static function _delete_data_for_all_users_in_context(\context $context) {
        self::delete_plagiarism_for_context($context);
    }

    /**
     * Delete every check belonging to one activity.
     *
     * The per-activity settings in plagiarism_pchkorg_config are deliberately
     * left alone: they describe the activity, not the people who submitted to
     * it, and course module deletion removes them separately.
     *
     * @param \context $context
     */
    public static function delete_plagiarism_for_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $DB->delete_records('plagiarism_pchkorg_files', ['cm' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function _delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            self::delete_plagiarism_for_user($userid, $context);
        }
    }

    /**
     * Delete one user's checks in one activity.
     *
     * @param int $userid
     * @param \context $context
     */
    public static function delete_plagiarism_for_user(int $userid, \context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $DB->delete_records('plagiarism_pchkorg_files', [
            'cm' => $context->instanceid,
            'userid' => $userid,
        ]);

        self::forget_service_registration($userid);
    }

    /**
     * Forget that this user was registered with the service.
     *
     * The row is keyed by email, not by user id, so the address has to be read
     * back before it can be removed. If the account is already gone there is
     * nothing to match on and nothing to do.
     *
     * Note this is a record of a registration, not the registration itself: the
     * account at PlagiarismCheck.org is unaffected, and while the Moodle account
     * still exists with auto-registration enabled the task may register them
     * again.
     *
     * @param int $userid
     */
    private static function forget_service_registration($userid) {
        global $DB;

        $email = $DB->get_field('user', 'email', ['id' => $userid]);
        if (empty($email)) {
            return;
        }

        $DB->delete_records('plagiarism_pchkorg_users', ['email' => $email]);
    }
}
