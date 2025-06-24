<?php
require_once 'JWTUser.php';
function audit_log($conn, $action, $user_id = null) {
    // Validate required parameters
    if (!$conn) {
        log_action("Database connection is required in audit log");
        return ['status' => false, 'message' => 'Database connection required'];
    }
    
    if (!$action) {
        log_action("action is required in audit log");
        return ['status' => false, 'message' => 'action required'];
    }
    
    try {
        // If user_id is not provided, try to get it from JWT
        if (!$user_id) {
            $user_data = getUserFromJWT();
            if (!$user_data['status']) {
                return $user_data; // Return the error from getUserFromJWT
            }
            $user_id = $user_data['user_id'];
            $full_name = $user_data['full_name'];
        } else {
            // If user_id is provided, fetch the full name
            $full_name = getUserFullName($conn, $user_id);
            if (!$full_name) {
                log_action("Could not fetch full name for user_id: $user_id");
                return ['status' => false, 'message' => 'User not found'];
            }
        }
        
        // Insert audit log
        $query = "INSERT INTO audit_logs (user_id, full_name, log_action) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            log_action("Prepare failed: " . $conn->error);
            return ['status' => false, 'message' => 'Database error'];
        }
        
        $stmt->bind_param("iss", $user_id, $full_name, $action);
        $executed = $stmt->execute();
        
        if (!$executed) {
            log_action("Execute failed: " . $stmt->error);
            return ['status' => false, 'message' => 'Failed to log action'];
        }
        
        return ['status' => true, 'message' => 'Action logged successfully'];
        
    } catch (Exception $e) {
        log_action("Audit log exception: " . $e->getMessage());
        return ['status' => false, 'message' => 'System error'];
    }
}

// usage example
// require_once 'audit_log.php'; in all actionable endpoint
// audit_log($conn, "User Login"); actual action can be anything like "User Login", "File Uploaded", etc.