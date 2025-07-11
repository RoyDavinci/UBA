<?php


function wh_log($log_msg)
{
    $log_filename = "/var/www/html/powersmpp/ubabulk/ubalog";
    if (!file_exists($log_filename)) {
        // create directory/folder uploads.
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename . '/log_' . date('d-M-Y') . '.log';
    // if you don't add `FILE_APPEND`, the file will be erased each time you add a log
    file_put_contents($log_file_data, date('Y-m-d H:i:s') .  $log_msg . "\n" .  "\n", FILE_APPEND);
}
