<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './log.php';

$data = $_REQUEST;

log_action("Received file data: " . json_encode($data));
log_action("Raw POST: " . json_encode($_POST));
log_action("Raw FILES: " . json_encode($_FILES));
$ftp_server = "34.29.231.15";
$ftp_user = "ftp_user";
$ftp_pass = "other49p!@#";

// Check if file was uploaded
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $tmp_file = $_FILES['file']['tmp_name'];
    $file_name = basename($_FILES['file']['name']);

    // Destination path on the FTP server
    $remote_file = "uploads/" . $file_name;

    // Connect to FTP
    $ftp_conn = ftp_connect($ftp_server);
    if (!$ftp_conn) {
        log_action("Error " . $ftp_conn);
        die("Could not connect to FTP server.");
    }

    // Login
    if (!ftp_login($ftp_conn, $ftp_user, $ftp_pass)) {
        ftp_close($ftp_conn);
        log_action("Error " . $ftp_conn);
        die("FTP login failed.");
    }

    // Set passive mode
    ftp_pasv($ftp_conn, true);

    // Upload the file
    if (ftp_put($ftp_conn, $remote_file, $tmp_file, FTP_BINARY)) {
        echo "✅ File uploaded successfully to FTP: $remote_file";
    } else {
        echo "❌ Failed to upload file.";
    }

    // Close the connection
    ftp_close($ftp_conn);
} else {
    echo "No file uploaded or upload error: " . ($_FILES['file']['error'] ?? 'no file uploaded');
}

