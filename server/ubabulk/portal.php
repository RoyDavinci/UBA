<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
$conn = getConnection();

date_default_timezone_set('Africa/Lagos');
$today = date('Y-m-d');

// Check if any file is still processing
$loading = false;
$checkUpload = $conn->query("SELECT COUNT(*) AS pending FROM uploaded_files WHERE status = 0");
if ($checkUpload) {
    $row = $checkUpload->fetch_assoc();
    $loading = $row['pending'] > 0;
}

// Fetch today's message summary
$sql = "SELECT summary_json FROM message_summary WHERE summary_date = '$today' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $summary = json_decode($row['summary_json'], true); // decode to modify
    $summary['loading'] = $loading;
    echo json_encode($summary);
} else {
    // Respond with default empty structure
    echo json_encode([
        'status' => 'success',
        'date' => $today,
        'loading' => $loading,
        'data' => [
            'MTN' => [],
            'Airtel' => [],
            'Glo' => [],
            '9mobile' => []
        ],
        'status_summary' => [
            'MTN' => ['total' => 0, 'delivered' => 0, 'expired' => 0, 'undelivered' => 0, 'rejected' => 0, 'pending' => 0],
            'Airtel' => ['total' => 0, 'delivered' => 0, 'expired' => 0, 'undelivered' => 0, 'rejected' => 0, 'pending' => 0],
            'Glo' => ['total' => 0, 'delivered' => 0, 'expired' => 0, 'undelivered' => 0, 'rejected' => 0, 'pending' => 0],
            '9mobile' => ['total' => 0, 'delivered' => 0, 'expired' => 0, 'undelivered' => 0, 'rejected' => 0, 'pending' => 0]
        ],
        'delivery_rates' => [
            'MTN' => 0,
            'Airtel' => 0,
            'Glo' => 0,
            '9mobile' => 0
        ],
        'totals' => [
            'queues' => 0,
            'messages' => 0,
            'pages' => 0,
            'duplicate' => 0
        ]
    ]);
}

closeConnection($conn);

