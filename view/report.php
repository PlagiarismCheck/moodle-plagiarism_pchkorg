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

defined('MOODLE_INTERNAL') || die();

if (empty($error)) {
    $PAGE->requires->js_init_code('window.document.getElementById("plagiarism_pchkorg_report_id").submit();', true);
    echo $OUTPUT->header();
    ?>
    <form id="plagiarism_pchkorg_report_id" action="<?php echo htmlspecialchars($action) ?>" method="post">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token) ?>"/>
        <input type="hidden" name="lms-type" value="moodle"/>
        <input type="submit" value="Check Report">
    </form>
    <?php
} else {
    echo $OUTPUT->header();
    ?>
    <h2>Error: <?php
        echo htmlspecialchars($error) ?></h2>
    <?php
}

echo $OUTPUT->footer();
