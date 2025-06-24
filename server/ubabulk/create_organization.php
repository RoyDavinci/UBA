<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';  // Ensure you have a JWT helper (e.g., using Firebase JWT)

$conn = getConnection();

// Read input (JSON or URL-encoded)
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    $data = $_REQUEST;
}

log_action("Received create organization data: " . json_encode($data));

// Sanitize input
$organization_name = trim($conn->real_escape_string($data['name'] ?? ''));

// Validation: Check if organization name is provided
if (!$organization_name) {
    log_action("Validation failed: Missing organization name.");
    echo json_encode(["status" => false, "message" => "Organization name is required."]);
    closeConnection($conn);
    exit;
}



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

// Get headers
$headers = getHeaders();
log_action("Incoming Headers".  json_encode($headers));

if (!isset($headers['Authorization'])) {
    log_action("Authorization Header Missing");
    echo json_encode(['message' => "Authorization header is required", 'status' => false]);
    exit;
}

$authHeader = $headers['Authorization'];
$parts = explode(' ', $authHeader);

if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
    log_action("Invalid Authorization Header Format ['header' => $authHeader]");
    echo json_encode(['message' => "Invalid Authorization header. Expected format: 'Bearer <token>'", 'status' => false]);
    exit;
}

$jwt = $parts[1];


try {
    // Decode the JWT token to get the user's data
    $decoded = decodeJWT($jwt);  // Use your JWT helper function (from jwt.php)

    if ($decoded['role'] !== 'super_admin') {
        log_action("Unauthorized access attempt by non-super_admin: " . $decoded['email']);
        echo json_encode(["status" => false, "message" => "Only super_admin can create an organization."]);
        closeConnection($conn);
        exit;
    }

    // Proceed with organization creation
    $sql = "INSERT INTO organizations (name) VALUES ('$organization_name')";

    if ($conn->query($sql)) {
        log_action("Organization created successfully: $organization_name");
        echo json_encode(["status" => true, "message" => "Organization created successfully."]);
    } else {
        log_action("Error creating organization: " . $conn->error);
        echo json_encode(["status" => false, "message" => "Error creating organization.", "error" => $conn->error]);
    }
} catch (Exception $e) {
    log_action("Error decoding JWT: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Invalid or expired token."]);
}

closeConnection($conn);
