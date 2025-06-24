<?php


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';


$data = $_REQUEST;

log_action("Received file data: " . json_encode($data));
log_action("Raw POST: " . json_encode($_POST));
log_action("Raw FILES: " . json_encode($_FILES));
$conn = getConnection();



$target_dir = "/var/www/html/bulksms/ubabulk/";
$target_file = $target_dir . basename($_FILES["file"]["name"]);
$uploadOk = 1;
$imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
$extensions = array("csv", "xls", "xslx", "zip");




// To check extensions are correct or not 
if (in_array($imageFileType, $extensions) === true) {
    $uploadOk = 1;
} else {

    echo "No file selected or Invalid file extension...";
    $uploadOk = 0;
    exit;
}

// Check if file already exists 
if (file_exists($target_file)) {

    echo "Sorry, file already exists.";
    $uploadOk = 0;
    exit;
}

// Check file size 
/*if ($_FILES["file"]["size"] > 10000000) {

    echo "Sorry, your file is too large.";
    $uploadOk = 0;
    exit;
}
 */
// Check if $uploadOk is set to 0 by an error 
if ($uploadOk == 0) {
    echo "Sorry, your file was not uploaded.";
} else {
	
    // If everything is ok, try to upload file 
    if (
        move_uploaded_file($_FILES["file"]["tmp_name"], $target_file)
    ) {
        echo "The file " . $_FILES["file"]["name"] . " has been uploaded.";
    } else {
        echo "Sorry, there was an error uploading your file.";
    }
}
