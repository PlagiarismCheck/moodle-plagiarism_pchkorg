<?php

$PAGE->requires->css('/plagiarism/pchkorg/assets/viewer/report-viewer.min.css');

echo $OUTPUT->header();

?>
    <div class="pCheck-container"></div>
    <div id="report-root"></div>
<?php


if (empty($error)) {
    ?>
    <script src="/plagiarism/pchkorg/assets/viewer/report-viewer.min.js"></script>
    <script>
      (function (window, document, ReportViewer, localData) {
        var element = document.getElementById('report-root');
        var widget = ReportViewer.create();

        widget.init({
          element: element,
          localData: localData
        });
      })(window, window.document, window.ReportViewer, <?php
 echo empty($data) ? '{}' : $data ?>);
    </script>
    <?php

} elseif (isset($error)) {
    ?>
    <h2>Error: <?php
 echo $error ?></h2>
    <?php

}

echo $OUTPUT->footer();
