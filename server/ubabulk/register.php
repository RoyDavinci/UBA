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

log_action("Received registration data: " . json_encode($data));

// Sanitize input
$full_name = trim($conn->real_escape_string($data['full_name'] ?? ''));
$email = trim($conn->real_escape_string($data['email'] ?? ''));
$password = $data['password'] ?? '';
$role = $conn->real_escape_string($data['role'] ?? '');
$organization_id = isset($data['organization_id']) ? (int)$data['organization_id'] : null;

// Validation
if (!$full_name || !$email || !$password || !$role) {
    log_action("Validation failed: Missing fields.");
    echo json_encode(["status" => false, "message" => "Missing required fields."]);
    closeConnection($conn);
    exit;
}

if (!in_array($role, ['super_admin', 'admin', 'customer_support', 'technical_support'])) {
    log_action("Validation failed: Invalid role '$role'.");
    echo json_encode(["status" => false, "message" => "Invalid role specified."]);
    closeConnection($conn);
    exit;
}

if ($role !== 'super_admin' && !$organization_id) {
    log_action("Validation failed: $role missing organization_id.");
    echo json_encode(["status" => false, "message" => "This role must be linked to an organization."]);
    closeConnection($conn);
    exit;
}

// Check if email exists
$check = $conn->query("SELECT id FROM users WHERE email = '$email'");
if ($check && $check->num_rows > 0) {
    log_action("Email already exists: $email");
    echo json_encode(["status" => false, "message" => "Email already in use."]);
    closeConnection($conn);
    exit;
}

// Hash password
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Insert query
$orgPart = ($role === 'super_admin') ? "NULL" : $organization_id;
$sql = "INSERT INTO users (organization_id, full_name, email, password, role)
        VALUES ($orgPart, '$full_name', '$email', '$hashedPassword', '$role')";

if ($conn->query($sql)) {
    // Get the newly inserted user's ID
    $user_id = $conn->insert_id;

    // Create JWT Payload
    $payload = [
        'id' => $user_id,
        'email' => $email,
        'role' => $role,
        'iat' => time(),
        'exp' => time() + 360000000000
    ];

    // Generate JWT Token
    $jwt = generateJWT($payload);  // Use your JWT helper function (from jwt.php)

    log_action("User registered: email=$email, role=$role, org_id=$orgPart");

    // Send success response with JWT
    echo json_encode([
        "status" => true,
        "message" => "User registered successfully.",
        "token" => $jwt
    ]);
} else {
    log_action("MySQL Error on registration: " . $conn->error);
    echo json_encode(["status" => false, "message" => "Error registering user.", "error" => $conn->error]);
}

closeConnection($conn);
