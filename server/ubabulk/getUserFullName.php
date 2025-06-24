<?php
// Helper function to get user's full name from database
function getUserFullName($conn, $user_id) {
    try {
        $query = "SELECT full_name FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            log_action("Prepare failed in getUserFullName: ");
            return false;
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['full_name'];
        }
        
        return false;
        
    } catch (Exception $e) {
        log_action("Exception in getUserFullName: " . $e->getMessage());
        return false;
    }
}