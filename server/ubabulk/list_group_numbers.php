<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
require './conn.php';
require './jwt.php';

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
    $decoded = decodeJWT($token);
    $org_id = $decoded['organization_id'] ?? null;
    $group_id = (int)($_GET['group_id'] ?? 0);

    $check = $conn->query("SELECT id FROM sms_groups WHERE id = $group_id AND organization_id = $org_id");
    if (!$check || $check->num_rows === 0) {
        echo json_encode(["status" => false, "message" => "Unauthorized group."]);
        exit;
    }

    $res = $conn->query("SELECT phone_number FROM sms_group_numbers WHERE group_id = $group_id");
    $numbers = [];

    while ($row = $res->fetch_assoc()) {
        $numbers[] = $row['phone_number'];
    }

    echo json_encode(["status" => true, "numbers" => $numbers]);
} catch (Exception $e) {
    echo json_encode(["status" => false, "message" => "Invalid token."]);
}
closeConnection($conn);
