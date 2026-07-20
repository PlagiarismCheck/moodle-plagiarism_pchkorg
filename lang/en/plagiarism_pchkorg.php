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
 * English language strings.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pchkorg'] = 'PlagiarismCheck.org plugin';
$string['pluginname'] = 'PlagiarismCheck.org plugin';
$string['pchkorg_use'] = 'Enable plugin';
$string['pchkorg_use_help'] = 'Enable or Disable PlagiarismCheck.org plugin';
$string['pchkorg_module_use'] = 'Enable plugin in this module';
$string['pchkorg_module_use_help'] = 'Enable plugin in this module';
$string['pchkorg_token'] = 'API Token';
$string['pchkorg_token_help'] = 'You can receive your token by contact us';
$string['pchkorg_description'] = 'You can receive your token by contact us';
$string['pchkorg_submit'] = 'Submit';
$string['pchkorg_check_for_plagiarism_report'] = 'View report';
$string['savedconfigsuccess'] = 'Settings had been changed';
$string['pchkorg_check_for_plagiarism'] = 'Check for plagiarism';
$string['pchkorg_min_percent'] = 'Exclude sources below X% similarity';
$string['pchkorg_min_percent_help'] = 'Exclude sources below X% similarity';
$string['pchkorg_min_percent_range'] = 'Must be between 0 and 99';
$string['pchkorg_exclude_self_plagiarism'] = 'Exclude self-plagiarism';
$string['pchkorg_include_referenced'] = 'Include References';
$string['pchkorg_include_citation'] = 'Include Quotes';
$string['pchkorg_enable_debug'] = 'Enable debug information';
$string['pchkorg_enable_debug_help'] = 'This information can help you to understand why something not work';
$string['pchkorg_enable_quiz'] = 'Enable PlagiarismCheck in Quizzes';
$string['pchkorg_enable_forum'] = 'Enable PlagiarismCheck in Forum Activity';
$string['pchkorg_debug_mime'] = 'Mime of file is not supported';
$string['pchkorg_debug_disabled'] = 'Plugin is disabled';
$string['pchkorg_debug_empty_context'] = 'Plugin is not supported in this place';
$string['pchkorg_debug_user_has_no_permission'] = 'User has no moodle-permission to see this';
$string['pchkorg_debug_disabled_acitivity'] = 'Plugin is disabled for this activity';
$string['pchkorg_debug_not_member'] = 'User is not a member of plagiarismcheck group';
$string['pchkorg_debug_membership_unknown'] = 'Could not confirm group membership with PlagiarismCheck.org';
$string['pchkorg_debug_user_has_no_capability'] = 'User does not have capability';
$string['pchkorg_debug_no_check'] = 'There is no checks for this activity';
$string['pchkorg_debug_status_error'] = 'Some error for this file';
$string['pchkorg_debug_student_not_allowed_see_widget'] = 'Students can not see a similarity score';
$string['pchkorg_student_can_see_widget'] = 'Students can see a similarity score';
$string['pchkorg_student_can_see_report'] = 'Students can access a similarity report';
$string['pchkorg_check_ai'] = 'Enable AI Detector';
$string['pchkorg:teacherautoregistration'] = 'Enable Teacher auto-registration';
$string['pchkorg_disclosure'] = 'Submission will be sent to <a target="_blank" href="https://plagiarismcheck.org/">PlagiarismCheck.org</a> for check.
<br />
By submitting assignment I agree with <a target="_blank" href="https://plagiarismcheck.org/terms-of-service/">Terms &amp; Conditions</a>
 and <a target="_blank" href="https://plagiarismcheck.org/privacy-policy/">Privacy Policy</a>.';
$string['privacy:metadata:plagiarism_pchkorg_files'] =
        'Table with information about a file within moodle system belonge to a check in plagiarismcheck.org system.';
$string['privacy:metadata:plagiarism_pchkorg_files:cm'] = 'Course module identity ';
$string['privacy:metadata:plagiarism_pchkorg_files:fileid'] = 'Identity of a submitted file';
$string['privacy:metadata:plagiarism_pchkorg_files:userid'] = 'Identity of user who submit file';
$string['privacy:metadata:plagiarism_pchkorg_files:state'] = 'Status of a document. For example: queued, sent, checked.';
$string['privacy:metadata:plagiarism_pchkorg_files:score'] = 'Originality score';
$string['privacy:metadata:plagiarism_pchkorg_files:scoreai'] = 'Chat GPT score';
$string['privacy:metadata:plagiarism_pchkorg_files:created_at'] = 'Date and time when document was saved.';
$string['privacy:metadata:plagiarism_pchkorg_files:textid'] = 'Identity of originality check';
$string['privacy:metadata:plagiarism_pchkorg_files:reportid'] = 'Identity of originality report';
$string['privacy:metadata:plagiarism_pchkorg_files:signature'] = 'Sha1 signature of content';
$string['privacy:metadata:plagiarism_pchkorg_files:attempt'] = 'Amount of sending attempts';
$string['privacy:metadata:plagiarism_pchkorg_files:itemid'] = 'Identity of submission';
$string['privacy:metadata:plagiarism_pchkorg_files:message'] =
        'Reason why a document could not be checked. May contain the email address of the user who submitted it.';
$string['privacy:metadata:plagiarism_pchkorg_config'] = 'Table with module settings';
$string['privacy:metadata:plagiarism_pchkorg_config:cm'] = 'Course module identity';
$string['privacy:metadata:plagiarism_pchkorg_config:name'] = 'Name of option';
$string['privacy:metadata:plagiarism_pchkorg_config:value'] = 'Value of option';
$string['privacy:metadata:plagiarism_pchkorg'] =
    'Service for originality check plagiarismcheck.org. Data sent there is held by PlagiarismCheck.org under the '
    . 'institution\'s account and is not removed by deleting data in Moodle. Users with an account on the service can '
    . 'review or delete it from their own cabinet at https://plagiarismcheck.org/, or ask their institution.';
$string['privacy:metadata:plagiarism_pchkorg:file'] =
        'Submission attachment for originality checkprivacy:metadata:plagiarism_pchkorg';
$string['privacy:metadata:core_files'] = 'We need a content of submission, for originality check';
$string['sendqueuedsubmissions'] = 'Send texts to check';
$string['updatereportscores'] = 'Update check result';
$string['autoregistrateteachers'] = 'Auto registration for teachers';
$string['pchkorg_label_title'] = 'PlagiarismCheck.org ID: %s; Similarity Score: %s%%';
$string['pchkorg_label_result'] = 'ID: %s Similarity: %s%%';
$string['pchkorg_label_title_ai'] = 'PlagiarismCheck.org ID: %s; Similarity Score: %s%% AI: %s%%';
$string['pchkorg_label_result_ai'] = 'ID: %s Similarity: %s%% AI: %s%%';
$string['pchkorg_label_sent'] = 'ID: %s Sent';
$string['pchkorg_label_queued'] = 'In queue';
$string['pchkorg_report_open'] = 'Open the report';
$string['pchkorg_report_not_available'] = 'This originality report is not available.';
$string['pchkorg_report_not_allowed'] = 'You are not allowed to open this originality report.';
$string['pchkorg_auto_registration_unavailable'] =
    'PlagiarismCheck.org could not confirm the teacher auto-registration setting for a user, so this run was stopped '
    . 'without changing any configuration. This is usually transient; the task will try again on its next scheduled run.';
$string['pchkorg:enable'] = 'Allow to enable/disable PlagiarismCheck.org inside an activity';
$string['pchkorg:viewsimilarity'] = 'Allow to view similarity value from PlagiarismCheck.org';
$string['pchkorg:changeminpercentfilter'] = 'Allow changing "Exclude sources below X% similarity"';
$string['pchkorg:enabledbydefault'] = 'Enable PlagiarismCheck in Activities by default';

// Activity ignore templates.
$string['pchkorg_enable_ignore_templates'] = 'Enable activity template exclusion';
$string['pchkorg_enable_ignore_templates_help'] =
    'When enabled, teachers can attach template files or text to an activity. Text in a student submission that '
    . 'matches a template is excluded from the similarity and AI scores, so task descriptions, questions, rubrics '
    . 'and coversheets do not count against students. Templates are stored by PlagiarismCheck.org and require an '
    . 'institutional (group) API token. Changing templates affects submissions checked from then on; reports that '
    . 'already exist keep their current scores.';
$string['pchkorg_ignore_templates'] = 'Ignored activity templates';
$string['pchkorg_ignore_templates_help'] =
    'Text matching these templates is excluded from the similarity and AI scores of every submission to this '
    . 'activity, so shared wording such as the task description, a rubric or a coversheet does not count against '
    . 'students.'
    . "\n\n"
    . 'Attach the template either as a file or as pasted text — one or the other in a single save, not both. '
    . 'Changes apply to submissions checked from then on; reports that already exist keep their current scores.';
$string['pchkorg_ignore_template_intro'] =
    'Attach the wording every student is expected to repeat — the task description, a rubric, a coversheet or a '
    . 'question sheet. Matching text is then excluded from the similarity and AI scores of this activity.';
$string['pchkorg_ignore_template_create_note'] =
    'Templates you add here are attached once you save this activity.';
$string['pchkorg_ignore_template_count'] = 'Using {$a->used} of {$a->max} templates.';
$string['pchkorg_ignore_template_limit_reached'] =
    'You have reached the limit of {$a} templates. Delete one below before adding another.';
$string['pchkorg_ignore_template_delete_hint'] = 'Tick a template to delete it when you save this form.';
$string['pchkorg_ignore_template_add_files'] = 'Add template files';
$string['pchkorg_ignore_template_add_files_help'] =
    'Upload the documents students are expected to reuse. Each template must contain at least 20 words, and the '
    . 'accepted file types are listed under the upload box.'
    . "\n\n"
    . 'Upload files or paste text below, but not both in the same save.';
$string['pchkorg_ignore_template_types'] = 'Accepted file types';
$string['pchkorg_ignore_template_types_documents'] = 'Text documents';
$string['pchkorg_ignore_template_types_pdf'] = 'PDF';
$string['pchkorg_ignore_template_types_presentations'] = 'Presentations';
$string['pchkorg_ignore_template_paste_text'] = 'Paste template text';
$string['pchkorg_ignore_template_paste_text_help'] =
    'Use this instead of a file when the shared wording is short — a question, a set of instructions, a standard '
    . 'heading. The text must contain at least 20 words.'
    . "\n\n"
    . 'Paste text or upload files above, but not both in the same save.';
$string['pchkorg_ignore_template_paste_placeholder'] = 'Paste the wording students are expected to repeat…';
$string['pchkorg_ignore_template_existing'] = 'Existing templates';
$string['pchkorg_ignore_template_none'] = 'No templates are attached to this activity yet.';
$string['pchkorg_ignore_template_untitled'] = 'Untitled template';
$string['pchkorg_ignore_template_filename'] = 'File';
$string['pchkorg_ignore_template_filesize'] = 'Size';
$string['pchkorg_ignore_template_download'] = 'Download';
$string['pchkorg_ignore_template_delete'] = 'Delete';
$string['pchkorg_ignore_template_delete_named'] = 'Delete {$a}';
$string['pchkorg_ignore_template_delete_confirm'] = 'Delete this template? Its exclusions stop applying to future checks.';
$string['pchkorg_ignore_template_unavailable'] =
    'The template list could not be loaded from PlagiarismCheck.org. Your existing templates are unchanged.';
$string['pchkorg_ignore_template_saved'] = 'Activity templates updated.';

// Errors reported by the service, keyed by its error codes. The part after
// pchkorg_ignore_template_error_ is the code the service sends, so these keys
// cannot be renamed even where the wording talks about an activity.
$string['pchkorg_ignore_template_error_template_limit_exceeded'] = 'You can attach at most {$a} templates to an activity.';
$string['pchkorg_ignore_template_error_unsupported_extension'] =
    'Unsupported file type. Upload a text document (.doc, .docx, .odt, .rtf, .txt), a PDF (.pdf) or a presentation '
    . '(.ppt, .pptx, .odp).';
$string['pchkorg_ignore_template_error_invalid_mime_type'] =
    'That file is not a supported document. Upload a text document (.doc, .docx, .odt, .rtf, .txt), a PDF (.pdf) '
    . 'or a presentation (.ppt, .pptx, .odp).';
$string['pchkorg_ignore_template_error_file_too_large'] = 'That file is too large.';
$string['pchkorg_ignore_template_error_insufficient_extracted_text'] = 'A template must contain at least 20 words.';
$string['pchkorg_ignore_template_error_duplicate_template'] = 'That template is already attached to this activity.';
$string['pchkorg_ignore_template_error_files_and_text_conflict'] = 'Upload files or paste text, but not both in one save.';
$string['pchkorg_ignore_template_error_empty_template'] = 'The template is empty.';
$string['pchkorg_ignore_template_error_template_not_found'] = 'That template no longer exists.';
$string['pchkorg_ignore_template_error_template_assignment_mismatch'] = 'That template belongs to a different activity.';
$string['pchkorg_ignore_template_error_invalid_assignment_key'] = 'This activity could not be identified by PlagiarismCheck.org.';
$string['pchkorg_ignore_template_error_assignment_institution_mismatch'] =
    'This activity belongs to a different institution.';
$string['pchkorg_ignore_template_error_access_denied'] = 'You are not allowed to manage templates for this activity.';
$string['pchkorg_ignore_template_error_invalid_token'] = 'The PlagiarismCheck.org API token is invalid.';
$string['pchkorg_ignore_template_error_storage_error'] = 'The template could not be stored. Please try again.';
$string['pchkorg_ignore_template_error_template_processing_failed'] = 'The template could not be processed.';
$string['pchkorg_ignore_template_error_unreachable'] = 'PlagiarismCheck.org is unavailable. Please try again later.';

// Token validation, shown once when settings are saved.
$string['pchkorg_token_valid'] = 'The PlagiarismCheck.org API token is valid.';
$string['pchkorg_token_invalid'] = 'The PlagiarismCheck.org API token is invalid.';
$string['pchkorg_token_unavailable'] = 'The token could not be validated because PlagiarismCheck.org is unavailable.';
$string['privacy:metadata:plagiarism_pchkorg:email'] =
    'The email address of the user acting on an activity. It is normally sent as a one-way hash, which is enough for the '
    . 'service to confirm they belong to the institution. When auto-registration is enabled and the user does not yet '
    . 'have an account, the full address is sent instead, so that an account can be created for them.';
$string['privacy:metadata:plagiarism_pchkorg:name'] =
    'The first and last name of the user, sent only when auto-registration creates an account for them on the service';
$string['privacy:metadata:plagiarism_pchkorg:assignment_key'] =
    'An identifier for the Moodle activity, so submissions and ignored templates can be matched to it';
$string['privacy:metadata:plagiarism_pchkorg:template_filename'] = 'The file name of an uploaded activity template';
$string['privacy:metadata:plagiarism_pchkorg:template_content'] = 'The content of an uploaded or pasted activity template';

// The table remembering who has already been registered with the service.
$string['privacy:metadata:plagiarism_pchkorg_users'] =
    'Users who have already been registered with PlagiarismCheck.org, so that the auto-registration task does not send '
    . 'the same person again on every run.';
$string['privacy:metadata:plagiarism_pchkorg_users:email'] = 'The email address the user was registered under';

// Status of a check, in words, for a data export.
$string['privacy:state:queued'] = 'Queued to be sent';
$string['privacy:state:sent'] = 'Sent, waiting for the result';
$string['privacy:state:checked'] = 'Checked';
$string['privacy:state:error'] = 'Failed';
$string['privacy:state:unknown'] = 'Unknown';
