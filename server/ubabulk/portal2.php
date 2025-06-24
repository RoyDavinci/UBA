<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

require './conn.php';

date_default_timezone_set('Africa/Lagos');

$start = date('Y-m-d') . ' 00:00:00';
$end   = date('Y-m-d') . ' 23:59:59';

$conn = getConnection();

// Fetch messages for today
$sql = "SELECT msisdn, network, dlr_status, created_at
        FROM messages
        WHERE created_at BETWEEN '$start' AND '$end'
        ORDER BY created_at DESC LIMIT 1000";

$result = $conn->query($sql);

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
        $network = 'MTN'; // Default fallback
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

// Calculate delivery rates
$deliveryRates = [];
foreach ($statusSummary as $network => $sums) {
    $deliveryRates[$network] = $sums['total'] > 0
        ? round(($sums['delivered'] / $sums['total']) * 100, 2)
        : 0;
}

// Fetch total counts
$queuesTotal = $conn->query("SELECT COUNT(*) AS total FROM queues")->fetch_assoc()['total'] ?? 0;
$messagesTotal = $conn->query("SELECT COUNT(*) AS total FROM messages")->fetch_assoc()['total'] ?? 0;
$pagesTotal = $conn->query("SELECT SUM(pages) AS total FROM messages")->fetch_assoc()['total'] ?? 0;
$duplicateTotal = $conn->query("SELECT COUNT(id) AS total FROM duplicate")->fetch_assoc()['total'] ?? 0;

echo json_encode([
    'status' => 'success',
    'date' => date('Y-m-d'),
    'data' => $dataByNetwork,
    'status_summary' => $statusSummary,
    'delivery_rates' => $deliveryRates,
    'totals' => [
        'queues' => (int)$queuesTotal,
	'messages' => (int)$messagesTotal,
	'pages' => (int)$pagesTotal,
	'duplicate'  => (int)$duplicateTotal,
    ]
]);

closeConnection($conn);

