<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';

$conn = getConnection();
$data = json_decode(file_get_contents("php://input"), true) ?? $_REQUEST;


function getHeaders()
{
    if (function_exists('apache_request_headers')) {
        return apache_request_headers();
    } elseif (function_exists('getallheaders')) {
        return getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}

// Get headers
$headers = getHeaders();
log_action("Incoming Headers". json_encode($headers));

if (!isset($headers['Authorization'])) {
    log_action("Authorization Header Missing");
    echo json_encode(['message' => "Authorization header is required", 'status' =>false]);
    exit;
}

$authHeader = $headers['Authorization'];
$parts = explode(' ', $authHeader);

if (count($parts) !== 2 || strcasecmp($parts[0], 'Bearer') !== 0) {
    log_action("Invalid Authorization Header Format ['header' => $authHeader]");
    echo json_encode(['message' => "Invalid Authorization header. Expected format: 'Bearer <token>'", 'status' => false]);
    exit;
}

$token = $parts[1];

try {
    $decoded = decodeJWT($token);
    $user_id = $decoded['id'] ?? null;
    $role    = $decoded['role'] ?? null;

    if (!$user_id || !$role) {
        echo json_encode(["status" => false, "message" => "Invalid token payload."]);
        exit;
    }
} catch (Exception $e) {
    log_action("JWT decode failed: " . $e->getMessage());
    echo json_encode(["status" => false, "message" => "Invalid token: " . $e->getMessage()]);
    closeConnection($conn);
    exit;
}

// 2. Determine organization_id
$organization_id = 0;
if ($role === 'super_admin') {
    $organization_id = $data['organization_id'] ?? null;
    if (!$organization_id) {
        echo json_encode(["status" => false, "message" => "Organization ID is required for super_admin."]);
        closeConnection($conn);
        exit;
    }
} else {
    $org_query = "SELECT organization_id FROM users WHERE id = $user_id LIMIT 1";
    $org_result = $conn->query($org_query);

    if ($org_result && $org_row = $org_result->fetch_assoc()) {
        $organization_id = $org_row['organization_id'];
    } else {
        echo json_encode(["status" => false, "message" => "Unable to determine organization for user."]);
        closeConnection($conn);
        exit;
    }
}

// 3. Validate Data
$messages = $data['messages'] ?? [];
$group_id = isset($data['group_id']) ? intval($data['group_id']) : null;
$status = 0; // set default status

if (empty($messages)) {
    echo json_encode(["status" => false, "message" => "Messages are required."]);
    closeConnection($conn);
    exit;
}

// Function to insert a single message into the queue
function insertMessage($conn, $organization_id, $group_id, $msisdn, $text, $status, $sequence, $user_id)
{
    $msisdn = trim($conn->real_escape_string($msisdn));
    $text = trim($conn->real_escape_string($text));
    $query = "INSERT INTO queues (organization_id, group_id, msisdn, text, status, sequence) VALUES ($organization_id, " . ($group_id === null ? 'NULL' : $group_id) . ", '$msisdn', '$text', $status, $sequence)";
    if ($conn->query($query)) {
        return true;
    } else {
        return false;
    }
}

// 4. Handle Single or Multiple Inserts for messages
$success = true;
foreach ($messages as $message) {
    $msisdn = $message['number'] ?? '';
    $text = $message['message'] ?? '';

    if (!$msisdn || !$text) {
        $success = false;
        break;
    }

    $sequence = random_int(100000, 999999);
    if (!insertMessage($conn, $organization_id, $group_id, $msisdn, $text, $status, $sequence, $user_id)) {
        $success = false;
        break;
    }
}

if ($success) {
    $message = "Messages queued successfully.";
    echo json_encode(["status" => true, "message" => $message]);
    log_action("$message. User ID: $user_id, Organization: $organization_id, Group ID: $group_id");
} else {
    $message = "Failed to queue some messages: " . $conn->error;
    echo json_encode(["status" => false, "message" => $message]);
    log_action($message);
}

closeConnection($conn);

function getNetwork($msisdn)
{
    if (!is_string($msisdn)) {
        return "MTN";
    }

    $number = preg_replace('/^234/', '0', $msisdn);
    $prefix5 = substr($number, 0, 5);
    $prefix4 = substr($number, 0, 4);

    switch ($prefix5) {
        case "07025":
        case "07026":
        case "07027":
            return "MTN";
    }

    switch ($prefix4) {
            // Airtel
        case "0701":
        case "0708":
        case "0802":
        case "0808":
        case "0812":
        case "0901":
        case "0902":
        case "0907":
        case "0911":
        case "0912":
            return "Airtel";

            // MTN
        case "0703":
        case "0704":
        case "0706":
        case "0803":
        case "0804":
        case "0806":
        case "0810":
        case "0813":
        case "0814":
        case "0816":
        case "0903":
        case "0906":
        case "0913":
        case "0916":
            return "MTN";

            // Globacom
        case "0705":
        case "0805":
        case "0807":
        case "0811":
        case "0815":
        case "0905":
        case "0915":
            return "Globacom";

            // 9mobile
        case "0809":
        case "0817":
        case "0818":
        case "0908":
        case "0909":
            return "9mobile";

        default:
            return "MTN";
    }
}
