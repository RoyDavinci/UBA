<?php

require './conn.php';

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


function audit_log($conn, $user_id, $full_name, $action) {
    // Validate parameters
    if (!$conn) {
        log_action("Database connection is required in audit log");
        return ['status' => false, 'message' => 'Database connection required'];
    }
    
    if (!$user_id) {
        log_action("user_id is required in audit log");
        return ['status' => false, 'message' => 'user_id required'];
    }
    
    if (!$full_name) {
        log_action("full_name is required in audit log");
        return ['status' => false, 'message' => 'full_name required'];
    }
    
    if (!$action) {
        log_action("action is required in audit log");
        return ['status' => false, 'message' => 'action required'];
    }

    try {
        
        $user_id = $conn->real_escape_string($user_id);
        $full_name = $conn->real_escape_string($full_name);
        $action = $conn->real_escape_string($action);

        $dateTime = new DateTime('now', new DateTimeZone('Africa/Lagos'));
        $dateTimeNow = $dateTime->format('Y-m-d H:i:s');
        $dateTimeNow = $conn->real_escape_string($dateTimeNow);

        $query = "INSERT INTO audit_logs 
                (user_id, full_name, log_action, created_at, updated_at) 
                VALUES ('$user_id', '$full_name', '$action', '$dateTimeNow', '$dateTimeNow')";
        
        $executed = $conn->query($query);
        
        if (!$executed) {
            log_action("Query failed: " . $conn->error);
            return ['status' => false, 'message' => 'Failed to log action'];
        }
        
        return ['status' => true, 'message' => 'Action logged successfully'];
        
    } catch (Exception $e) {
        log_action("Audit log exception: " . $e->getMessage());
        return ['status' => false, 'message' => 'System error'];
    }
}


