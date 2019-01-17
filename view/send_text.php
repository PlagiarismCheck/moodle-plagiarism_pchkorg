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

echo $OUTPUT->header();
?>

    <style>
        .plagiarism-preview-content {
            width: 800px;
            height: 400px;
            overflow-y: scroll;
        }

        .plagiarism-preview-content-inner {
            /*white-space: pre;*/
        }
    </style>
    <br/>
    <div class="plagiarism-preview-content">
        <div class="plagiarism-preview-content-inner">
            <?php
            echo nl2br(htmlspecialchars($content, ENT_COMPAT | ENT_HTML401, $encoding = 'UTF-8')) ?>
        </div>
    </div>
    <br/>
    <div>
        <?php

        if ($issupported) {
            echo $form->display();
        } else {
            echo 'file not supported';
        }
        ?>
    </div>
<?php
echo $OUTPUT->footer();