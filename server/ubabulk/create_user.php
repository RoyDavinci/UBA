<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';  // Make sure your decodeJWT() and generateJWT() functions are in here

$conn = getConnection();

// Read input (JSON or URL-encoded)
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    $data = $_REQUEST;
}

log_action("Received registration data: " . json_encode($data));

// Sanitize input
$full_name = trim($conn->real_escape_string($data['full_name'] ?? ''));
$email = trim($conn->real_escape_string($data['email'] ?? ''));
$password = $data['password'] ?? '';
$role = $conn->real_escape_string($data['role'] ?? '');
$provided_organization_id = isset($data['organization_id']) ? (int)$data['organization_id'] : null;

// Get and decode JWT token

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
    $current_user_id = $decoded['id'] ?? null;
    $current_user_role = $decoded['role'] ?? null;

    if (!$current_user_id || !$current_user_role) {
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        closeConnection($conn);
        exit;
    }

    log_action("Decoded JWT: " . json_encode($decoded));

    if ($current_user_role === 'super_admin') {
        // Super admins MUST provide an organization_id for the new user
        if (!$provided_organization_id) {
            echo json_encode(["status" => false, "message" => "Organization ID is required for super admin."]);
            closeConnection($conn);
            exit;
        }
        $organization_id = $provided_organization_id;
    } else {
        // Non-super admins: fetch their organization_id from DB
        $result = $conn->query("SELECT organization_id FROM users WHERE id = $current_user_id");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $organization_id = (int)$row['organization_id'];
        } else {
            echo json_encode(["status" => false, "message" => "Failed to fetch organization ID for user."]);
            closeConnection($conn);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(["status" => false, "message" => "Invalid token. " . $e->getMessage()]);
    closeConnection($conn);
    exit;
}

// Final validation
if (!$email || !$password || !$role) {
    log_action("Validation failed: Missing fields.");
    echo json_encode(["status" => false, "message" => "Missing required fields."]);
    closeConnection($conn);
    exit;
}

$allowed_roles = ['super_admin', 'admin', 'customer_support', 'technical_support'];
if (!in_array($role, $allowed_roles)) {
    echo json_encode(["status" => false, "message" => "Invalid role specified."]);
    closeConnection($conn);
    exit;
}

// Check if email exists
$check = $conn->query("SELECT id FROM users WHERE email = '$email'");
if ($check && $check->num_rows > 0) {
    echo json_encode(["status" => false, "message" => "Email already in use."]);
    closeConnection($conn);
    exit;
}

// Hash password
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Insert user
$orgPart = ($role === 'super_admin') ? "NULL" : $organization_id;
$sql = "INSERT INTO users (organization_id, full_name, email, password, role)
        VALUES ($orgPart, '$full_name', '$email', '$hashedPassword', '$role')";

if ($conn->query($sql)) {
    $user_id = $conn->insert_id;

    // Generate JWT for the new user
    $payload = [
        'id' => $user_id,
        'email' => $email,
        'role' => $role,
        'organization_id' => $organization_id,
        'iat' => time(),
        'exp' => time() + 3600 * 24 * 7
    ];

    $jwt = generateJWT($payload);

    log_action("User registered: email=$email, role=$role, org_id=$organization_id");

    echo json_encode([
        "status" => true,
        "message" => "User registered successfully.",
        "token" => $jwt
    ]);
} else {
    log_action("MySQL Error: " . $conn->error);
    echo json_encode(["status" => false, "message" => "Error registering user."]);
}

closeConnection($conn);
