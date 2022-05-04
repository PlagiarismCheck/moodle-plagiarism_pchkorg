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
$string['privacy:metadata:plagiarism_pchkorg_files:created_at'] = 'Date and time when document was saved.';
$string['privacy:metadata:plagiarism_pchkorg_files:textid'] = 'Identity of originality check';
$string['privacy:metadata:plagiarism_pchkorg_files:reportid'] = 'Identity of originality report';
$string['privacy:metadata:plagiarism_pchkorg_files:signature'] = 'Sha1 signature of content';
$string['privacy:metadata:plagiarism_pchkorg_files:attempt'] = 'Amount of sending attempts';
$string['privacy:metadata:plagiarism_pchkorg_files:itemid'] = 'Identity of submission';
$string['privacy:metadata:plagiarism_pchkorg_config'] = 'Table with module settings';
$string['privacy:metadata:plagiarism_pchkorg_config:cm'] = 'Course module identity';
$string['privacy:metadata:plagiarism_pchkorg_config:name'] = 'Name of option';
$string['privacy:metadata:plagiarism_pchkorg_config:value'] = 'Value of option';
$string['privacy:metadata:plagiarism_pchkorg'] = 'Service for originality check plagiarismcheck.org';
$string['privacy:metadata:plagiarism_pchkorg:file'] =
        'Submission attachment for originality checkprivacy:metadata:plagiarism_pchkorg';
$string['pchkorg:enable'] = 'Enable or Disable plugin';
$string['privacy:metadata:core_files'] = 'We need a content of submission, for originality check';
$string['sendqueuedsubmissions'] = '';
$string['updatereportscores'] = '';
$string['pchkorg_label_title'] = 'PlagiarismCheck.org ID: %s; Similarity Score: %s%%';
$string['pchkorg_label_result'] = 'ID: %s Similarity: %s%%';
$string['pchkorg_label_sent'] = 'ID: %s Sent';
$string['pchkorg_label_queued'] = 'In queue';
$string['pchkorg:enable'] = 'Allow to enable/disable PlagiarismCheck.org inside an activity';
$string['pchkorg:viewsimilarity'] = 'Allow to view similarity value from PlagiarismCheck.org';
$string['pchkorg:changeminpercentfilter'] = 'Allow changing "Exclude sources below X% similarity"';
