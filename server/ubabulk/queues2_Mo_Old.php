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

// Fetch user full name
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

// Handle POST data
$batchId = $_POST['batchId'] ?? '';
$costCntr = $_POST['cost_cntr'] ?? '';

//Validating Message Category input
if (!$costCntr) {
    log_action("Missing Cost Center");
    echo json_encode(["status" => false, "message" => "Cost Center is required."]);
    exit;
}

// Validate file
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

// Prepare target file path
$upload_dir = '/var/www/html/bulksms/ubabulk/uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$baseName = pathinfo($file_name, PATHINFO_FILENAME);
$uniqueName = uniqid('upload_') . '_' . $baseName . '.' . $file_ext;
$target_file = $upload_dir . $uniqueName;

// Move uploaded file
if (!move_uploaded_file($file_tmp, $target_file)) {
    log_action("Failed to move uploaded file. Temp: $file_tmp, Target: $target_file");
    echo json_encode(["status" => false, "message" => "Failed to store uploaded file."]);
    exit;
}

//        $contents = file_get_contents($target_file);
// Convert Windows and old Mac line endings to Unix
//        $normalized = preg_replace("/\r\n?/", "\n", $contents);
//        file_put_contents($target_file, $normalized);
//        log_action("Normalized line endings to Unix for: $target_file");
log_action("File moved to: $target_file");

echo json_encode([
    "status" => true,
    "message" => "File uploaded and saved successfully.",
    "file" => basename($target_file),
]);

// Save to DB
$file_escaped = $conn->real_escape_string($target_file);
$batch_escaped = $conn->real_escape_string($batchId);

// Convert XLSX to CSV
if ($file_ext === 'xlsx') {
    try {
        log_action("Attempting XLSX to CSV conversion using Spout");

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($target_file);

        $csvFilename = pathinfo($target_file, PATHINFO_FILENAME) . '.csv';
        $csvPath = $upload_dir . $csvFilename;
        $csvHandle = fopen($csvPath, 'w');

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                // Skip completely empty rows
                if (array_filter($cells, fn ($v) => trim($v) !== '') === []) {
                    continue;
                }

                fputcsv($csvHandle, $cells);
            }
            break; // only first sheet
        }

        fclose($csvHandle);
        $reader->close();

        if (file_exists($target_file)) {
            unlink($target_file);
            log_action("Deleted original XLSX file: $target_file");
        }

        $target_file = $csvPath;
        log_action("Converted XLSX to CSV using Spout: $csvPath");

        // Normalize line endings to Unix
        $contents = file_get_contents($target_file);
        $normalized = preg_replace("/\r\n?/", "\n", $contents);
        file_put_contents($target_file, $normalized);
        log_action("Normalized line endings to Unix for: $target_file");
    } catch (Exception $e) {
	    log_action("Spout conversion failed: " . $e->getMessage());
	    //status 5 means file upload failed
        $query = "INSERT INTO uploaded_files (file_path, status, batch_id, full_name, user_id, cost_cntr) 
            VALUES ('$file_escaped', '5', '$batch_escaped', '$full_name', '$user_id', '$costCntr)";
            $result = $conn->query($query);
        echo json_encode(["status" => false, "message" => "Failed to convert Excel to CSV (streaming)."]);
        closeConnection($conn);
        exit;
    }
}

$contents = file_get_contents($target_file);
// Convert Windows and old Mac line endings to Unix
$normalized = preg_replace("/\r\n?/", "\n", $contents);
file_put_contents($target_file, $normalized);
log_action("Normalized line endings to Unix for: $target_file");

log_action("Counting numbers and duplicates in CSV: $target_file");
$frequency = [];
$totalNumbers = 0;
$phoneColumnIndex = null;
$hasHeader = true;  

$csvHandle = fopen($target_file, 'r');
if ($csvHandle == false) {
    log_action("Failed to open CSV file for counting: $target_file");
} else {
    // Read and detect header row
    $headerRow = fgetcsv($csvHandle);
    if ($headerRow == false) {
        log_action("Empty CSV file: $target_file");
    } else {

        foreach ($headerRow as $index => $columnName) {
            if (stripos($columnName, 'phone') != false) {
                $phoneColumnIndex = $index;
                log_action("Detected phone column: '$columnName' at index $phoneColumnIndex");
                break;
            }
        }

        // Default to first column if no phone column found
        if ($phoneColumnIndex == null) {
            $phoneColumnIndex = 0;
            log_action("No phone column detected. Using first column (index 0)");
        }

        // Process remaining rows
        while (($row = fgetcsv($csvHandle)) != false) {
            // Skip empty rows
            if ($row == [null]) continue;
            
            // Get value from identified phone column
            $number = trim($row[$phoneColumnIndex] ?? '');
            
            // Skip empty values
            if ($number === '') {
                continue;
            }
            
            $totalNumbers++;
            
            // Track frequency for duplicates
            if (!isset($frequency[$number])) {
                $frequency[$number] = 1;
            } else {
                $frequency[$number]++;
            }
        }

        // Calculate metrics
        $uniqueNumbers = count($frequency);
        $duplicateEntries = $totalNumbers - $uniqueNumbers;
        
        $distinctDuplicates = 0;
        foreach ($frequency as $count) {
            if ($count > 1) {
                $distinctDuplicates++;
            }
        }

        // Log results
        log_action("Total numbers: $totalNumbers");
        log_action("Duplicate entries (extra occurrences): $duplicateEntries");
        log_action("Distinct duplicated numbers: $distinctDuplicates");
    }
    fclose($csvHandle);
}

// Prepare the initial insert query with status 0
$query = "INSERT INTO uploaded_files 
          (file_path, status, batch_id, full_name, user_id, cost_cntr, total_count, total_distinct) 
          VALUES (?, '0', ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($query);
$stmt->bind_param("sssssii", 
    $file_escaped, 
    $batch_escaped, 
    $full_name, 
    $user_id, 
    $costCntr, 
    $totalNumbers, 
    $uniqueNumbers
);

// Try the initial insert
if (!$stmt->execute()) {
    log_action("Initial insert failed: " . $stmt->error);
    
    // Check if record already exists
    $checkQuery = "SELECT id FROM uploaded_files WHERE file_path = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $file_escaped);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();
    
    if ($exists) {
        // Update existing record to status 5
        $updateQuery = "UPDATE uploaded_files SET status = '5' WHERE file_path = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("s", $file_escaped);
        
        if (!$updateStmt->execute()) {
            log_action("Update failed: " . $updateStmt->error);
            echo json_encode([
                "status" => false, 
                "message" => "Failed to update existing file record."
            ]);
            closeConnection($conn);
            exit;
        }
        $updateStmt->close();
        
        log_action("Updated existing record to status 5: $file_escaped");
    } else {
	    // Insert new record with status 5
	    // status 5 means file upload failed
        $insertQuery = "INSERT INTO uploaded_files 
                       (file_path, status, batch_id, full_name, user_id, cost_cntr, total_count, total_distinct) 
                       VALUES (?, '5', ?, ?, ?, ?, ?, ?)";
        
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("sssssii", 
            $file_escaped, 
            $batch_escaped, 
            $full_name, 
            $user_id, 
            $costCntr, 
            $totalNumbers, 
            $uniqueNumbers
        );
        
        if (!$insertStmt->execute()) {
            log_action("Fallback insert failed: " . $insertStmt->error);
            echo json_encode([
                "status" => false, 
                "message" => "Failed to insert fallback file record."
            ]);
            closeConnection($conn);
            exit;
        }
        $insertStmt->close();
        
        log_action("Inserted new record with status 5: $file_escaped");
    }
    
    echo json_encode([
        "status" => false, 
        "message" => "Initial insert failed, but fallback operation completed."
    ]);
    closeConnection($conn);
    exit;
}

// If we got here, initial insert succeeded
log_action("File record inserted successfully: $file_escaped");
$stmt->close();

closeConnection($conn);

