<?php
function log_action($msg, $logDir = './logs')
{
    // Create directory if it doesn't exist
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true); // Recursive mkdir
    }

    // Daily log file
    $logFile = $logDir . '/' . date('Y-m-d') . '.log';

    // Format message
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = $msg . " | $timestamp\n<=============================================>\n";

    // Write to file
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    return true;
}

