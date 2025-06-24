<?php

require './conn.php';

$conn  = getConnection();

function wh_log($log_msg)
{
    $log_filename = "/var/www/html/powersmpp/ubabulk/ubalog";
    if (!file_exists($log_filename)) {
        mkdir($log_filename, 0777, true);
    }
    $log_file_data = $log_filename . '/log_' . date('d-M-Y') . '.log';
    file_put_contents($log_file_data, date('Y-m-d H:i:s') . " - " . $log_msg . "\n", FILE_APPEND);
}

$request = $_REQUEST;

// Sanitize & extract
$id = $request['smsID'] ?? '';
$type = $request['type'] ?? '';
$str = $request['message'] ?? '';
$sMobileNo = $request['phone'] ?? '';
$sSender = $request['sender'] ?? '';

// Log request
wh_log("Incoming Request: " . json_encode($request));

if (empty($id) || empty($type)) {
    wh_log("Missing required fields: smsID or type.");
    exit("Missing required data.");
}

if ($type == 8 || $type == 16) {
    wh_log("Type $type received for smsID $id, ignoring as per business rule.");
    return;
}

// Parse message
$parts = explode(' ', $str);
$result = [];
$num = 1;

foreach ($parts as $part) {
    $keyValue = explode(':', $part);
    if ($num == 1 && $keyValue[0] == 'date') {
        $result['submitDate'] = convertTime($keyValue[1]);
        $num++;
    } elseif ($num == 2 && $keyValue[0] == 'date') {
        $result['doneDate'] = convertTime($keyValue[1]);
    } else {
        $result[$keyValue[0]] = $keyValue[1] ?? '';
    }
}
wh_log("Parsed DLR for smsID $id: " . json_encode($result));

// Extract status and error code
$stat = $result['stat'] ?? 'unknown';
preg_match('/err:(\d+)/', $str, $matches);
$errorCode = $matches[1] ?? '0';
$sOperatorName = getNetwork($sMobileNo);

// Fetch message timestamp
$query = "SELECT created_at FROM messages WHERE id = '$id'";
$dbResult = $conn->query($query);

if ($dbResult && $dbResult->num_rows > 0) {
    $row = $dbResult->fetch_assoc();
    $createdAt = strtotime($row['created_at']);
    $randomDoneTimestamp = rand($createdAt, $createdAt + 60);
    $doneDate = date('Y-m-d H:i:s', $randomDoneTimestamp);
    wh_log("Fetched created_at for smsID $id: " . $row['created_at'] . ", using doneDate: $doneDate");
} else {
    wh_log("Error fetching created_at for ID $id: " . $conn->error);
    exit("Error fetching message data.");
}

// Update message
$updateQuery = "UPDATE messages 
                SET dlr_status = '$stat', dlr_request = '$errorCode', 
                    dlr_results = '$id', 
                    updated_at = '$doneDate' 
                WHERE id = '$id'";

$updateResult = $conn->query($updateQuery);

if ($updateResult) {
    if ($conn->affected_rows === 1) {
        wh_log("Successfully updated smsID $id with status: $stat, errorCode: $errorCode, operator: $sOperatorName");
    } else {
        wh_log("No update occurred for smsID $id (Affected rows: 0).");
    }
} else {
    wh_log("Database update error for smsID $id: " . $conn->error);
    exit("Database update error.");
}

closeConnection($conn);

function convertTime($timestamp)
{
    $timestamp_str = strval($timestamp);
    $date_time = DateTime::createFromFormat('ymdHi', $timestamp_str);
    return $date_time ? $date_time->format('Y-m-d H:i') : null;
}

function getNetwork($number)
{
    $number = preg_replace('/^234/', '0', $number);
    $prefix = substr($number, 0, 4);

    switch ($prefix) {
        case '0701': case '0708': case '0802': case '0804': case '0808': case '0812':
            return 'Airtel';
        case '0702': case '07025': case '07026': case '07027': case '07028': case '07029':
        case '0703': case '0704': case '0706': case '0707': case '0709':
        case '0803': case '0806': case '0810': case '0813': case '0814': case '0816': case '0819':
            return 'MTN';
        case '0705': case '0805': case '0807': case '0811': case '0815':
            return 'Globacom';
        case '0809': case '0817': case '0818': case '0909':
            return '9mobile';
        default:
            return 'Unknown';
    }
}

