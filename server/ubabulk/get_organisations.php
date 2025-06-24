<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';

// Function to decode JWT


try {
    // Get the token from the Authorization header
    // Decode the JWT
	
	
    
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
log_action("Incoming Headers:". json_encode($headers));

if (!isset($headers['Authorization'])) {
    log_action("Authorization Header Missing");
    echo json_encode(['message' => "Authorization header is required", 'status' => false]);
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
	
	
    $decoded = decodeJWT($token);
    if (!$decoded) {
        echo json_encode([
            'status' => false,
            'message' => 'Invalid or expired token.'
        ]);
        exit;
    }

    // Fetch the user's role and organization_id
    $user_id = $decoded['id'];
    $role = $decoded['role'];

    // SQL query to fetch organizations based on user role
    if ($role == 'super_admin') {
        // If the user is a super_admin, fetch all organizations
        $sql = "SELECT id, name FROM organisations ORDER BY name ASC";
    } else {
        // For other roles, fetch organizations associated with the user
        $sql = "SELECT id, name FROM organisations WHERE id IN (SELECT organization_id FROM users WHERE id = ?) ORDER BY name ASC";
    }

    $stmt = $conn->prepare($sql);
    if ($role != 'super_admin') {
        $stmt->bind_param("i", $user_id);  // Bind user ID if not super_admin
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $organisations = [];

    while ($row = $result->fetch_assoc()) {
        $organisations[] = [
            'id' => $row['id'],
            'name' => $row['name']
        ];
    }

    echo json_encode([
        'status' => true,
        'organisations' => $organisations
    ]);

    log_action("User ID: $user_id successfully fetched " . count($organisations) . " organisations.");
} catch (Exception $e) {
    log_action("Error fetching organisations: " . $e->getMessage());

    echo json_encode([
        'status' => false,
        'message' => 'Failed to fetch organisations.'
    ]);
}
