<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

require_once 'conn.php';
require_once 'log.php';

log_action("=== categories.php called ===");

$conn = getConnection();

// Fetch categories
$categories = [];
$query = "SELECT category_name FROM msg_cat ORDER BY id ASC";
$result = mysqli_query($conn, $query);
if (!$result) {
    log_action("Error fetching categories: " . mysqli_error($conn));
} else {
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row;
    }
    log_action("Fetched " . count($categories) . " categories");
}

// Fetch senders
$senders = [];
$query = "SELECT sender_name FROM senderid ORDER BY id ASC";
$resultSender = mysqli_query($conn, $query);
if (!$resultSender) {
    log_action("Error fetching senders: " . mysqli_error($conn));
} else {
    while ($row = mysqli_fetch_assoc($resultSender)) {
        $senders[] = $row;
    }
    log_action("Fetched " . count($senders) . " senders");
}

// Get batch info
$batch_id = $_REQUEST['batch_id'] ?? null;
$batch = [];

if (!$batch_id) {
    log_action("Missing batch_id in request.");
} else {
    log_action("Received batch_id: $batch_id");

    $safe_batch_id = mysqli_real_escape_string($conn, $batch_id);
    $query = "SELECT * FROM uploaded_files WHERE batch_id = '$safe_batch_id' LIMIT 1";
    $resultBatch = mysqli_query($conn, $query);

    if (!$resultBatch) {
        log_action("Error fetching batch info: " . mysqli_error($conn));
    } else {
        while ($row = mysqli_fetch_assoc($resultBatch)) {
            $batch[] = $row;
        }
        log_action("Fetched batch info for batch_id '$batch_id' — " . count($batch) . " records found");
    }
}

// Final response
$response = [
    'status' => true,
    'category' => $categories ?? [],
    'sender' => $senders ?? [],
    'batch_info' => $batch ?? null
];

log_action("Response ready. Sending JSON output.");
echo json_encode($response, JSON_PRETTY_PRINT);
