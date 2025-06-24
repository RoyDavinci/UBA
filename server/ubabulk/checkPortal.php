<?php
require '/var/www/html/bulksms/ubabulk/conn.php';
require '/var/www/html/bulksms/ubabulk/log.php';

date_default_timezone_set('Africa/Lagos');
$conn = getConnection();

log_action("checkPortal.php started");

// Check if 'uba' status is 0
$check = $conn->query("SELECT status FROM status_tables WHERE table_name = 'uba' LIMIT 1");

if (!$check) {
    log_action("Failed to query status_tables: " . $conn->error);
    closeConnection($conn);
    exit;
}

if ($check->num_rows === 0) {
    $conn->query("INSERT INTO status_tables (table_name, status) VALUES ('uba', 0)");
    log_action("Inserted initial row for 'uba' in status_tables");
    $status = 0;
} else {
    $status = (int)$check->fetch_assoc()['status'];
    log_action("Fetched status for 'uba': $status");
}

if ($status !== 0) {
    log_action("Status is not 0, exiting script.");
    closeConnection($conn);
    exit;
}

// Set to 1 to lock processing
$lock = $conn->query("UPDATE status_tables SET status = 1 WHERE table_name = 'uba'");
if (!$lock) {
    log_action("Failed to lock status_tables row: " . $conn->error);
    closeConnection($conn);
    exit;
}
log_action("Locked 'uba' in status_tables for processing.");

$start = date('Y-m-d') . ' 00:00:00';
$end   = date('Y-m-d') . ' 23:59:59';

$sql = "SELECT msisdn, network, dlr_status, created_at
        FROM messages
        WHERE created_at BETWEEN '$start' AND '$end'
        ORDER BY created_at DESC LIMIT 1000";

$result = $conn->query($sql);
if (!$result) {
    log_action("Failed to fetch messages: " . $conn->error);
    $conn->query("UPDATE status_tables SET status = 0 WHERE table_name = 'uba'");
    closeConnection($conn);
    exit;
}
log_action("Fetched latest 1000 messages");

$networks = ['MTN', 'Airtel', 'Glo', '9mobile'];
$dataByNetwork = [];
$statusSummary = [];

foreach ($networks as $net) {
    $dataByNetwork[$net] = [];
    $statusSummary[$net] = [
        'total' => 0,
        'delivered' => 0,
        'expired' => 0,
        'undelivered' => 0,
        'rejected' => 0,
        'pending' => 0,
    ];
}

while ($row = $result->fetch_assoc()) {
    $network = $row['network'];
    $dlr = strtoupper(trim($row['dlr_status'] ?? ''));

    if (!isset($dataByNetwork[$network])) {
        $network = 'MTN';
    }

    $dataByNetwork[$network][] = $row;
    $statusSummary[$network]['total']++;

    if ($dlr === 'DELIVRD') {
        $statusSummary[$network]['delivered']++;
    } elseif (in_array($dlr, ['EXPIRD', 'EXPIRED'])) {
        $statusSummary[$network]['expired']++;
    } elseif ($dlr === 'UNDELIV') {
        $statusSummary[$network]['undelivered']++;
    } elseif (in_array($dlr, ['REJECTD', 'REJECTED'])) {
        $statusSummary[$network]['rejected']++;
    } elseif ($dlr === '' || is_null($dlr)) {
        $statusSummary[$network]['pending']++;
    }
}
log_action("Processed delivery stats");

// Delivery rate
$deliveryRates = [];
foreach ($statusSummary as $network => $sums) {
    $deliveryRates[$network] = $sums['total'] > 0
        ? round(($sums['delivered'] / $sums['total']) * 100, 2)
        : 0;
}

// Other totals
$queuesTotal = $conn->query("SELECT COUNT(*) AS total FROM queues")->fetch_assoc()['total'] ?? 0;
$messagesTotal = $conn->query("SELECT COUNT(*) AS total FROM messages")->fetch_assoc()['total'] ?? 0;
$pagesTotal = $conn->query("SELECT SUM(pages) AS total FROM messages")->fetch_assoc()['total'] ?? 0;
$duplicateTotal = $conn->query("SELECT COUNT(id) AS total FROM duplicate")->fetch_assoc()['total'] ?? 0;

$response = [
    'status' => 'success',
    'date' => date('Y-m-d'),
    'data' => $dataByNetwork,
    'status_summary' => $statusSummary,
    'delivery_rates' => $deliveryRates,
    'totals' => [
        'queues' => (int)$queuesTotal,
        'messages' => (int)$messagesTotal,
        'pages' => (int)$pagesTotal,
        'duplicate' => (int)$duplicateTotal,
    ]
];

// Save response to DB
$today = date('Y-m-d');
$json = $conn->real_escape_string(json_encode($response));

$checkSummary = $conn->query("SELECT id FROM message_summary WHERE summary_date = '$today' LIMIT 1");

if ($checkSummary && $checkSummary->num_rows > 0) {
    $row = $checkSummary->fetch_assoc();
    $id = $row['id'];
    $update = "UPDATE message_summary SET summary_json = '$json', created_at = NOW() WHERE id = $id";
    $conn->query($update);
    log_action("Updated message_summary ID $id for $today");
} else {
    $insert = "INSERT INTO message_summary (summary_json, summary_date) VALUES ('$json', '$today')";
    $conn->query($insert);
    log_action("Inserted new message_summary for $today");
}

// Release lock
$unlock = $conn->query("UPDATE status_tables SET status = 0 WHERE table_name = 'uba'");
if ($unlock) {
    log_action("Unlocked 'uba' in status_tables (set status = 0)");
} else {
    log_action("Failed to unlock 'uba': " . $conn->error);
}

log_action("checkPortal.php completed");
closeConnection($conn);

