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
 * Locates quiz essay content for checking.
 *
 * @package   plagiarism_pchkorg
 * @category  plagiarism
 * @copyright PlagiarismCheck.org, https://plagiarismcheck.org/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/resolver.php');

/**
 * Quiz essay answers, or files attached to an attempt.
 */
class plagiarism_pchkorg_quiz_resolver implements plagiarism_pchkorg_resolver {
    /**
     * An attempt may hold several essay answers, so the queued signature picks
     * out which one this record is for.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_text($filedb, $cm) {
        global $DB;

        $answers = $DB->get_records_sql(
            "SELECT {question_attempts}.responsesummary "
            . " FROM {question_attempts} "
            . " INNER JOIN  {question} on  {question}.id =  {question_attempts}.questionid "
            . " INNER JOIN {quiz_attempts} on {quiz_attempts}.uniqueid = {question_attempts}.questionusageid "
            . " WHERE   {quiz_attempts}.id= ? AND  {question}.qtype = 'essay' ",
            [$filedb->itemid]
        );

        foreach ($answers as $answer) {
            $content = $answer->responsesummary;
            $signature = sha1($content);
            if ($signature !== $filedb->signature) {
                continue;
            }

            return plagiarism_pchkorg_payload::from_html(
                $filedb->itemid,
                $signature,
                $content,
                sprintf('%s-quiz.txt', $filedb->itemid)
            );
        }

        return null;
    }

    /**
     * Describe a file attached to a quiz attempt.
     *
     * @param stdClass $filedb
     * @param stdClass $cm
     * @param stored_file $file
     * @return plagiarism_pchkorg_payload|null
     */
    public function resolve_file($filedb, $cm, $file) {
        return plagiarism_pchkorg_payload::from_file($filedb->itemid, $file);
    }
}
