<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json');
require_once './conn.php'; // adjust path if needed

$phoneNumber = $_REQUEST['phoneNumber'] ?? '';
$startDate = $_REQUEST['startDate'] ?? null;
$endDate = $_REQUEST['endDate'] ?? null;

$conn = getConnection();

$response = [];

if (empty($phoneNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Phone number is required']);
    exit;
}

try {
    $sql = "SELECT id, msisdn, text, dlr_request, dlr_status, network, senderid, created_at FROM messages WHERE msisdn = ?";
    
    // Add date range filter if provided
    if ($startDate && $endDate) {
        $sql .= " AND created_at BETWEEN ? AND ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL error: " . $conn->error);
    }

    // Bind parameters
    if ($startDate && $endDate) {
        $startDateFormatted = $startDate . " 00:00:00";
        $endDateFormatted = $endDate . " 23:59:59";
        $stmt->bind_param("sss", $phoneNumber, $startDateFormatted, $endDateFormatted);
    } else {
        $stmt->bind_param("s", $phoneNumber);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }

    echo json_encode($messages);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'details' => $e->getMessage()]);
}


closeConnection($conn);

