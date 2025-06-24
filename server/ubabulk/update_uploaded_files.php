<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once 'conn.php';
require_once 'log.php';

$conn = getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// Check if the request method is POST
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get input data
$data = json_decode(file_get_contents("php://input"), true);

$batch_id  = $data['batch_id']  ?? null;
$message      = $data['message']      ?? null;
$sender_id = $data['sender_id'] ?? null;
$msg_cat   = $data['msg_cat']   ?? null;

if (!$batch_id || !$message || !$sender_id || !$msg_cat) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE uploaded_files SET message = ?, sender_id = ?, batch_id = ?, msg_cat = ? WHERE batch_id = ?");
    $stmt->bind_param("sssss", $message, $sender_id, $batch_id, $msg_cat, $batch_id);

    if ($stmt->execute()) {
        log_action("Batch ID $batch_id updated successfully.");
        echo json_encode(['status' => true, 'message' => 'Record updated successfully']);
    } else {
        log_action("Failed to update Batch ID $batch_id: " . $stmt->error);
        echo json_encode(['status' => false, 'error' => 'Update failed: ' . $stmt->error]);
    }

    $stmt->close();
} catch (Exception $e) {
    log_action("Exception on update: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}