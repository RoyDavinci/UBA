<?php
// Helper function to get user's full name from database
function getUserFullName($conn, $user_id) {
    try {

         $result = $conn->query("SELECT full_name FROM users WHERE id = $user_id");
         return $result->fetch_assoc()['full_name'] ?? false;
        
    } catch (Exception $e) {
        log_action("Exception in getUserFullName: " . $e->getMessage());
        return false;
    }
}