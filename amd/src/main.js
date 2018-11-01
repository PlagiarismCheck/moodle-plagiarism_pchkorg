/**
 * Activity form
 *
 * @package     plagiarism_pchkorg
 * @subpackage  plagiarism
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* jshint ignore:start */
define(['jquery'], function ($) {
  return {
    checkReport: function () {
      var interval;
      var data = {
        'file': $('#plagcheck-loader').attr('data-file'),
        'cmid': $('#plagcheck-loader').attr('data-cmid')
      };
      var checkStatus = function () {
        $.post('/plagiarism/pchkorg/page/check.php', data, function (response) {
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
      interval = setInterval(checkStatus, 1000)
    }
  };
});
