<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

require_once 'conn.php';
require_once 'log.php';

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);
log_action("Received file data: " . json_encode($data));

$batch_id  = $data['batch_id']  ?? null;
$message      = $data['message'] ?? null;
$sender_id = $data['sender_id'] ?? null;
$msg_cat   = $data['msg_cat']   ?? null;

if (!$batch_id || !$message || !$sender_id || !$msg_cat) {
    //    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $query  = "UPDATE uploaded_files SET message = '$message', senderid = '$sender_id', batch_id = '$batch_id', msg_cat = '$msg_cat' WHERE batch_id = '$batch_id'";
    $result = mysqli_query($conn, $query);
    log_action($query);

    if ($result) {
        log_action("Batch ID $batch_id updated successfully.");
        echo json_encode(['status' => true, 'message' => 'Record updated successfully']);
    } else {
        log_action("Failed to update Batch ID $batch_id: " . mysqli_error($conn));
        echo json_encode(['status' => false, 'message' => 'Update failed: ' . mysqli_error($conn)]);
    }
    closeConnection($conn);
} catch (Exception $e) {
    log_action("Exception on update: " . $e->getMessage());
    echo json_encode(['status' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
