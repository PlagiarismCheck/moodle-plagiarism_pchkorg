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
 * Resolves an ignored template download to the bytes that should be sent.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/assignment_key.php');
require_once(__DIR__ . '/plagiarism_pchkorg_api_provider.php');
require_once(__DIR__ . '/plagiarism_pchkorg_config_model.php');

/**
 * Everything ignoretemplate.php does apart from the request itself.
 *
 * The script keeps require_login() and require_sesskey(), which only mean
 * something during a real request, and calls resolve() for the rest. That
 * leaves the authorization and lookup rules in a class that can be tested
 * without a web request and without send_file(), which exits.
 */
class plagiarism_pchkorg_ignore_template_download {
    /**
     * Capability required to download a template.
     *
     * The same one that gates the form section: whoever may edit the activity
     * may read its templates.
     */
    const CAPABILITY = 'moodle/course:manageactivities';

    /** Used when the service does not tell us what the file is. */
    const DEFAULT_MIME = 'application/octet-stream';

    /**
     * The file to send for one template of one activity.
     *
     * @param stdClass $cm Course module the template must belong to.
     * @param int $templateid
     * @param stdClass $user User doing the download; their email identifies
     *                        them to the service.
     * @param plagiarism_pchkorg_config_model|null $configmodel
     * @param plagiarism_pchkorg_api_provider|null $apiprovider Injected by tests.
     * @return stdClass With filename, mime and content.
     * @throws moodle_exception When the feature is off or the service refuses.
     * @throws required_capability_exception When the user may not edit the activity.
     */
    public static function resolve($cm, $templateid, $user, $configmodel = null, $apiprovider = null) {
        $templateid = (int) $templateid;

        require_capability(self::CAPABILITY, context_module::instance($cm->id));

        if (null === $configmodel) {
            $configmodel = new plagiarism_pchkorg_config_model();
        }
        if ('1' !== $configmodel->get_system_config('pchkorg_enable_ignore_templates')) {
            throw new moodle_exception('pchkorg_ignore_template_error_access_denied', 'plagiarism_pchkorg');
        }

        if (null === $apiprovider) {
            $apiprovider = new plagiarism_pchkorg_api_provider($configmodel->get_system_config('pchkorg_token'));
        }

        $key = plagiarism_pchkorg_assignment_key::for_cmid($cm->id);

        // The service is the authority on which template belongs to this
        // activity; it answers 404 for one that belongs to another. The list is
        // only read for the name and type to send the file under.
        $templates = $apiprovider->ignore_template_list($key, $user->email);

        $result = new stdClass();
        $result->filename = null;
        $result->mime = self::DEFAULT_MIME;

        if (is_array($templates)) {
            foreach ($templates as $template) {
                if (!isset($template->id) || (int) $template->id !== $templateid) {
                    continue;
                }
                if (!empty($template->filename)) {
                    $result->filename = (string) $template->filename;
                }
                if (!empty($template->mime_type)) {
                    $result->mime = (string) $template->mime_type;
                }
                break;
            }
        }

        $result->content = $apiprovider->ignore_template_download($key, $templateid, $user->email);

        if (null === $result->content) {
            $code = $apiprovider->get_last_error();

            throw new moodle_exception(
                'pchkorg_ignore_template_error_' . (empty($code) ? 'template_not_found' : $code),
                'plagiarism_pchkorg'
            );
        }

        if (empty($result->filename)) {
            $result->filename = 'ignore-template-' . $templateid;
        }
        $result->filename = clean_filename($result->filename);

        return $result;
    }
}
