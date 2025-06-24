<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';

$conn = getConnection();

$sql = "SELECT * FROM uploaded_files ORDER BY id DESC";
$result = $conn->query($sql);

if (!$result) {
    echo json_encode([
        "status" => false,
        "message" => "Failed to fetch uploaded files: " . $conn->error
    ]);
    closeConnection($conn);
    exit;
}

$files = [];
while ($row = $result->fetch_assoc()) {
    $storedPath = $row['file_path'];
    $basename = basename($storedPath);

    $parts = explode('_', $basename);
    $originalFilename = array_pop($parts);

    $row['file_name'] = $originalFilename;

    unset($row['file_path']);

    $files[] = $row;
}

echo json_encode([
    "status" => true,
    "data" => $files
]);

closeConnection($conn);

