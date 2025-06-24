<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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
    log_action("Invalid Authorization Header Format ['header' => $authHeader]");
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

$organization_id = 0;
if ($role === 'super_admin') {
    $organization_id = $_POST['organization_id'] ?? null;
    if (!$organization_id) {
        log_action("Missing organization_id for super_admin.");
        echo json_encode(["status" => false, "message" => "Organization ID is required for super_admin."]);
        closeConnection($conn);
        exit;
    }
} else {
    $org_query = "SELECT organization_id FROM users WHERE id = $user_id LIMIT 1";
    $org_result = $conn->query($org_query);

    if ($org_result && $org_row = $org_result->fetch_assoc()) {
        $organization_id = $org_row['organization_id'];
    } else {
        $error = $conn->error;
        log_action("Unable to get organization_id for user $user_id. SQL: $org_query. Error: $error");
        echo json_encode(["status" => false, "message" => "Unable to determine organization for user."]);
        closeConnection($conn);
        exit;
    }
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
    log_action("File upload failed. Error code: " . ($_FILES['file']['error'] ?? 'No file error code'));
    echo json_encode(["status" => false, "message" => "File upload failed."]);
    closeConnection($conn);
    exit;
}

$file_name = $_FILES['file']['name'];
$file_tmp  = $_FILES['file']['tmp_name'];
$file_size = $_FILES['file']['size'];
$max_size = 50 * 1024 * 1024;

if ($file_size > $max_size) {
    log_action("File too large: $file_size bytes.");
    echo json_encode(["status" => false, "message" => "File size too large (max 50MB)."]);
    closeConnection($conn);
    exit;
}

$id  = uniqid();
$file_path = tempnam('/var/www/html/bulksms/ubabulk/', "uploaded_file_" . $id);
if (!move_uploaded_file($file_tmp, $file_path)) {
    log_action("Failed to move uploaded file.");
    echo json_encode(["status" => false, "message" => "Failed to move uploaded file."]);
    closeConnection($conn);
    exit;
}

$file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
$csv_file_path = $file_path;

if ($file_extension == 'xlsx' || $file_extension == 'xls') {
    try {
        $spreadsheet = IOFactory::load($file_path);
        $writer = IOFactory::createWriter($spreadsheet, 'Csv');
        $csv_file_path = tempnam('/var/www/html/bulksms/ubabulk/', "converted_csv_" . uniqid());
        $writer->save($csv_file_path);
        unlink($file_path);
        log_action("Excel converted to CSV: $csv_file_path");
    } catch (Exception $e) {
        log_action("Error converting Excel to CSV: " . $e->getMessage());
        echo json_encode(["status" => false, "message" => "Error converting Excel to CSV: " . $e->getMessage()]);
        closeConnection($conn);
        exit;
    }
} elseif ($file_extension != 'csv') {
    log_action("Invalid file type: $file_extension");
    echo json_encode(["status" => false, "message" => "Invalid file type. Only CSV and Excel are allowed."]);
    closeConnection($conn);
    exit;
}

$final_file_path = "/var/www/html/bulksms/ubabulk/upload_" . uniqid() . ".csv";
if (!rename($csv_file_path, $final_file_path)) {
    log_action("Failed to save final CSV to destination: $final_file_path");
    echo json_encode(["status" => false, "message" => "Failed to save final file."]);
    closeConnection($conn);
    exit;
}

$record_count = 0;
if (($handle = fopen($final_file_path, 'r')) !== false) {
    $has_header = fgetcsv($handle);
    while (fgetcsv($handle) !== false) {
        $record_count++;
    }
    fclose($handle);
}

log_action("File stored: $final_file_path with $record_count rows");
echo json_encode([
    "status" => true,
    "message" => "File successfully uploaded and stored.",
    "file" => basename($final_file_path),
    "record_count" => $record_count
]);

closeConnection($conn);
?>

