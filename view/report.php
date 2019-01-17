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
    $PAGE->requires->css(new moodle_url('/plagiarism/pchkorg/assets/viewer/public-report.min.css'));
    $PAGE->requires->js(new moodle_url('/plagiarism/pchkorg/assets/viewer/public-report.bundle.min.js'), true);
    $PAGE->requires->js_init_code(
            '
        initPublicReport({
            container: window.document.getElementById(\'report-root\'),
            basename: "'.$currenturl->get_path().'",
            localData: ' . (empty($data) ? '{}' : $data) . '
        });
      ', true
    );
}

echo $OUTPUT->header();

?>
    <div class="pCheck-container"></div>
    <div id="report-root"></div>
<?php
if (!empty($error)) {
    ?>
    <h2>Error: <?php
        echo htmlspecialchars($error) ?></h2>
    <?php
}

echo $OUTPUT->footer();
