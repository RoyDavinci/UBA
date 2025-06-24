<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
require './conn.php';
require './log.php';
require './jwt.php';

$conn = getConnection();
$data = json_decode(file_get_contents("php://input"), true) ?? $_REQUEST;



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
log_action("Incoming Headers". json_encode($headers));

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
    $user_id = $decoded['id'] ?? null;
    $role = $decoded['role'] ?? null;

    if (!$user_id || !$role) {
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        exit;
    }

    // Determine organization_id
    if ($role === 'super_admin') {
        $organization_id = isset($data['organization_id']) ? (int)$data['organization_id'] : null;
        if (!$organization_id) {
            echo json_encode(["status" => false, "message" => "Organization ID is required for super_admin."]);
            exit;
        }
    } else {
        // Fetch organization_id from DB for this user
        $result = $conn->query("SELECT organization_id FROM users WHERE id = $user_id LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $organization_id = (int)$row['organization_id'];
        } else {
            echo json_encode(["status" => false, "message" => "Unable to determine organization for user."]);
            closeConnection($conn);
            exit;
        }
    }

    // Validate group name
    $group_name = trim($conn->real_escape_string($data['name'] ?? ''));
    if (!$group_name) {
        echo json_encode(["status" => false, "message" => "Group name is required."]);
        closeConnection($conn);
        exit;
    }

    // Insert group
    $stmt = $conn->prepare("INSERT INTO sms_groups (organization_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $organization_id, $group_name);
    $stmt->execute();

    echo json_encode(["status" => true, "message" => "Group created."]);
    log_action("Created SMS group '$group_name' for organization $organization_id by user ID $user_id ($role)");
} catch (Exception $e) {
    log_action("JWT decode failed: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Invalid token: " . $e->getMessage()]);
}

closeConnection($conn);
