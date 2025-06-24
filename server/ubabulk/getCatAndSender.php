<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once 'conn.php';
require_once 'log.php';

$conn = getConnection();

// Fetch all categories
function getAllCategoriesName($conn) {
    try {
        $query = "SELECT category_name FROM msg_cat ORDER BY id ASC";
        $result = mysqli_query($conn, $query);

        if (!$result) {
            log_action("Category query failed: " . mysqli_error($conn));
            return ['status' => false, 'error' => 'Category query error: ' . mysqli_error($conn)];
        }

        $categories = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $categories[] = $row;
        }

        log_action("Fetched categories: " . json_encode($categories));
        return ['status' => true, 'data' => $categories, 'count' => count($categories)];

    } catch (Exception $e) {
        log_action("Exception fetching categories: " . $e->getMessage());
        return ['status' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}

// Fetch all senders
function getAllSenderName($conn) {
    try {
        $query = "SELECT sender_name FROM senderid ORDER BY id ASC";
        $result = mysqli_query($conn, $query);

        if (!$result) {
            log_action("Sender query failed: " . mysqli_error($conn));
            return ['status' => false, 'error' => 'Sender query error: ' . mysqli_error($conn)];
        }

        $senders = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $senders[] = $row;
        }

        log_action("Fetched senders: " . json_encode($senders));
        return ['status' => true, 'data' => $senders, 'count' => count($senders)];

    } catch (Exception $e) {
        log_action("Exception fetching senders: " . $e->getMessage());
        return ['status' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}

$batch_info = [];



function getBatchDetails($conn, $batch_id) {
    try {
        // FIXED: Corrected SQL syntax
        $stmt = $conn->prepare("SELECT * FROM uploaded_files WHERE batch_id = ? LIMIT 1");
        $stmt->bind_param("s", $batch_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $batch_info = [];
        if ($row = $result->fetch_assoc()) {
            log_action("Batch details fetched for batch_id: $batch_id");
            $batch_info[] = $row;

            // FIXED: Ensure we return the data
            return ['status' => true, 'data' => $batch_info];
        } else {
            log_action("No batch found for batch_id: $batch_id");
            return ['status' => false, 'error' => 'Batch not found'];
        }

    } catch (Exception $e) {
        log_action("Error fetching batch details: " . $e->getMessage());
        return ['status' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}



// Handle request
$method = $_SERVER['REQUEST_METHOD'];


if ($method === 'GET' || $method === 'POST') {
    log_action("Received $method request via \$_REQUEST");
    $batch_info = [];
if (!empty($_REQUEST['batch_id'])) {
    $batch_info = getBatchDetails($conn, $_REQUEST['batch_id']);
}

    $categories = getAllCategoriesName($conn);
    $senders = getAllSenderName($conn);

    $response = [
        'status' => true,
        'category' => $categories['data'] ?? [],      
        'sender' => $senders['data'] ?? [],
        'batch_info' => $batch_info['data'] ?? null
  
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
} else {
    log_action("Method not allowed: $method");
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Method not allowed']);
}
