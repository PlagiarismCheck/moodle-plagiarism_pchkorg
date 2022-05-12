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

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}

require_once($CFG->dirroot.'/plagiarism/pchkorg/lib.php');

/**
 * This class subscribe to events.
 */
class plagiarism_pchkorg_observer {
    /**
     * Handle the course_module_deleted event.
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(
        \core\event\course_module_deleted $event) {
        global $DB;
        $eventdata = $event->get_data();

        $DB->delete_records('plagiarism_pchkorg_files', array('cm' => $eventdata['contextinstanceid']));
        $DB->delete_records('plagiarism_pchkorg_config', array('cm' => $eventdata['contextinstanceid']));
    }

    /**
     * Handle the assignment assessable_uploaded event.
     * @param \assignsubmission_file\event\assessable_uploaded $event
     */
    public static function assignsubmission_file_uploaded(
        \assignsubmission_file\event\assessable_uploaded $event) {
        $eventdata = $event->get_data();
        $eventdata['eventtype'] = 'file_uploaded';
        $eventdata['other']['modulename'] = 'assign';

        $plugin = new plagiarism_plugin_pchkorg();
        $plugin->event_handler($eventdata);
    }

    /**
     * Handle the assignment assessable_uploaded event.
     * @param \assignsubmission_onlinetext\event\assessable_uploaded $event
     */
    public static function assignsubmission_onlinetext_uploaded(
        \assignsubmission_onlinetext\event\assessable_uploaded $event) {
        $eventdata = $event->get_data();
        $eventdata['eventtype'] = 'content_uploaded';
        $eventdata['other']['modulename'] = 'assign';

        $plugin = new plagiarism_plugin_pchkorg();
        $plugin->event_handler($eventdata);
    }

    /**
     * Handle the assignment assessable_submitted event.
     * @param \mod_assign\event\assessable_submitted $event
     */
    public static function assignsubmission_submitted(
        \mod_assign\event\assessable_submitted $event) {
        $eventdata = $event->get_data();
        $eventdata['eventtype'] = 'assessable_submitted';
        $eventdata['other']['modulename'] = 'assign';

        $plugin = new plagiarism_plugin_pchkorg();
        $plugin->event_handler($eventdata);
    }

    /**
     * Handle the quiz attempt_submitted event.
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_submitted(
        \mod_quiz\event\attempt_submitted $event) {
        $eventdata = $event->get_data();
        $eventdata['eventtype'] = 'quiz_submitted';
        $eventdata['other']['modulename'] = 'quiz';

        $plugin = new plagiarism_plugin_pchkorg();
        $plugin->event_handler($eventdata);
    }

    /**
     * Handle the forum reply updated event.
     * @param \mod_forum\event\assessable_uploaded $event
     */
    public static function forum_assessable_uploaded(
        \mod_forum\event\assessable_uploaded$event
    ) {
        $eventdata = $event->get_data();
        $eventdata['eventtype'] = 'forum_attachment';
        $eventdata['other']['modulename'] = 'forum';

        $plugin = new plagiarism_plugin_pchkorg();
        $plugin->event_handler($eventdata);
    }
}
