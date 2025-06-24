<?php
require_once 'jwt.php';
require_once 'conn.php';
require_once 'getUserFullName.php';
require_once 'log.php';


// function to get headers from request
function getHeaders()
{
    if (function_exists('apache_request_headers')) {
        return apache_request_headers();
    } elseif (function_exists('getallheaders')) {
        return getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}


// Helper function to extract user data from JWT
function getUserFromJWT() {
    try {
        $headers = getHeaders();
        
        if (!isset($headers['Authorization'])) {
            log_action("Authorization Header Missing for audit log");
            return ['status' => false, 'message' => 'Authorization header missing'];
        }
        
        $authHeader = $headers['Authorization'];
        $parts = explode(' ', $authHeader);
        
        if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
            log_action("Invalid Authorization Header Format for audit log");
            return ['status' => false, 'message' => 'Invalid authorization header format'];
        }
        
        $token = $parts[1];
        $decoded = decodeJWT($token);
        $user_id = $decoded['id'] ?? null;
        
        if (!$user_id) {
            log_action("Invalid JWT payload for audit log: " . json_encode($decoded));
            return ['status' => false, 'message' => 'Invalid token payload'];
        }
        
      
        // Get database connection
        $conn = getConnection();
        if (!$conn) {
            log_action("Failed to get database connection in audit log");
            return ['status' => false, 'message' => 'Database connection failed'];
        }
        $full_name = getUserFullName($conn, $user_id);
        
        if (!$full_name) {
            return ['status' => false, 'message' => 'User not found'];
        }
        
        return [
            'status' => true, 
            'user_id' => $user_id, 
            'full_name' => $full_name
        ];
        
    } catch (Exception $e) {
        log_action("JWT decode failed in audit log: " . $e->getMessage());
        return ['status' => false, 'message' => 'Token validation failed'];
    }
}