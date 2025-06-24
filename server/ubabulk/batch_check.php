<?php

ini_set('memory_limit', '2G');  // or 512M, 2G
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Load dependencies
require './conn.php';
require './log.php';
require './jwt.php';
require './vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;


$conn = getConnection();

// Log raw inputs
log_action("Raw POST: " . json_encode($_POST));
log_action("Raw FILES: " . json_encode($_FILES));

// Helper to get headers
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

// Get Authorization token
$headers = getHeaders();
log_action("Incoming Headers: " . json_encode($headers));

if (!isset($headers['Authorization'])) {
    log_action("Authorization Header Missing");
    echo json_encode(['message' => "Authorization header is required", "status" => false]);
    exit;
}

$authHeader = $headers['Authorization'];
$parts = explode(' ', $authHeader);

if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
    log_action("Invalid Authorization Header Format");
    echo json_encode(['message' => "Invalid Authorization header. Expected format: 'Bearer <token>'", 'status' => false]);
    exit;
}

$token = $parts[1];

try {
    $decoded = decodeJWT($token);
    $user_id = $decoded['id'] ?? null;
    $role    = $decoded['role'] ?? null;

    if (!$user_id || !$role) {
        log_action("Invalid JWT payload: " . json_encode($decoded));
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        closeConnection($conn);
        exit;
    }
} catch (Exception $e) {
    log_action("JWT decode failed: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Invalid token: " . $e->getMessage()]);
    closeConnection($conn);
    exit;
}

$batchId = $_POST['batchId'] ?? '';

// Validating Batch Id input
if (!$batchId) {
    log_action("Missing Batch Id");
    echo json_encode(["status" => false, "message" => "Batch Id is required."]);
    exit;
}

// Using prepared statement to prevent SQL injection
$query = "SELECT * FROM uploaded_files WHERE batch_id = ?";
$stmt = $conn->prepare($query);

if ($stmt === false) {
    log_action("Prepare failed: " . $conn->error);
    echo json_encode(["status" => false, "message" => "Database error"]);
    exit;
}

$stmt->bind_param("s", $batchId);
$executeResult = $stmt->execute();

if ($executeResult === false) {
    log_action("Execute failed: " . $stmt->error);
    echo json_encode(["status" => false, "message" => "Database error"]);
    $stmt->close();
    exit;
}

$result = $stmt->get_result();

if ($result === false) {
    log_action("Get result failed: " . $stmt->error);
    echo json_encode(["status" => false, "message" => "Database error"]);
    $stmt->close();
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();

echo json_encode([
    "status" => true,
    "message" => "Data successfully retrieved.",
    "data" => $data,
]);