<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE"); // Consider limiting to POST if this script only handles uploads.  OPTIONS might also be needed.
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
require './conn.php';
require './log.php';
require './jwt.php';

$conn = getConnection();
if (!$conn) {
    log_action("Failed to connect to the database.");
    echo json_encode(["status" => false, "message" => "Database connection error."]);
    exit; // Stop execution if the database connection fails.
}

function getHeaders()
{
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        log_action("Using apache_request_headers()");
        return $headers;
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        log_action("Using getallheaders()");
        return $headers;
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        log_action(" ম্যানুয়ালি তৈরি করা শিরোনাম: " . json_encode($headers));
        return $headers;
    }
}

// Get headers
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
log_action("Authorization Token: $token"); // গুরুত্বপূর্ণ: লগ টোকেন

try {
    $decoded = decodeJWT($token);
    $user_id = $decoded['id'] ?? null;
    $role = $decoded['role'] ?? null;
    log_action("JWT Decoded: User ID: $user_id, Role: $role");

    if (!$user_id || !$role) {
        log_action("Invalid JWT payload.");
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        exit;
    }

    // Merge JSON and REQUEST data
    $data = json_decode(file_get_contents("php://input"), true);
    $data = is_array($data) ? array_merge($_REQUEST, $data) : $_REQUEST;
    log_action("Merged input data: " . json_encode($data)); // Log merged data

    if ($role === 'super_admin') {
        $organization_id = (int)($data['organization_id'] ?? 0);
        log_action("Role is super_admin. Organization ID from input: $organization_id");
        if (!$organization_id) {
            log_action("Organization ID required for super_admin.");
            echo json_encode(["status" => false, "message" => "Organization ID required for super_admin."]);
            exit;
        }
    } else {
        $res = $conn->query("SELECT organization_id FROM users WHERE id = $user_id LIMIT 1");
        if (!$res) {
            log_action("Error querying for organization ID: " . $conn->error);
            echo json_encode(["status" => false, "message" => "Database error fetching organization."]);
            closeConnection($conn);
            exit;
        }
        if ($res && $row = $res->fetch_assoc()) {
            $organization_id = (int)$row['organization_id'];
            log_action("Organization ID for user $user_id: $organization_id");
        } else {
            log_action("Unable to find user's organization.");
            echo json_encode(["status" => false, "message" => "Unable to find user's organization."]);
            closeConnection($conn);
            exit;
        }
    }

    $group_id = (int)($data['group_id'] ?? 0);
    $action = $data['action'] ?? 'add';
    log_action("Group ID: $group_id, Action: $action");

    if (!$group_id || !in_array($action, ['add', 'delete'])) {
        log_action("Invalid request parameters.");
        echo json_encode(["status" => false, "message" => "Invalid request."]);
        exit;
    }

    $check_query = "SELECT id, name FROM sms_groups WHERE id = $group_id AND organization_id = $organization_id";
    log_action("Checking group: $check_query");
    $check = $conn->query($check_query);
    if (!$check) {
        log_action("Database error checking group: " . $conn->error);
        echo json_encode(["status" => false, "message" => "Database error."]);
        closeConnection($conn);
        exit;
    }
    if ($check->num_rows === 0) {
        log_action("Unauthorized group access attempt: group_id $group_id by user $user_id in org $organization_id");
        echo json_encode(["status" => false, "message" => "Group not found or unauthorized."]);
        closeConnection($conn);
        exit;
    }

    $group = $check->fetch_assoc();
    $group_name = $group['name'];
    log_action("Group found: ID: $group_id, Name: $group_name");

    // Handle file upload and parsing
    $numbers = [];

    if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
        $file_error_code = $_FILES['file']['error'] ?? 'No file error code';
        log_action("File upload failed. Error code: $file_error_code");
        echo json_encode(["status" => false, "message" => "File upload failed. Error code: $file_error_code"]);
        closeConnection($conn);
        exit;
    }

    $file_name = $_FILES['file']['name'];
    $file_tmp  = $_FILES['file']['tmp_name'];
    $file_size = $_FILES['file']['size'];
    $max_size = 50 * 1024 * 1024; // 50MB
    log_action("Uploaded file: $file_name, size: $file_size");

    if ($file_size > $max_size) {
        log_action("File too large: $file_size bytes.");
        echo json_encode(["status" => false, "message" => "File size too large (max 50MB)."]);
        closeConnection($conn);
        exit;
    }

    $file_path = tempnam(sys_get_temp_dir(), 'uploaded_file_');
    if (!move_uploaded_file($file_tmp, $file_path)) {
        log_action("Failed to move uploaded file.");
        echo json_encode(["status" => false, "message" => "Failed to move uploaded file."]);
        closeConnection($conn);
        exit;
    }
    log_action("Uploaded file moved to: $file_path");

    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $csv_file_path = $file_path;
    log_action("File extension: $file_extension");

    if ($file_extension === 'xlsx' || $file_extension === 'xls') {
        // Convert Excel to CSV
        try {
            $spreadsheet = IOFactory::load($file_path);
            $writer = IOFactory::createWriter($spreadsheet, 'Csv');
            $csv_file_path = tempnam(sys_get_temp_dir(), 'converted_csv_');
            $writer->save($csv_file_path);
            log_action("Excel converted to CSV: $csv_file_path");
        } catch (Exception $e) {
            log_action("Error converting Excel to CSV: " . $e->getMessage());
            echo json_encode(["status" => false, "message" => "Error converting Excel to CSV: " . $e->getMessage()]);
            closeConnection($conn);
            unlink($file_path);
            exit;
        } finally {
            unlink($file_path); // Delete original file after conversion
        }
    } elseif ($file_extension !== 'csv') {
        log_action("Invalid file type: $file_extension");
        echo json_encode(["status" => false, "message" => "Invalid file type. Only CSV and Excel are allowed."]);
        closeConnection($conn);
        unlink($file_path); //delete the file
        exit;
    }

    // Now parse CSV file
    if (($handle = fopen($csv_file_path, 'r')) === false) {
        log_action("Failed to open CSV file: $csv_file_path");
        echo json_encode(["status" => false, "message" => "Failed to open CSV file."]);
        closeConnection($conn);
        unlink($csv_file_path);
        exit;
    }
    log_action("CSV file opened: $csv_file_path");

    // Read header row to find msisdn/phone column (case-insensitive)
    $header = fgetcsv($handle);
    if (!$header) {
        log_action("Empty CSV file or failed to read header.");
        echo json_encode(["status" => false, "message" => "CSV file is empty or invalid."]);
        closeConnection($conn);
        fclose($handle);
        unlink($csv_file_path);
        exit;
    }
    log_action("CSV Header: " . json_encode($header));

    $phone_col_index = null;
    foreach ($header as $index => $col_name) {
        $col_name_lower = strtolower(trim($col_name));
        if (in_array($col_name_lower, ['msisdn', 'phone', 'mobile'])) { //Added mobile
            $phone_col_index = $index;
            break;
        }
    }

    if ($phone_col_index === null) {
        log_action("No 'msisdn', 'phone', or 'mobile' column found in CSV header: " . json_encode($header));
        echo json_encode(["status" => false, "message" => "CSV must contain a column named 'msisdn', 'phone', or 'mobile'."]);
        closeConnection($conn);
        fclose($handle);
        unlink($csv_file_path);
        exit;
    }
    log_action("Phone column index: $phone_col_index");

    // Read phone numbers from identified column
    while (($row = fgetcsv($handle)) !== false) {
        if (isset($row[$phone_col_index]) && trim($row[$phone_col_index]) !== '') {
            $numbers[] = trim($row[$phone_col_index]);
        }
    }
    log_action("Numbers extracted from CSV: " . count($numbers));

    fclose($handle);
    unlink($csv_file_path); //delete the csv file.

    if (empty($numbers)) {
        log_action("No phone numbers found in the CSV file.");
        echo json_encode(["status" => false, "message" => "No phone numbers found in the CSV file."]);
        closeConnection($conn);
        exit;
    }

    $success = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($numbers as $number) {
        $cleaned = preg_replace('/\D/', '', $number); // Remove non-digits
        if (str_starts_with($cleaned, '0') && strlen($cleaned) === 11) {
            $cleaned = '234' . substr($cleaned, 1);
        }

        if (!preg_match('/^234[0-9]{10}$/', $cleaned)) {
            log_action("Skipped invalid number: $number (normalized: $cleaned)");
            $skipped++;
            continue;
        }

        $escapedNumber = $conn->real_escape_string($cleaned); // Use cleaned number
        $query = '';

        if ($action === 'add') {
            $query = "INSERT INTO sms_group_numbers (group_id, phone_number) VALUES ($group_id, '$escapedNumber')";
        } else {
            $query = "DELETE FROM sms_group_numbers WHERE group_id = $group_id AND phone_number = '$escapedNumber'";
        }
        log_action("Executing query: $query");

        if ($conn->query($query)) {
            $success++;
            log_action("Successfully executed '$action' for number: $cleaned in group $group_id"); // ইউজ করুন $cleaned
        } else {
            $failed++;
            log_action("Failed to execute '$action' for number: $cleaned in group $group_id - Error: " . $conn->error); // ইউজ করুন $cleaned
        }
    }

    log_action("User $user_id ($role) performed '$action' on group '$group_name' (ID: $group_id) in org $organization_id. Total: " . count($numbers) . ", Success: $success, Skipped: $skipped, Failed: $failed");

    echo json_encode([
        "status" => true,
        "message" => "$action complete.",
        "total" => count($numbers),
        "successful" => $success,
        "failed" => $failed,
        "skipped" => $skipped
    ]);
} catch (Exception $e) {
    log_action("Exception: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Error: " . $e->getMessage()]); // General error message
} finally {
    closeConnection($conn); // Close the connection in a finally block
}
?>

