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
$message   = $data['message'] ?? null;
$sender_id = $data['sender_id'] ?? null;
$msg_cat   = $data['msg_cat']   ?? null;
$params1   = $data['params1']   ?? null;
$params2   = $data['params2']   ?? null;
$params3   = $data['params3']   ?? null;
$params4   = $data['params4']   ?? null;
$params5   = $data['params5']   ?? null;
$phoneHeader   = $data['phoneHeader']   ?? null;

if (!$batch_id || !$message || !$sender_id || !$msg_cat) {
    //    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    if (!empty($data['shcduleAt'])) {
        try {
            $date = new DateTime($data['shcduleAt']);
            $scheduled_at = $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log("Invalid date format for scheduled_at: " . $data['shcduleAt']);
            $scheduled_at = null;
        }
    } else {
        $scheduled_at = null;
    }

    $query  = "UPDATE uploaded_files SET message = '$message', senderid = '$sender_id', batch_id = '$batch_id', msg_cat = '$msg_cat', schedule_time = '$scheduled_at', params1 = '$params1', params2 = '$params2', params3 = '$params3', params4 = '$params4', params5 = '$params5', phoneHeader = '$phoneHeader', WHERE batch_id = '$batch_id'";
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
