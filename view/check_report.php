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

$ajaxurl = new moodle_url('/plagiarism/pchkorg/page/check.php');
$PAGE->requires->js_init_code("
      var interval;
      var data = {
        'file': $('#plagcheck-loader').attr('data-file'),
        'cmid': $('#plagcheck-loader').attr('data-cmid')
      };
      var checkStatus = function () {
        $.post('{$ajaxurl}', data, function (response) {
          if (!response || !response.success) {
            $('#plagcheck-loader').hide();
            clearInterval(interval);
          } else if (response.checked) {
            $('#plagcheck-loader').hide();
            clearInterval(interval);
            window.location.href = response.location;
          }
        }, 'JSON');
      };
      interval = setInterval(checkStatus, 1000);
", true);

echo $OUTPUT->header();
?>
    <style>
        .loader {
            border: 16px solid #f3f3f3; /* Light grey */
            border-top: 16px solid #3498db; /* Blue */
            border-radius: 50%;
            width: 120px;
            height: 120px;
            animation: spin 2s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
    </style>

    <div>
        <div id="plagcheck-loader" data-cmid="<?php
        echo intval($cmid) ?>" data-file="<?php
        echo intval($fileid) ?>" class="loader"></div>
    </div>
<?php
echo $OUTPUT->footer();