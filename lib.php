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
 * Plugin hooks and the submission pipeline for PlagiarismCheck.org.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/plagiarism/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once(__DIR__ . '/classes/state.php');
require_once(__DIR__ . '/classes/assignment_key.php');
require_once(__DIR__ . '/classes/ignore_template_form.php');
require_once(__DIR__ . '/classes/refresh_results_form.php');
require_once(__DIR__ . '/classes/roles.php');
require_once(__DIR__ . '/classes/course_access.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_config_model.php');
require_once(__DIR__ . '/classes/plagiarism_pchkorg_api_provider.php');
require_once(__DIR__ . '/classes/submission/sender.php');
require_once(__DIR__ . '/classes/permissions/capability.class.php');

use plagiarism_pchkorg\classes\permissions\capability;

/**
 * Validate the minimum source similarity percentage.
 *
 * An empty field is not the same as 0 and is equally valid. Empty means the
 * activity defers to the site-wide threshold; 0 means this activity filters
 * nothing, whatever the site-wide threshold says. The two are kept apart all
 * the way to the service, so neither may be rejected here.
 *
 * @param mixed $value
 * @return bool
 */
function pchkorg_check_pchkorg_min_percent($value) {
    if (null === $value || '' === trim((string) $value)) {
        return true;
    }

    if (!is_numeric($value)) {
        return false;
    }

    return 0 <= $value && $value < 100;
}

/**
 * Add the plugin's settings to an activity settings form.
 *
 * @param object $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function plagiarism_pchkorg_coursemodule_standard_elements($formwrapper, $mform) {
    $context = context_course::instance($formwrapper->get_course()->id);
    $modulename = $formwrapper->get_current()->modulename;
    $allowedmodules = ['assign', 'mod_assign'];
    if (!$context || !isset($modulename)) {
        return;
    }
    global $DB;

    $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

    $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
    $enabled = has_capability(capability::ENABLE, $context);
    if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_quiz')) {
        $allowedmodules[] = 'quiz';
    }
    if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_forum')) {
        $allowedmodules[] = 'forum';
    }
    if ('1' == $config && $enabled) {
        if (!in_array($modulename, $allowedmodules, true)) {
            return;
        }
        // On submit the mod form posts a hidden 'update' of 0 for a new activity,
        // so an absent id can arrive as either null or 0. Normalise it to null.
        $cm = optional_param('update', 0, PARAM_INT);
        if (empty($cm)) {
            $cm = null;
        }
        $minpercent = $pchkorgconfigmodel->get_system_config('pchkorg_min_percent');
        $exportedvalues = $mform->exportValues([]);
        if (!is_array($exportedvalues)) {
            $exportedvalues = [];
        }
        if (
            !isset($exportedvalues['pchkorg_exclude_self_plagiarism'])
            || is_null($exportedvalues['pchkorg_exclude_self_plagiarism'])
        ) {
            $mform->setDefault('pchkorg_exclude_self_plagiarism', 1);
        }
        if (
            !isset($exportedvalues['pchkorg_include_referenced'])
            || is_null($exportedvalues['pchkorg_include_referenced'])
        ) {
            $mform->setDefault('pchkorg_include_referenced', 0);
        }
        if (
            !isset($exportedvalues['pchkorg_include_citation'])
            || is_null($exportedvalues['pchkorg_include_citation'])
        ) {
            $mform->setDefault('pchkorg_include_citation', 0);
        }

        if (
            !isset($exportedvalues['pchkorg_student_can_see_report']) || is_null(
                $exportedvalues['pchkorg_student_can_see_report']
            )
        ) {
            $mform->setDefault('pchkorg_student_can_see_report', 1);
        }
        if (
            !isset($exportedvalues['pchkorg_student_can_see_widget']) || is_null(
                $exportedvalues['pchkorg_student_can_see_widget']
            )
        ) {
            $mform->setDefault('pchkorg_student_can_see_widget', 1);
        }
        if (
            !isset($exportedvalues['pchkorg_check_ai']) || is_null(
                $exportedvalues['pchkorg_check_ai']
            )
        ) {
            // The site-wide setting is what a new activity is offered, and what
            // an activity that never stores its own is checked with. Anything
            // but an explicit 0 -- an unset site setting included -- means on,
            // which is the behaviour every existing site already has.
            $sitecheckai = $pchkorgconfigmodel->get_system_config('pchkorg_check_ai');
            $mform->setDefault('pchkorg_check_ai', ('0' === (string) $sitecheckai) ? '0' : '1');
        }

        if (null === $cm) {
            if (
                !isset($exportedvalues['pchkorg_module_use'])
                || is_null($exportedvalues['pchkorg_module_use'])
            ) {
                $enabledbydefault = $pchkorgconfigmodel->get_system_config('pchkorg_enabled_by_default');
                if ('1' === $enabledbydefault || null === $enabledbydefault) {
                    $mform->setDefault('pchkorg_module_use', '1');
                }
                if ('0' === $enabledbydefault) {
                    $mform->setDefault('pchkorg_module_use', '0');
                }
            }
        } else {
            $records = $DB->get_records('plagiarism_pchkorg_config', [
                'cm' => $cm,
            ]);
            if (!empty($records)) {
                foreach ($records as $record) {
                    $mform->setDefault($record->name, $record->value);
                }
            }
        }
        $mform->addElement(
            'header',
            'plagiarism_pchkorg',
            get_string('pluginname', 'plagiarism_pchkorg')
        );
        $mform->addElement(
            'select',
            'pchkorg_module_use',
            get_string('pchkorg_module_use', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );
        $mform->addHelpButton('pchkorg_module_use', 'pchkorg_module_use', 'plagiarism_pchkorg');

        $canchangeminpercent = has_capability(capability::CHANGE_MIN_PERCENT_FILTER, $context);
        if ($canchangeminpercent) {
            $dissabledattribute = '';
        } else {
            $dissabledattribute = 'disabled="disabled"';
        }
        $mform->registerRule(
            'check_pchkorg_min_percent',
            'callback',
            'pchkorg_check_pchkorg_min_percent'
        );
        $label = get_string('pchkorg_min_percent', 'plagiarism_pchkorg');
        if (!empty($minpercent)) {
            $label = \str_replace('X%', $minpercent . '%', $label);
        }

        $mform->addElement(
            'text',
            'pchkorg_min_percent',
            $label,
            $dissabledattribute
        );
        $mform->addHelpButton('pchkorg_min_percent', 'pchkorg_min_percent', 'plagiarism_pchkorg');
        $mform->addRule(
            'pchkorg_min_percent',
            get_string('pchkorg_min_percent_range', 'plagiarism_pchkorg'),
            'check_pchkorg_min_percent'
        );
        // Not PARAM_INT: that cleans an empty field to 0, and here the two mean
        // different things -- empty defers to the site-wide threshold, 0 turns
        // filtering off for this activity. The value is validated by the rule
        // above and cast to an int before it is stored.
        $mform->setType('pchkorg_min_percent', PARAM_RAW_TRIMMED);

        $mform->addElement(
            'select',
            'pchkorg_exclude_self_plagiarism',
            get_string('pchkorg_exclude_self_plagiarism', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        $mform->addElement(
            'select',
            'pchkorg_include_referenced',
            get_string('pchkorg_include_referenced', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        $mform->addElement(
            'select',
            'pchkorg_include_citation',
            get_string('pchkorg_include_citation', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        $mform->addElement(
            'select',
            'pchkorg_student_can_see_widget',
            get_string('pchkorg_student_can_see_widget', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        $mform->addElement(
            'select',
            'pchkorg_student_can_see_report',
            get_string('pchkorg_student_can_see_report', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        $mform->addElement(
            'select',
            'pchkorg_check_ai',
            get_string('pchkorg_check_ai', 'plagiarism_pchkorg'),
            [get_string('no'), get_string('yes')]
        );

        // Re-fetching results for submissions already checked. Last in the
        // section because it is an instruction rather than a setting: it is
        // acted on once when the form is saved and is not stored.
        //
        // Only offered while editing. A new activity has no submissions to
        // refresh, and no course module id to name them by.
        if (null !== $cm) {
            $refreshcontext = context_module::instance($cm);
            if (plagiarism_pchkorg_refresh_results::is_available($pchkorgconfigmodel, $refreshcontext, $cm)) {
                plagiarism_pchkorg_refresh_results_form::add_elements($mform, $cm);
            }
        }

        // Ignored templates. Offered for every activity type this plugin
        // handles, not only assignments: the key identifying the activity to
        // the service is built from a course module id, which a quiz and a
        // forum have just as much as an assignment does.
        //
        // While an activity is being created there is no module context yet,
        // so the capability is checked against the course instead.
        $templatecontext = (null === $cm) ? $context : context_module::instance($cm);
        if (plagiarism_pchkorg_ignore_template_form::is_available($pchkorgconfigmodel, $templatecontext)) {
            plagiarism_pchkorg_ignore_template_form::add_elements($mform, $cm, $pchkorgconfigmodel);
        }
    }
}

/**
 * Persist the activity's source similarity threshold.
 *
 * Kept out of the loop that saves every other setting because this is the one
 * with three states rather than two:
 *
 *  - a number, which this activity filters sources by;
 *  - an empty field, meaning the activity defers to the site-wide threshold,
 *    which is what the absence of a record means to the sender;
 *  - 0, meaning this activity filters nothing at all, whatever the site-wide
 *    threshold says.
 *
 * Storing 0 is what makes the third state expressible. Earlier versions
 * deleted the record for it, leaving it indistinguishable from the second, so
 * a teacher who cleared a threshold silently got the site-wide one back -- and
 * with nothing left to send, the service went on applying the value it had
 * last been told.
 *
 * @param object $data Submitted form data.
 * @param array $records Existing config records for this course module.
 * @param bool $canchange Whether the acting user may change the threshold.
 * @return void
 */
function plagiarism_pchkorg_save_min_percent($data, $records, $canchange) {
    global $DB;

    // The field is rendered disabled for a user who may not change it, and a
    // disabled field is not posted at all. Writing anything from that absence
    // would let saving the activity for any other reason silently wipe a
    // threshold this user was never shown.
    if (!$canchange) {
        return;
    }

    $field = 'pchkorg_min_percent';

    $existing = null;
    foreach ($records as $record) {
        if ($record->name === $field) {
            $existing = $record;
            break;
        }
    }

    $submitted = isset($data->{$field}) ? trim((string) $data->{$field}) : '';

    // Empty, or anything the form rule would have rejected: defer to the site.
    if ('' === $submitted || !is_numeric($submitted)) {
        if (null !== $existing) {
            $DB->delete_records('plagiarism_pchkorg_config', ['id' => $existing->id]);
        }

        return;
    }

    $value = (string) (int) $submitted;

    if (null !== $existing) {
        $existing->value = $value;
        $DB->update_record('plagiarism_pchkorg_config', $existing);

        return;
    }

    $insert = new \stdClass();
    $insert->cm = $data->coursemodule;
    $insert->name = $field;
    $insert->value = $value;

    $DB->insert_record('plagiarism_pchkorg_config', $insert);
}

/**
 * Persist the plugin's per-activity settings when a form is saved.
 *
 * The single place a saved activity form is applied, and deliberately so.
 * plagiarism_plugin_pchkorg intentionally does not implement the older
 * plagiarism_plugin::save_form_elements(): Moodle 3.9 to 4.1 call both that
 * method and this callback for one save, and a plugin implementing both has
 * every save applied twice. Most of the work below survives that, being writes
 * of the posted value, but the parts that are instructions rather than settings
 * do not. The second run re-posts the same ignored templates -- the draft area
 * is still full, nothing having consumed it -- and PlagiarismCheck.org rejects
 * them as duplicates of the ones the first run has just attached, so a
 * successful upload is reported to the teacher as a failure.
 *
 * The base class carries an empty save_form_elements() on those versions, so
 * their deprecated call reaches that and does nothing; 4.2 onwards dropped the
 * call and the method with it. Either way this runs exactly once.
 *
 * @param object $data Submitted form data.
 * @param object|null $course
 * @return object The unmodified form data.
 */
function plagiarism_pchkorg_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();

    $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
    if ('1' != $config) {
        return $data;
    }

    // The similarity threshold is not in this list: it is the one setting with
    // three states rather than two, so it is saved separately below.
    $fields = [
        'pchkorg_module_use',
        'pchkorg_include_citation',
        'pchkorg_include_referenced',
        'pchkorg_exclude_self_plagiarism',
        'pchkorg_student_can_see_widget',
        'pchkorg_student_can_see_report',
        'pchkorg_check_ai',
    ];

    $records = $DB->get_records('plagiarism_pchkorg_config', [
        'cm' => $data->coursemodule,
    ]);

    $context = context_module::instance($data->coursemodule);

    foreach ($fields as $field) {
        $isfounded = false;
        foreach ($records as $record) {
            if ($record->name === $field) {
                $isfounded = true;
                if (!isset($data->{$record->name}) || $data->{$record->name} === null) {
                    $data->{$record->name} = 0;
                }
                $record->value = $data->{$record->name};
                $DB->update_record('plagiarism_pchkorg_config', $record);
                break;
            }
        }
        if (!$isfounded && isset($data->{$field})) {
            $insert = new \stdClass();
            $insert->cm = $data->coursemodule;
            $insert->name = $field;
            $insert->value = $data->{$field};

            $DB->insert_record('plagiarism_pchkorg_config', $insert);
        }
    }

    plagiarism_pchkorg_save_min_percent(
        $data,
        $records,
        has_capability(capability::CHANGE_MIN_PERCENT_FILTER, $context)
    );

    // The loop above wrote the activity's settings straight through $DB, which
    // the config model caches for the life of the request and cannot see. The
    // form was defined earlier in this same request, so those caches are
    // already populated with the values as they were before this save. Discard
    // them, or anything below reads the settings the teacher has just changed.
    plagiarism_pchkorg_config_model::reset_caches();

    // Ignored assignment templates. A failure here must not abort saving the
    // activity, so it is reported as a notification and the rest of the
    // settings are kept.
    $templateresult = plagiarism_pchkorg_ignore_template_form::save($data, $pchkorgconfigmodel);
    if (null !== $templateresult->error) {
        \core\notification::error(plagiarism_pchkorg_ignore_template_form::error_message($templateresult->error));
    }

    // Refreshing results. Reported as a notification because saving the form
    // redirects away from it, leaving nowhere to show the outcome in place.
    //
    // A template change has already queued this activity's finished checks, so
    // a box ticked on the same save finds nothing of its own left to queue. The
    // two counts are added and reported once, rather than following "12
    // submissions queued" with "there was nothing to refresh". When the box was
    // not ticked the queueing needs explaining, so it is reported in terms of
    // the template change that caused it.
    if (!empty($data->{plagiarism_pchkorg_refresh_results_form::FIELD})) {
        $refreshed = plagiarism_pchkorg_refresh_results_form::save($data, $pchkorgconfigmodel);
        \core\notification::success(
            plagiarism_pchkorg_refresh_results::result_message($refreshed + $templateresult->requeued)
        );
    } else if ($templateresult->requeued > 0) {
        \core\notification::success(
            plagiarism_pchkorg_ignore_template_form::requeue_message($templateresult->requeued)
        );
    }

    return $data;
}

/**
 * Class plagiarism_plugin_pchkorg
 */
class plagiarism_plugin_pchkorg extends plagiarism_plugin {
    /**
     * Maximum length of plagiarism_pchkorg_files.message.
     *
     * Must match the column width declared in db/install.xml and db/upgrade.php
     * (char(130)). Every write to that column goes through truncate_message(),
     * because on a database in strict mode an over-length value aborts the
     * insert and the submission is lost -- a shortened message is always the
     * better outcome.
     */
    const MESSAGE_MAX_LENGTH = 130;

    /**
     * Fit a message into plagiarism_pchkorg_files.message.
     *
     * Uses core_text so a multibyte message is never cut mid-character, which
     * would store invalid UTF-8.
     *
     * @param string|null $message message to store
     * @return string|null message guaranteed to fit the column
     */
    public static function truncate_message($message) {
        if (null === $message || '' === $message) {
            return $message;
        }

        if (core_text::strlen($message) <= self::MESSAGE_MAX_LENGTH) {
            return $message;
        }

        return core_text::substr($message, 0, self::MESSAGE_MAX_LENGTH);
    }

    /**
     * hook to allow plagiarism specific information to be displayed beside a submission.
     *
     * @param array $linkarraycontains all relevant information for the plugin to generate a link
     * @return string
     *
     */
    public function get_links($linkarray) {
        global $DB, $USER, $PAGE;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
        $isdebugenabled = $pchkorgconfigmodel->get_system_config('pchkorg_enable_debug') === '1';
        $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);

        $cmid = null;
        if (array_key_exists('cmid', $linkarray)) {
            $cmid = $linkarray['cmid'];
        }

        if (array_key_exists('file', $linkarray)) {
            $file = $linkarray['file'];
        } else {
            // Online text submission.
            $file = null;
        }

        // We can do nothing with submissions which we can not handle.
        if (null !== $file && !$apiprovider->is_supported_mime($file->get_mimetype())) {
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string('pchkorg_debug_mime', 'plagiarism_pchkorg'),
                    $file->get_mimetype()
                ),
                $isdebugenabled
            );
        }

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return $this->exit_message(
                get_string('pchkorg_debug_disabled', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }
        $context = null;
        $component = !empty($linkarray['component']) ? $linkarray['component'] : '';
        if ($cmid === null && $component == 'qtype_essay' && !empty($linkarray['area'])) {
            $questions = question_engine::load_questions_usage_by_activity($linkarray['area']);
            $context = $questions->get_owning_context();
            if ($cmid === null && $context->contextlevel == CONTEXT_MODULE) {
                $cmid = $context->instanceid;
            }
        }

        if (!empty($cmid)) {
            $context = context_module::instance($cmid);// Get context of course.
        }

        if (empty($context)) {
            return $this->exit_message(
                get_string('pchkorg_debug_empty_context', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }
        // Moodle allows several roles at once, so anyone holding a teaching role
        // is treated as a teacher even when they also hold the student role.
        $isstudent = plagiarism_pchkorg_roles::is_student($context, $USER->id);

        $canview = has_capability(capability::VIEW_SIMILARITY, $context);

        $pchkorgconfigmodel->show_widget_for_student($cmid);

        if (!$canview) {
            return $this->exit_message(
                get_string('pchkorg_debug_user_has_no_permission', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        // SQL will be called only once per page. There is static result inside.
        if (!$pchkorgconfigmodel->is_enabled_for_module($cmid)) {
            return $this->exit_message(
                get_string('pchkorg_debug_disabled_acitivity', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        // Widget for student is disabled.
        if ($isstudent && $pchkorgconfigmodel->show_widget_for_student($cmid) === false) {
            return $this->exit_message(
                get_string('pchkorg_debug_student_not_allowed_see_widget', 'plagiarism_pchkorg'),
                $isdebugenabled
            );
        }

        $isreportallowed = !$isstudent
            || $pchkorgconfigmodel->show_report_for_student($cmid) === true
            || $pchkorgconfigmodel->show_report_for_student($cmid) === null;

        // Only for some type of account, method will call a remote HTTP API.
        // The API will be called only once, because result is static.
        // Also, there is timeout 8 seconds for response.
        // Even if service will be unavailable, method will try call API only once.
        // Also, we don't use raw user email.
        $servicelogin = plagiarism_pchkorg_service_login::resolve($apiprovider, $USER, $pchkorgconfigmodel);
        $ismemberresponse = $servicelogin->member;
        if (!$ismemberresponse->is_member) {
            // Deny in both cases: a confirmed non-member, and an unknown
            // answer (service unreachable). Failing open on "unknown" would
            // let a non-member through during an outage.
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string(
                        $ismemberresponse->is_known ? 'pchkorg_debug_not_member' : 'pchkorg_debug_membership_unknown',
                        'plagiarism_pchkorg'
                    ),
                    $USER->email
                ),
                $isdebugenabled
            );
        }

        $isgranted = !empty($context) && has_capability('mod/assign:view', $context, null);
        if (!$isgranted) {
            return $this->exit_message(
                sprintf(
                    '%s (%s)',
                    get_string('pchkorg_debug_user_has_no_capability', 'plagiarism_pchkorg'),
                    'mod/assign:view'
                ),
                $isdebugenabled
            );
        }

        $where = new \stdClass();
        $where->cm = $cmid;
        if ($file === null) {
            if (!array_key_exists('content', $linkarray) && $component == 'qtype_essay' && !empty($linkarray['area'])) {
                $questions = question_engine::load_questions_usage_by_activity($linkarray['area']);
                $attempt = $questions->get_question_attempt($linkarray['itemid']);
                $response = $attempt->get_response_summary();
                $signature = sha1($response);
            } else {
                $signature = sha1($linkarray['content']);
            }
            $where->signature = $signature;
            $where->fileid = null;
        } else {
            $where->fileid = $file->get_id();
        }
        $filerecords = $DB->get_records(
            'plagiarism_pchkorg_files',
            (array) $where,
            'id',
            '*',
            0,
            1
        );

        if (!$filerecords && $file === null) {
            $where->signature = sha1(trim(strip_tags($linkarray['content'])));
            $where->fileid = null;
            $filerecords = $DB->get_records(
                'plagiarism_pchkorg_files',
                (array) $where,
                'id',
                '*',
                0,
                1
            );
        }

        if ($filerecords) {
            $filerecord = end($filerecords);

            $img = new moodle_url('/plagiarism/pchkorg/pix/icon.png');
            $imgsrc = $img->__toString();

            if (
                array_key_exists('forum', $linkarray)
                && $isstudent
                && $filerecord->userid !== $USER->id
            ) {
                    return $this->exit_message(
                        sprintf(
                            '%s (%s)',
                            get_string('pchkorg_debug_student_not_allowed_see_widget', 'plagiarism_pchkorg'),
                            $USER->email
                        ),
                        $isdebugenabled
                    );
            }

            // Text had been successfully checked.
            if ($filerecord->state == plagiarism_pchkorg_state::LOCAL_CHECKED) {
                $score = $filerecord->score;
                $isaienabled = '1' === $pchkorgconfigmodel->get_filter_for_module($cmid, 'pchkorg_check_ai');

                if (isset($filerecord->scoreai) && $isaienabled) {
                    $title = sprintf(
                        get_string('pchkorg_label_title_ai', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score,
                        $filerecord->scoreai
                    );
                    $label = sprintf(
                        get_string('pchkorg_label_result_ai', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score,
                        $filerecord->scoreai
                    );
                } else {
                    $title = sprintf(
                        get_string('pchkorg_label_title', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score
                    );
                    $label = sprintf(
                        get_string('pchkorg_label_result', 'plagiarism_pchkorg'),
                        $filerecord->textid,
                        $score
                    );
                }

                if ($score < 30) {
                    $color = '#63EC80';
                } else if (30 < $score && $score < 60) {
                    $color = '#F7B011';
                } else {
                    $color = '#F04343';
                }
                // The report link points at this plugin, not at the service. The
                // service credential is never handed to the browser: report.php
                // re-checks permission server side and only then posts the token
                // on to plagiarismcheck.org. Viewers who may see the score but
                // not open the report get no link at all.
                $reporturl = null;
                if ($isreportallowed) {
                    $reporturl = (new moodle_url(
                        '/plagiarism/pchkorg/report.php',
                        ['id' => $filerecord->id, 'sesskey' => sesskey()]
                    ))->out(false);
                }

                $jsdata = [
                    'id' => $filerecord->id,
                    'title' => $title,
                    'label' => $label,
                    'color' => $color,
                    'reporturl' => $reporturl,
                ];
                static $isjsfuncinjected = false;
                if (!$isjsfuncinjected) {
                    $isjsfuncinjected = true;
                    $PAGE->requires->js_amd_inline(
                        "
window.plagiarism_check_data = [];

require(['jquery'], function ($) {
    $(function () {
        var spans = window.document.getElementsByClassName('plagiarism-pchkorg-widget');
        for (var s in spans) {
            var span = spans[s];
            if (span) {
                for (var c in span.classList) {
                    var classname = span.classList[c];
                    if (classname && classname.includes('plagiarism-pchkorg-widget-id-')) {
                        var id = classname.replace('plagiarism-pchkorg-widget-id-', '');
                        if (id) {
                            for (var d in window.plagiarism_check_data) {
                                var data = window.plagiarism_check_data[d];
                                if (data && data.id == id) {
                                    var a = document.createElement(data.reporturl ? 'a' : 'span');
                                    if (data.reporturl) {
                                        a.setAttribute('href', data.reporturl);
                                        a.setAttribute('target', '_blank');
                                        a.setAttribute('rel', 'noopener');
                                    }
                                    a.setAttribute('title', data.title);
                                    a.setAttribute('data-id', data.id);
                                    a.style.fontFamily =  'Roboto';
                                    a.style.fontStyle = 'normal';
                                    a.style.fontWeight =  '400';
                                    a.style.fontSize =  '16px';
                                    a.style.textAlign =  'center';
                                    a.style.padding = '4px 16px';
                                    a.style.textDecoration = 'none';
                                    a.style.backgroundColor = data.color;
                                    a.style.color = 'black';
                                    a.style.cursor = data.reporturl ? 'pointer' : 'default';
                                    a.style.borderRadius = '4px 4px 4px 4px';
                                    a.style.margin = '4px';
                                    a.style.display = 'inline-block';
                                    var label = document.createTextNode(data.label);
                                    a.appendChild(label);
                                    span.appendChild(a);
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }
    });
});
"
                    );
                }

                $PAGE->requires->js_amd_inline("window.plagiarism_check_data.push(" . json_encode($jsdata) . ")");

                return '
                <span class="plagiarism-pchkorg-widget plagiarism-pchkorg-widget-id-' . $filerecord->id . '"></span>';
            } else if ($filerecord->state == plagiarism_pchkorg_state::LOCAL_QUEUED) {
                $label = get_string('pchkorg_label_queued', 'plagiarism_pchkorg');
                return '
                <span style="padding: 5px 3px;
text-decoration: none;
background-color: #eeeded;
color: black;
border-radius: 3px 3px 3px 3px;
margin: 4px;
display: inline-block;"
            href="#" class="plagiarism_pchkorg_report_id_score">
                <img src="' . $imgsrc . '" alt="logo" width="20" />
                ' . $label . '
            </span>';
            } else if ($filerecord->state == plagiarism_pchkorg_state::LOCAL_SENT) {
                $label = sprintf(get_string('pchkorg_label_sent', 'plagiarism_pchkorg'), $filerecord->textid);
                return '
                <span style="padding: 5px 3px;
text-decoration: none;
background-color: #eeeded;
color: black;
border-radius: 3px 3px 3px 3px;
margin: 4px;
display: inline-block;"
            href="#" class="plagiarism_pchkorg_report_id_score">
                <img src="' . $imgsrc . '" alt="logo" width="20" />
                ' . $label . '
            </span>';
            } else {
                return $this->exit_message(
                    sprintf(
                        '%s: [%s] %s',
                        get_string('pchkorg_debug_status_error', 'plagiarism_pchkorg'),
                        $filerecord->state,
                        empty($filerecord->message) ? '(empty)' : $filerecord->message
                    ),
                    $isdebugenabled
                );
            }
        }

        return $this->exit_message(
            sprintf(
                '%s (%s)',
                get_string('pchkorg_debug_no_check', 'plagiarism_pchkorg'),
                $cmid
            ),
            $isdebugenabled
        );
    }

    /**
     * Render message with reason why do we stop plugin.
     *
     * @param string $message - exit message
     * @param bool $debug - is debug enabled.
     * @return string
     */
    private function exit_message($message, $debug) {
        if ($debug) {
            return $message;
        }

        return '';
    }

    /**
     * hook to allow a disclosure to be printed notifying users what will happen with their submission.
     *
     * @param int $cmid - course module id
     * @return string
     */
    public function print_disclosure($cmid) {
        global $OUTPUT;

        if (empty($cmid)) {
            return '';
        }
        // Get course details.
        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            return '';
        }

        $configmodel = new plagiarism_pchkorg_config_model();
        $enabled = $configmodel->get_system_config('pchkorg_use');
        if ($enabled !== '1') {
            return '';
        }
        $modulename = $cm->modname;
        $allowedmodules = ['assign', 'mod_assign'];
        if ($configmodel->get_system_config('pchkorg_enable_quiz')) {
            $allowedmodules[] = 'quiz';
        }
        if ($configmodel->get_system_config('pchkorg_enable_forum')) {
            $allowedmodules[] = 'forum';
        }
        if (!in_array($modulename, $allowedmodules, true)) {
            return '';
        }

        if (!$configmodel->is_enabled_for_module($cmid)) {
            return '';
        }

        $result = '';

        $result .= $OUTPUT->box_start('generalbox boxaligncenter', 'intro');

        $formatoptions = new stdClass();
        $formatoptions->noclean = true;

        $result .= '<div style="background-color: #d5ffd5; padding: 10px; border: 1px solid #b7dab7">';
        $result .= format_text(get_string('pchkorg_disclosure', 'plagiarism_pchkorg'), FORMAT_MOODLE, $formatoptions);
        $result .= '</div>';
        $result .= $OUTPUT->box_end();

        return $result;
    }

    /**
     *
     * Method will handle event assessable_uploaded.
     *
     * @param $eventdata
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests; built from config otherwise.
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    public function event_handler($eventdata, $apiprovider = null) {
        global $USER, $DB;

        // Whitelist of supported events, ignore other.
        $issupportedevent = in_array($eventdata['eventtype'], [
            "forum_attachment",
            "quiz_submitted",
            "assessable_submitted",
            "content_uploaded",
        ]);
        if (!$issupportedevent) {
            return true;
        }

        $modulename = $eventdata['other']['modulename'];
        $allowedmodules = ['assign', 'mod_assign'];
        // We support only assign module so just ignore all other.
        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        if (null === $apiprovider) {
            // Token is needed for API auth.
            $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
            $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);
        }
        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }
        if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_quiz')) {
            $allowedmodules[] = 'quiz';
        }
        if ('1' === $pchkorgconfigmodel->get_system_config('pchkorg_enable_forum')) {
            $allowedmodules[] = 'forum';
        }
        if (!in_array($modulename, $allowedmodules, true)) {
            return true;
        }

        // Receive couser moudle id.
        $cmid = $eventdata['contextinstanceid'];
        // Remove the event if the course module no longer exists.
        $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $cmid);
        $context = context_module::instance($cm->id);
        if (!$cm) {
            return true;
        }

        // SQL will be called only once per page. There is static result inside.
        // Plugin is enabled for this module.
        if (!$pchkorgconfigmodel->is_enabled_for_module($cm->id)) {
            return true;
        }

        // Only for some type of account, method will call a remote HTTP API.
        // The API will be called only once, because result is static.
        // Also, there is timeout 8 seconds for response.
        // Even if service is unavailable, method will try call only once.
        // Also, we don't use raw users email.
        $servicelogin = plagiarism_pchkorg_service_login::resolve($apiprovider, $USER, $pchkorgconfigmodel);
        $ismemberresponse = $servicelogin->member;
        $ismember = true;
        if (!$ismemberresponse->is_known) {
            // The service could not be reached or answered with something we
            // could not parse. We don't know whether this user is a member,
            // so queue the submission rather than fail it outright: the send
            // step retries on transient failures and can reject it later
            // with a concrete reason if the user genuinely is not a member.
            $ismember = true;
        } else if (!$ismemberresponse->is_member) {
            if ($ismemberresponse->is_auto_registration_enabled) {
                $name = $USER->firstname . ' ' . $USER->lastname;
                // Moodle has multiple roles in courses.
                $isstudent = plagiarism_pchkorg_roles::is_student($context, $USER->id);
                // Registered under whichever identity the lookup just failed to
                // find them by, so the account created here is the one the next
                // membership check will look for.
                $isregistered = $apiprovider->auto_registrate_member(
                    $name,
                    $servicelogin->login,
                    $isstudent ? 3 : 2,
                    plagiarism_pchkorg_service_login::additional_email($USER, $servicelogin->login)
                );
                if (!$isregistered) {
                    $ismember = false;
                }
            } else {
                $ismember = false;
            }
        }

        // Set the author and submitter.
        $submitter = $eventdata['userid'];

        // Related user ID is NULL when an instructor submits on behalf of a student in a
        // group. The intent was to look the group up and attribute the submission to one of
        // its students, but that was never finished: the record was fetched and discarded,
        // and the helper meant to pick the author was unreachable. Such submissions are
        // therefore attributed to the submitting instructor.

        if (
            $eventdata['other']['modulename'] === 'forum'
            && $eventdata['eventtype'] === 'forum_attachment'
        ) {
            if (!empty($eventdata['other']['content'])) {
                $content = trim(strip_tags($eventdata['other']['content']));
                if (strlen($content) > 80) {
                    $signature = sha1($content);
                    $filesconditions = [
                        'signature' => $signature,
                        'cm' => $cmid,
                        'userid' => $USER->id,
                        'itemid' => $eventdata['objectid'],
                    ];
                    $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                    if (!$oldfile) {
                        $filerecord = new \stdClass();
                        $filerecord->fileid = null;
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;
                        if ($ismember) {
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
                        } else {
                            $filerecord->message = self::truncate_message(sprintf(
                                'User %s is not a member of group',
                                $USER->email
                            ));
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                        }

                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                }
            }
            if (!empty($eventdata['other']['pathnamehashes'])) {
                foreach ($eventdata['other']['pathnamehashes'] as $pathnamehash) {
                    $file = get_file_storage()->get_file_by_hash($pathnamehash);
                    if (!$file) {
                        // We can not find file so we do not send it in queue.
                        continue;
                    } else {
                        try {
                            // Check that we can fetch content without exception.
                            $content = $file->get_content();
                        } catch (Exception $e) {
                            // No we can not.
                            continue;
                        }
                    }
                    if ($file->get_filename() === '.') {
                        continue;
                    }
                    $filemime = $file->get_mimetype();

                    // File type is not supported.
                    if (!$apiprovider->is_supported_mime($filemime)) {
                        continue;
                    }
                    $signature = sha1($content);
                    $filesconditions = [
                        'fileid' => $file->get_id(),
                    ];
                    $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                    if (!$oldfile) {
                        $filerecord = new \stdClass();
                        $filerecord->fileid = $file->get_id();
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;
                        if ($ismember) {
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
                        } else {
                            $filerecord->message = self::truncate_message(sprintf(
                                'User %s is not a member of group',
                                $USER->email
                            ));
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                        }

                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                }
            }

            return true;
        }

        if (
            $eventdata['other']['modulename'] === 'quiz'
            && $eventdata['eventtype'] === 'quiz_submitted'
        ) {
            $attempt = quiz_attempt::create($eventdata['objectid']);
            foreach ($attempt->get_slots() as $slot) {
                $questionattempt = $attempt->get_question_attempt($slot);
                $qtype = $questionattempt->get_question()->qtype;
                if ($qtype instanceof qtype_essay) {
                    $attachments = $questionattempt->get_last_qt_files('attachments', $eventdata['contextid']);
                    $content = $questionattempt->get_response_summary();
                    if (strlen($content) > 80) {
                        $signature = sha1($content);
                        $filesconditions = [
                            'signature' => $signature,
                            'cm' => $cmid,
                            'userid' => $USER->id,
                            'itemid' => $eventdata['objectid'],
                        ];

                        $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                        if ($oldfile) {
                            // There is the same check in database, so we can skip this one.
                            return true;
                        }

                        $filerecord = new \stdClass();
                        $filerecord->fileid = null;
                        $filerecord->cm = $cmid;
                        $filerecord->userid = $USER->id;
                        $filerecord->textid = null;
                        $filerecord->created_at = time();
                        $filerecord->itemid = $eventdata['objectid'];
                        $filerecord->signature = $signature;
                        if ($ismember) {
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
                        } else {
                            $filerecord->message = self::truncate_message(sprintf(
                                'User %s is not a member of group',
                                $USER->email
                            ));
                            $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                        }
                        $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                    }
                    foreach ($attachments as $pathnamehash => $file) {
                        if (!$file) {
                            // We can not find file so we do not send it in queue.
                            continue;
                        } else {
                            try {
                                // Check that we can fetch content without exception.
                                $content = $file->get_content();
                            } catch (Exception $e) {
                                // No we can not.
                                continue;
                            }
                        }
                        if ($file->get_filename() === '.') {
                            continue;
                        }
                        $filemime = $file->get_mimetype();

                        // File type is not supported.
                        if (!$apiprovider->is_supported_mime($filemime)) {
                            continue;
                        }
                        $signature = sha1($content);
                        $filesconditions = [
                            'fileid' => $file->get_id(),
                        ];
                        $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
                        if (!$oldfile) {
                            $filerecord = new \stdClass();
                            $filerecord->fileid = $file->get_id();
                            $filerecord->cm = $cmid;
                            $filerecord->userid = $USER->id;
                            $filerecord->textid = null;
                            $filerecord->created_at = time();
                            $filerecord->itemid = $eventdata['objectid'];
                            $filerecord->signature = $signature;
                            if ($ismember) {
                                $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
                            } else {
                                $filerecord->message = self::truncate_message(sprintf(
                                    'User %s is not a member of group',
                                    $USER->email
                                ));
                                $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                            }
                            $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                        }
                    }
                }
            }
        }

        // Get actual text content and files to be submitted for draft submissions.
        // As this won't be present in eventdata for certain event types.
        if (
            $eventdata['other']['modulename'] === 'assign'
            && $eventdata['eventtype'] === 'assessable_submitted'
        ) {
            // Get content.
            $moodlesubmission = $DB->get_record('assign_submission', ['id' => $eventdata['objectid']], 'id');

            $moodletextsubmission = $DB->get_record(
                'assignsubmission_onlinetext',
                ['submission' => $eventdata['objectid']],
                'onlinetext'
            );

            if ($moodletextsubmission) {
                $eventdata['other']['content'] = $moodletextsubmission->onlinetext;
            }

            $filesconditions = [
                'component' => 'assignsubmission_file',
                'itemid' => $eventdata['objectid'],
                'userid' => $eventdata['userid'],
            ];

            $moodlefiles = $DB->get_records('files', $filesconditions);
            if ($moodlefiles) {
                $fs = get_file_storage();
                foreach ($moodlefiles as $filedb) {
                    $file = $fs->get_file_by_id($filedb->id);

                    if (!$file) {
                        // We can not find file so we do not send it in queue.
                        continue;
                    } else {
                        try {
                            // Check that we can fetch content without exception.
                            $content = $file->get_content();
                        } catch (Exception $e) {
                            // No we can not.
                            continue;
                        }
                    }

                    if ($file->get_filename() === '.') {
                        continue;
                    }
                    $filemime = $file->get_mimetype();

                    // File type is not supported.
                    if (!$apiprovider->is_supported_mime($filemime)) {
                        continue;
                    }

                    $filerecord = new \stdClass();
                    $filerecord->fileid = $file->get_id();
                    $filerecord->cm = $cmid;
                    $filerecord->userid = $USER->id;
                    $filerecord->textid = null;
                    $filerecord->created_at = time();
                    $filerecord->itemid = $eventdata['objectid'];
                    $filerecord->signature = sha1($content);
                    if ($ismember) {
                        $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
                    } else {
                        $filerecord->message = self::truncate_message(sprintf(
                            'User %s is not a member of group',
                            $USER->email
                        ));
                        $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                    }
                    $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
                }
            }
        }

        // Queue text content to send to plagiarismcheck.org.
        // If there was an error when creating the assignment then still queue the submission so it can be saved as failed.
        if (
            $eventdata['other']['modulename'] === 'assign'
            && in_array($eventdata['eventtype'], ["content_uploaded", "assessable_submitted"])
            && !empty($eventdata['other']['content'])
        ) {
            $signature = sha1($eventdata['other']['content']);

            $filesconditions = [
                'signature' => $signature,
                'cm' => $cmid,
                'userid' => $USER->id,
                'itemid' => $eventdata['objectid'],
            ];

            $oldfile = $DB->get_record('plagiarism_pchkorg_files', $filesconditions);
            if ($oldfile) {
                // There is the same check in database, so we can skip this one.
                return true;
            }

            $filerecord = new \stdClass();
            $filerecord->fileid = null;
            $filerecord->cm = $cmid;
            $filerecord->userid = $USER->id;
            $filerecord->textid = null;
            $filerecord->created_at = time();
            $filerecord->itemid = $eventdata['objectid'];
            $filerecord->signature = $signature;
            if ($ismember) {
                $filerecord->state = plagiarism_pchkorg_state::LOCAL_QUEUED;
            } else {
                $filerecord->message = self::truncate_message(sprintf(
                    'User %s is not a member of group',
                    $USER->email
                ));
                $filerecord->state = plagiarism_pchkorg_state::LOCAL_ERROR;
            }
            $DB->insert_record('plagiarism_pchkorg_files', $filerecord);
        }

        return true;
    }

    /**
     * Register teachers of plugin-enabled courses with the service.
     *
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests; built from config otherwise.
     * @return bool
     * @throws moodle_exception When the service could not confirm the auto-registration
     *                           setting for a user, so Moodle records this task run as failed
     *                           instead of silently succeeding.
     */
    public function cron_auto_registrate_teachers($apiprovider = null) {
        global $DB;

        // Cron shares one process across runs, so a resolution cached for a
        // previous run's user would still be here. A web request is short enough
        // for these caches to be safe; this is not.
        plagiarism_pchkorg_service_login::reset_cache();
        plagiarism_pchkorg_api_provider::reset_caches();

        $configmodel = new plagiarism_pchkorg_config_model();
        $enabled = $configmodel->get_system_config('pchkorg_use');
        $isdebugenabled = $configmodel->get_system_config('pchkorg_enable_debug') === '1';
        $apitoken = $configmodel->get_system_config('pchkorg_token');

        // Plugin is disabled.
        if ($enabled !== '1') {
            if ($isdebugenabled) {
                echo 'cron_auto_registrate_teachers: Plugin is disabled';
            }
            return true;
        }
        $isfeatureenabled = $configmodel->get_system_config('pchkorg_teacher_auto_registration');
        // This feature is disabled.
        if ($isfeatureenabled !== '1') {
            if ($isdebugenabled) {
                echo 'cron_auto_registrate_teachers: This feature is disabled.';
            }
            return true;
        }

        // Api token is empty.
        if (empty($apitoken)) {
            if ($isdebugenabled) {
                echo 'cron_auto_registrate_teachers: Api token is empty.';
            }
            return true;
        }
        if (null === $apiprovider) {
            $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);
        }

        // This SQL fetches all teachers in courses where plugin is enabled,
        // and which have not been imported yet.
        //
        // Everything here is written for cross-database portability: LIMIT and
        // CONCAT are MySQL spellings, string columns must not be compared to
        // integers, and ordering by a column that is neither selected nor
        // grouped is rejected outside MySQL.
        [$rolesql, $roleparams] = $DB->get_in_or_equal(
            plagiarism_pchkorg_roles::teacher_shortnames(),
            SQL_PARAMS_NAMED,
            'role'
        );
        $namesql = $DB->sql_concat('u.firstname', "' '", 'u.lastname');

        // On a course-scoped site a teacher is bookkept under their namespaced
        // login, so the "already handled" guard has to recognise both strings or
        // turning the setting on would re-register every teacher on the site.
        //
        // The prefix is inlined as a literal rather than bound: sql_concat()
        // builds an expression, and Postgres cannot infer the type of a
        // placeholder inside one, so a bound parameter fails there.
        $handledsql = 'pu.email = u.email';
        if (plagiarism_pchkorg_course_access::is_course_scoped($configmodel)) {
            $loginsql = $DB->sql_concat(
                "'" . plagiarism_pchkorg_service_login::site_prefix() . "'",
                'u.username'
            );
            $handledsql .= " OR pu.email = {$loginsql}";
        }

        $sql = "SELECT DISTINCT u.id, u.email, u.username, {$namesql} AS name
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctxcourse ON ctxcourse.id = ra.contextid
                       AND ctxcourse.contextlevel = :coursecontext
                 WHERE u.deleted = 0
                   AND r.shortname {$rolesql}
                   AND e.courseid IN (
                       SELECT c.id
                         FROM {assign} a
                         JOIN {course_modules} cm ON cm.instance = a.id
                         JOIN {modules} m ON m.id = cm.module
                         JOIN {plagiarism_pchkorg_config} pc ON pc.cm = cm.id
                         JOIN {course} c ON c.id = cm.course AND a.course = c.id
                        WHERE pc.value = :enabledvalue
                          AND pc.name = :configname
                          AND m.name = :modname
                   )
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {plagiarism_pchkorg_users} pu
                        WHERE {$handledsql}
                   )
              ORDER BY u.id";

        $params = array_merge($roleparams, [
            'coursecontext' => CONTEXT_COURSE,
            'enabledvalue' => '1',
            'configname' => 'pchkorg_module_use',
            'modname' => 'assign',
        ]);

        $records = $DB->get_records_sql($sql, $params, 0, 50);
        $iscoursescoped = plagiarism_pchkorg_course_access::is_course_scoped($configmodel);
        foreach ($records as $record) {
            // What makes a teacher registerable depends on what identifies them.
            // On a course-scoped site that is their username, so a junk address
            // is no longer a reason to skip them -- they simply get no delivery
            // address. Everywhere else the address is the identity and still has
            // to look like one.
            if ($iscoursescoped ? empty($record->username) : \strpos($record->email, '@') === false) {
                if ($isdebugenabled) {
                    echo 'cron_auto_registrate_teachers: Email format is invalid.';
                }
                continue;
            }
            $servicelogin = plagiarism_pchkorg_service_login::resolve($apiprovider, $record, $configmodel);
            $member = $servicelogin->member;
            if (!$member->is_known) {
                // The service could not be reached or answered with
                // something we could not parse. We do not know whether
                // auto-registration is enabled, so leave the administrator's
                // setting untouched and stop this run rather than guess.
                if ($isdebugenabled) {
                    echo sprintf(
                        'cron_auto_registrate_teachers: Could not confirm auto-registration status for %s.',
                        $record->email
                    );
                }
                throw new moodle_exception('pchkorg_auto_registration_unavailable', 'plagiarism_pchkorg');
            }
            // Auto-registration is off for this group. Stop here and disable this feature site-wide,
            // since the setting is a property of the group, not of the individual user.
            if (!$member->is_auto_registration_enabled) {
                if ($isdebugenabled) {
                    echo 'cron_auto_registrate_teachers: Auto-registration is disabled for this group.';
                }
                set_config('pchkorg_teacher_auto_registration', '0', 'plagiarism_pchkorg');
                $configmodel->set_system_config('pchkorg_teacher_auto_registration', '0');
                return false;
            }
            // User is already registered. The bookkeeping row records the
            // identifier actually used, not always the address: it is what the
            // NOT EXISTS guard above matches on, so storing the wrong one of the
            // two would bring this teacher back on the next run forever.
            if ($member->is_member) {
                $insertdata = new \stdClass();
                $insertdata->email = $servicelogin->login;
                $DB->insert_record('plagiarism_pchkorg_users', $insertdata);
            } else {
                // Which role a teacher is registered under depends on how this
                // site scopes report access. On an institution-wide site they
                // are registered as a teacher, as they always have been. On a
                // course-scoped site they are registered as a student, so that
                // the per-course grants written when they open a report are the
                // only thing carrying their teaching rights.
                $success = $apiprovider->auto_registrate_member(
                    $record->name,
                    $servicelogin->login,
                    plagiarism_pchkorg_course_access::registration_role($configmodel),
                    plagiarism_pchkorg_service_login::additional_email($record, $servicelogin->login)
                );
                // Operation is successful.
                if ($success) {
                    $insertdata = new \stdClass();
                    $insertdata->email = $servicelogin->login;
                    $DB->insert_record('plagiarism_pchkorg_users', $insertdata);
                }
            }
        }

        return true;
    }

    /**
     *
     * Method will be called by cron. Method sends queued files into plagiarism check system.
     *
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests; built from config otherwise.
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    public function cron_send_submissions($apiprovider = null) {
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        if (null === $apiprovider) {
            $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
            $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);
        }

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }
        $filesconditions = ['state' => plagiarism_pchkorg_state::LOCAL_QUEUED];
        $moodlefiles = $DB->get_records(
            'plagiarism_pchkorg_files',
            $filesconditions,
            'id',
            '*',
            0,
            20
        );

        if (!$moodlefiles) {
            return true;
        }

        // One query for every author in the batch, rather than one per record.
        $userids = [];
        foreach ($moodlefiles as $filedb) {
            $userids[$filedb->userid] = $filedb->userid;
        }
        $users = $DB->get_records_list('user', 'id', $userids);

        // The agreement is site-wide, so it is settled once for the whole run.
        $this->accept_agreement_once($apiprovider, $users, $pchkorgconfigmodel);

        $sender = new plagiarism_pchkorg_sender($apiprovider, $pchkorgconfigmodel);

        foreach ($moodlefiles as $filedb) {
            $user = array_key_exists($filedb->userid, $users) ? $users[$filedb->userid] : null;
            $cm = get_coursemodule_from_id('', $filedb->cm);

            // The author or the course module may have been deleted since the
            // record was queued. Neither can be recovered, so fail the item and
            // move on rather than fataling and stalling the whole batch.
            if (!$user || !$cm) {
                $this->fail_submission($filedb);
                continue;
            }

            $result = $sender->send($filedb, $cm, $user);
            $this->record_send_result($filedb, $result, $apiprovider);
        }

        return true;
    }

    /**
     * Tell the service the agreement was accepted, once per site.
     *
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @param array $users Users in the current batch, for the acting identity.
     * @param plagiarism_pchkorg_config_model|null $configmodel Injected by tests.
     * @return void
     */
    private function accept_agreement_once($apiprovider, array $users, $configmodel = null) {
        global $DB;

        $agreementwhere = [
            'cm' => 0,
            'name' => 'accepted_agreement',
            'value' => '1',
        ];
        if ($DB->record_exists('plagiarism_pchkorg_config', $agreementwhere)) {
            return;
        }

        $user = reset($users);
        if (!$user) {
            return;
        }

        $servicelogin = plagiarism_pchkorg_service_login::resolve($apiprovider, $user, $configmodel);
        $apiprovider->save_accepted_agreement($servicelogin->login);
        $DB->insert_record('plagiarism_pchkorg_config', $agreementwhere);
    }

    /**
     * Mark a queued record as permanently failed.
     *
     * @param stdClass $filedb
     * @return void
     */
    private function fail_submission($filedb) {
        global $DB;

        $filedbnew = new stdClass();
        $filedbnew->id = $filedb->id;
        $filedbnew->attempt = $filedb->attempt + 1;
        $filedbnew->state = plagiarism_pchkorg_state::LOCAL_ERROR;

        $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
    }

    /**
     * Apply the outcome of one send attempt to the queue record.
     *
     * @param stdClass $filedb
     * @param stdClass $result From plagiarism_pchkorg_sender::send().
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @return void
     */
    private function record_send_result($filedb, $result, $apiprovider) {
        global $DB;

        if (plagiarism_pchkorg_sender::RESULT_FAILED === $result->status) {
            $this->fail_submission($filedb);
            return;
        }

        $filedbnew = new stdClass();
        $filedbnew->id = $filedb->id;

        if (plagiarism_pchkorg_sender::RESULT_SENT === $result->status) {
            // Text was successfully sent to the service.
            $filedbnew->textid = $result->textid;
            $filedbnew->state = plagiarism_pchkorg_state::LOCAL_SENT;
            $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
            return;
        }

        $filedbnew->attempt = $filedb->attempt + 1;
        // When more than 6 attempts or we know concrete reason of failure.
        // There is no reasone to future attempt.
        $lasterrormessage = $apiprovider->get_last_error();
        if (!empty($lasterrormessage)) {
            $apiprovider->set_last_error(null);
            $filedbnew->message = self::truncate_message($lasterrormessage);
            $filedbnew->state = plagiarism_pchkorg_state::LOCAL_ERROR;
        }
        if ($filedbnew->attempt > 6) {
            $filedbnew->state = plagiarism_pchkorg_state::LOCAL_ERROR;
        }

        $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
    }

    /**
     * Method will update similarity score and change status of checks.
     *
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests; built from config otherwise.
     * @return bool
     * @throws dml_exception
     */
    public function cron_update_reports($apiprovider = null) {
        global $DB;

        $pchkorgconfigmodel = new plagiarism_pchkorg_config_model();
        if (null === $apiprovider) {
            $apitoken = $pchkorgconfigmodel->get_system_config('pchkorg_token');
            $apiprovider = new plagiarism_pchkorg_api_provider($apitoken);
        }

        // SQL will be called only once, result is static.
        $config = $pchkorgconfigmodel->get_system_config('pchkorg_use');
        if ('1' !== $config) {
            return true;
        }

        $filesconditions = ['state' => plagiarism_pchkorg_state::LOCAL_SENT];

        $moodlefiles = $DB->get_records(
            'plagiarism_pchkorg_files',
            $filesconditions,
            'id',
            '*',
            0,
            20
        );

        foreach ($moodlefiles as $filedb) {
            $report = $apiprovider->check_text($filedb->textid);
            if ($report !== null) {
                $filedbnew = new stdClass();
                $filedbnew->id = $filedb->id;
                $filedbnew->reportid = $report->id;
                // successful check, all good.
                if (plagiarism_pchkorg_state::REMOTE_CHECKED === $report->state) {
                    $filedbnew->state = plagiarism_pchkorg_state::LOCAL_CHECKED;
                    // The score column is NOT NULL, so fall back to 0 when the service
                    // reports a checked text without a similarity percentage. scoreai is
                    // nullable and stays null when AI detection did not run.
                    $filedbnew->score = null === $report->percent ? 0 : $report->percent;
                    $filedbnew->scoreai = $report->percent_ai;
                } else {
                    // Check has been failed for some reason. We cannot check this document.
                    // We can mark this queue-item as failed and move to the next document.
                    // LOCAL_ERROR will fail document in queue and hide it from the UI.
                    $filedbnew->state = plagiarism_pchkorg_state::LOCAL_ERROR;
                }

                $DB->update_record('plagiarism_pchkorg_files', $filedbnew);
            }
        }
    }
}
