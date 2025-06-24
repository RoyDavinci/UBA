<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';
require './vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

$conn = getConnection();

log_action("Raw POST: " . json_encode($_POST));
log_action("Raw FILES: " . json_encode($_FILES));

// Helper to get headers in all environments
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

// Get Authorization header
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

$query = "SELECT full_name FROM users WHERE id = '$user_id'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    log_action("Failed to fetch full_name for user $user_id");
    echo json_encode(["status" => false, "message" => "Failed to retrieve user info."]);
    closeConnection($conn);
    exit;
}

$row = $result->fetch_assoc();
$full_name = $conn->real_escape_string($row['full_name']);

$text = $_POST['text'] ?? '';
$batchId = $_POST['batchId'] ?? '';
$type = $_POST['sms_type'] ?? 'general';

if (!$text) {
    log_action("Missing text");
    echo json_encode(["status" => false, "message" => "Text message is required."]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
    log_action("File upload failed.");
    echo json_encode(["status" => false, "message" => "File upload failed."]);
    exit;
}

$file_name = $_FILES['file']['name'];
$file_tmp  = $_FILES['file']['tmp_name'];
$file_size = $_FILES['file']['size'];
$max_size = 100 * 1024 * 1024;

if ($file_size > $max_size) {
    log_action("File too large: $file_size");
    echo json_encode(["status" => false, "message" => "File too large (max 100MB)."]);
    exit;
}

$allowed_extensions = ['csv', 'xls', 'xlsx', 'zip'];
$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

if (!in_array($file_ext, $allowed_extensions)) {
    echo json_encode(["status" => false, "message" => "Invalid file type."]);
    exit;
}

$upload_dir = '/var/www/html/bulksms/ubabulk/uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$baseName = pathinfo($file_name, PATHINFO_FILENAME);
$uniqueName = uniqid('upload_') . '_' . $baseName . '.' . $file_ext;
$target_file = $upload_dir . $uniqueName;

if (file_exists($target_file)) {
    echo json_encode(["status" => false, "message" => "File already exists."]);
    exit;
}

if (!move_uploaded_file($file_tmp, $target_file)) {
    log_action("Failed to move uploaded file.");
    echo json_encode(["status" => false, "message" => "Failed to store uploaded file."]);
    exit;
}

// If XLSX, convert to CSV

if ($file_ext === 'xlsx') {
    try {
        $spreadsheet = IOFactory::load($target_file);
        $sheet = $spreadsheet->getActiveSheet();

        // Create a new Spreadsheet to write cleaned data
        $cleanSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $cleanSheet = $cleanSpreadsheet->getActiveSheet();

        $rowIndex = 1;
        foreach ($sheet->toArray(null, true, true, true) as $row) {
            // Check if the row is entirely empty
            $isEmpty = true;
            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }

            if (!$isEmpty) {
                $cleanSheet->fromArray($row, null, 'A' . $rowIndex++);
            }
        }

        $csvWriter = new Csv($cleanSpreadsheet);
        $csvWriter->setDelimiter(',');
        $csvWriter->setEnclosure('"');

        $csvFilename = pathinfo($target_file, PATHINFO_FILENAME) . '.csv';
        $csvPath = $upload_dir . $csvFilename;
        $csvWriter->save($csvPath);

        if (file_exists($target_file)) {
            unlink($target_file);
            log_action("Deleted original XLSX file: $target_file");
        }

        $target_file = $csvPath;
        log_action("Converted XLSX to CSV without empty rows: $csvPath");

    } catch (Exception $e) {
        log_action("XLSX to CSV conversion failed: " . $e->getMessage());
        echo json_encode(["status" => false, "message" => "Failed to convert Excel to CSV."]);
	closeConnection($conn);
        exit;
    }
}


$file_escaped = $conn->real_escape_string($target_file);
$text_escaped = $conn->real_escape_string($text);
$type_escaped = $conn->real_escape_string($type);
$batch_escaped = $conn->real_escape_string($batchId);

$query = "INSERT INTO uploaded_files (file_path, message, status, type, batch_id, full_name) 
          VALUES ('$file_escaped', '$text_escaped', '0', '$type_escaped', '$batch_escaped', '$full_name')";

$result = $conn->query($query);

if (!$result) {
    log_action("Insert failed: " . $conn->error);
    echo json_encode(["status" => false, "message" => "Failed to save file record."]);
    closeConnection($conn);
    exit;
}

echo json_encode([
    "status" => true,
    "message" => "File uploaded and saved successfully.",
    "file" => basename($target_file),
]);

closeConnection($conn);

