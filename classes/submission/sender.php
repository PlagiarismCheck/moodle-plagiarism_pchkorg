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
 * Sends one queued submission to the service.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../assignment_key.php');
require_once(__DIR__ . '/../site_version.php');
require_once(__DIR__ . '/assign_resolver.php');
require_once(__DIR__ . '/quiz_resolver.php');
require_once(__DIR__ . '/forum_resolver.php');

/**
 * Sends one queued record to the service.
 *
 * The three activity types differ only in how content is located, which is the
 * resolvers' job. Filter assembly, credentials and the decision of what the
 * outcome means all live here so they cannot drift apart per activity.
 */
class plagiarism_pchkorg_sender {
    /** @var string Accepted by the service; record it as sent. */
    const RESULT_SENT = 'sent';

    /** @var string Nothing to send yet; leave queued and try again later. */
    const RESULT_RETRY = 'retry';

    /** @var string Unrecoverable; the record can never succeed. */
    const RESULT_FAILED = 'failed';

    /** @var plagiarism_pchkorg_api_provider */
    private $apiprovider;

    /** @var plagiarism_pchkorg_config_model */
    private $configmodel;

    /** @var array Filters per course module id, built once per run. */
    private $filtercache = [];

    /**
     * Build a sender.
     *
     * @param plagiarism_pchkorg_api_provider $apiprovider
     * @param plagiarism_pchkorg_config_model $configmodel
     */
    public function __construct($apiprovider, $configmodel) {
        $this->apiprovider = $apiprovider;
        $this->configmodel = $configmodel;
    }

    /**
     * Resolver for an activity type, or null when it is not supported.
     *
     * @param string $modname
     * @return plagiarism_pchkorg_resolver|null
     */
    public static function resolver_for($modname) {
        switch ($modname) {
            case 'assign':
                return new plagiarism_pchkorg_assign_resolver();
            case 'quiz':
                return new plagiarism_pchkorg_quiz_resolver();
            case 'forum':
                return new plagiarism_pchkorg_forum_resolver();
            default:
                return null;
        }
    }

    /**
     * Send one queued record.
     *
     * @param stdClass $filedb Queue record.
     * @param stdClass $cm Course module.
     * @param stdClass $user Submitting user.
     * @return stdClass With status (one of the RESULT_* constants) and textid.
     */
    public function send($filedb, $cm, $user) {
        $resolver = self::resolver_for($cm->modname);
        if (null === $resolver) {
            // Nothing can ever handle this record.
            return $this->result(self::RESULT_FAILED);
        }

        if (null === $filedb->fileid) {
            $payload = $resolver->resolve_text($filedb, $cm);
        } else {
            $file = get_file_storage()->get_file_by_id($filedb->fileid);
            // The file is gone, so this record can never be sent.
            if (!$file || !is_object($file)) {
                return $this->result(self::RESULT_FAILED);
            }
            $payload = $resolver->resolve_file($filedb, $cm, $file);
        }

        if (null === $payload) {
            return $this->result(self::RESULT_RETRY);
        }

        $textid = $this->apiprovider->general_send_check(
            $this->apiprovider->user_email_to_hash($user->email),
            $cm->course,
            $cm->id,
            $cm->name,
            $payload->submissionid,
            $payload->attachmentid,
            $payload->content,
            $payload->mime,
            $payload->filename,
            $this->filters_for($cm),
            $user->email
        );

        if (!$textid) {
            return $this->result(self::RESULT_RETRY);
        }

        return $this->result(self::RESULT_SENT, $textid);
    }

    /**
     * Search filters for an activity, built once per course module per run.
     *
     * @param stdClass $cm
     * @return array
     */
    private function filters_for($cm) {
        if (array_key_exists($cm->id, $this->filtercache)) {
            return $this->filtercache[$cm->id];
        }

        $filters = [
            'include_references' => $this->configmodel->get_filter_for_module(
                $cm->id,
                'pchkorg_include_referenced'
            ),
            'include_quotes' => $this->configmodel->get_filter_for_module(
                $cm->id,
                'pchkorg_include_citation'
            ),
            'exclude_self_plagiarism' => $this->configmodel->get_filter_for_module(
                $cm->id,
                'pchkorg_exclude_self_plagiarism'
            ),
        ];

        // AI detection. The per-activity setting wins over the site-wide one,
        // and anything but an explicit 0 means on, so a site that has never
        // saved either keeps having AI detection run.
        //
        // Sent unconditionally, exactly like the threshold below: the service
        // stores the last value it was told for an activity and has no other
        // way to hear that a teacher switched AI detection off, so leaving the
        // field out would keep it running on every future submission.
        $checkai = $this->configmodel->get_filter_for_module($cm->id, 'pchkorg_check_ai');
        if (null === $checkai) {
            $checkai = $this->configmodel->get_system_config('pchkorg_check_ai');
        }
        $filters['enable_ai_detection'] = ('0' === (string) $checkai) ? '0' : '1';

        // Ignore templates. The key goes out for every activity type, quiz and
        // forum included, because it is built from a course module id and the
        // service treats it as opaque.
        //
        // It goes out even when the feature is switched off, so that turning it
        // on later applies the activity's templates to work checked in the
        // meantime; the flag carries the on/off state separately.
        $filters['assignment_key'] = plagiarism_pchkorg_assignment_key::for_cmid($cm->id);
        $filters['ignore_templates_enabled'] =
            '1' === $this->configmodel->get_system_config('pchkorg_enable_ignore_templates') ? '1' : '0';

        // Which releases are still in the field, so support for old ones can be
        // dropped on evidence. Both are site facts rather than search filters,
        // and ride here only because this is what reaches both send paths. A
        // site that cannot report a version sends null, which the body builders
        // drop, and is checked exactly as before.
        $filters['moodle_version'] = plagiarism_pchkorg_site_version::moodle_major();
        $filters['plugin_version'] = plagiarism_pchkorg_site_version::plugin_release();

        // The per-activity threshold wins over the site-wide one. The test is
        // for a stored value rather than a truthy one: a stored 0 is a teacher
        // saying this activity filters nothing, and must beat a site-wide
        // threshold rather than be mistaken for having set nothing at all.
        $minpercent = $this->configmodel->get_filter_for_module($cm->id, 'pchkorg_min_percent');
        if (null === $minpercent) {
            $minpercent = $this->configmodel->get_system_config('pchkorg_min_percent');
        }

        // Sent unconditionally, 0 included. The service stores the last value
        // it was told for an activity and has no other way to hear that a
        // threshold changed, so leaving the field out would keep the previous
        // one filtering every future submission.
        $filters['source_min_percent'] = (int) $minpercent;

        $this->filtercache[$cm->id] = $filters;

        return $filters;
    }

    /**
     * Build a result object.
     *
     * @param string $status
     * @param int|null $textid
     * @return stdClass
     */
    private function result($status, $textid = null) {
        $result = new stdClass();
        $result->status = $status;
        $result->textid = $textid;

        return $result;
    }
}
