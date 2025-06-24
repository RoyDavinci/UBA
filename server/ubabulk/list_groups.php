<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './jwt.php';
require './log.php';

$conn = getConnection();



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
log_action("Incoming Headers" . json_encode($headers));

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

$token = $parts[1];


try {
    log_action($token);
    $decoded = decodeJWT($token);
    log_action(json_encode($decoded));
    $userId = $decoded['id'] ?? null;
    $role = $decoded['role'] ?? null;

    log_action("Token decoded. User ID: $userId, Role: $role");

    if (!$userId || !$role) {
        log_action("Invalid token payload. userId or role missing.");
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        exit;
    }

    if ($role === 'super_admin') {
        log_action("Super admin fetching all groups.");
        $query = "SELECT id, name, created_at FROM sms_groups ORDER BY created_at DESC";
    } else {
        log_action("Fetching organization_id for user ID: $userId");
        $userResult = $conn->query("SELECT organization_id FROM users WHERE id = $userId");

        if (!$userResult || $userResult->num_rows === 0) {
            log_action("User not found or error querying users table. User ID: $userId");
            echo json_encode(["status" => false, "message" => "User not found."]);
            exit;
        }

        $user = $userResult->fetch_assoc();
        $org_id = $user['organization_id'] ?? null;

        if (!$org_id) {
            log_action("Organization ID missing for user ID: $userId");
            echo json_encode(["status" => false, "message" => "Organization ID missing."]);
            exit;
        }

        log_action("User ID: $userId belongs to organization ID: $org_id. Fetching groups.");
        $query = "SELECT id, name, created_at FROM sms_groups WHERE organization_id = $org_id ORDER BY created_at DESC";
    }

    $result = $conn->query($query);

    if (!$result) {
        log_action("Database query failed: " . $conn->error);
        echo json_encode(["status" => false, "message" => "Query failed."]);
        exit;
    }

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }

    log_action("Fetched " . count($groups) . " group(s) for user ID: $userId");

    echo json_encode(["status" => true, "groups" => $groups]);
} catch (Exception $e) {
    log_action("Token decoding failed: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Invalid token."]);
}

closeConnection($conn);
