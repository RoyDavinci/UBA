<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
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

// Get token from Authorization header
$headers = getHeaders();
log_action("Incoming Headers: " . json_encode($headers));

if (!isset($headers['Authorization'])) {
    log_action("Authorization Header Missing");
    echo json_encode(['status' => false, 'message' => 'Authorization header is required.']);
    exit;
}

$authHeader = $headers['Authorization'];
$parts = explode(' ', $authHeader);

if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
    log_action("Invalid Authorization Header Format: $authHeader");
    echo json_encode(['status' => false, 'message' => 'Invalid Authorization header format.']);
    exit;
}

$token = $parts[1];
try {
    $decoded = decodeJWT($token);
    $user_id = $decoded['id'] ?? null;

    if (!$user_id) {
        log_action("Missing user_id in decoded token: " . json_encode($decoded));
        echo json_encode(['status' => false, 'message' => 'Invalid token payload.']);
        closeConnection($conn);
        exit;
    }
} catch (Exception $e) {
    log_action("JWT Decode Failed: " . $e->getMessage());
    echo json_encode(['status' => false, 'message' => 'Token decode failed.']);
    closeConnection($conn);
    exit;
}

// Get the OTP from request
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    $data = $_POST;
}

$otp = trim($data['otp'] ?? '');
log_action("OTP received: $otp for user_id: $user_id");

if (!$otp) {
    log_action("OTP not provided");
    echo json_encode(['status' => false, 'message' => 'OTP is required.']);
    closeConnection($conn);
    exit;
}

// Query the stored OTP
$sql = "SELECT * FROM users WHERE id = '$user_id' LIMIT 1";
$result = $conn->query($sql);

if (!$result || $result->num_rows === 0) {
    log_action("User not found or query failed for user_id: $user_id");
    echo json_encode(['status' => false, 'message' => 'User not found.']);
    closeConnection($conn);
    exit;
}

$row = $result->fetch_assoc();
$stored_otp = trim($row['otp'] ?? '');

if ($stored_otp === $otp) {
    log_action("OTP verified successfully for user_id: $user_id");
    $payload = [
        'id' => $row['id'],
        'email' => $row['email'],
        'role' => $row['role'],
        'iat' => time(),
        'exp' => time() + 360000000000
    ];

    $jwt = generateJWT($payload);
    log_action("JWT generated for user ID: {$row['id']}");
    echo json_encode([
        "status" => true, "message" => 'OTP verified successfully.',
        "token" => $jwt,
        "role" => $row['role']
    ]);
} else {
    log_action("OTP mismatch for user_id: $user_id. Expected: $stored_otp, Given: $otp");
    echo json_encode(['status' => false, 'message' => 'Invalid OTP.']);
}

closeConnection($conn);

