<?php


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


        if ($isSupported) {
            echo $form->display();
        } else {
            echo 'file not supported';
        }
        ?>
    </div>
<?php

echo $OUTPUT->footer();