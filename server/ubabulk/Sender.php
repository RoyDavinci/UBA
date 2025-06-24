<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once 'conn.php';
require_once 'log.php';

$conn = getConnection();
$action = $_REQUEST['action'] ?? null;

switch ($action) {
    case 'create':
        $sender_name = $_REQUEST['sender_name'] ?? null;

        if (!$sender_name) {
            echo json_encode(['status' => false, 'error' => 'Sender name is required']);
            break;
        }

        $sql = "INSERT INTO senderid (sender_name) VALUES ('$sender_name')";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['status' => true, 'message' => 'Sender added successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'read':
        $result = mysqli_query($conn, "SELECT * FROM senderid ORDER BY id DESC");
        $senders = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $senders[] = $row;
        }

        echo json_encode(['status' => true, 'data' => $senders]);
        break;

    case 'update':
        $id = $_REQUEST['id'] ?? null;
        $sender_name = $_REQUEST['sender_name'] ?? null;

        if (!$id || !$sender_name) {
            echo json_encode(['status' => false, 'error' => 'ID and sender name are required']);
            break;
        }

        $sql = "UPDATE senderid SET sender_name = '$sender_name' WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['status' => true, 'message' => 'Sender updated successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'delete':
        $id = $_REQUEST['id'] ?? null;

        if (!$id) {
            echo json_encode(['status' => false, 'error' => 'Sender ID is required']);
            break;
        }

        $sql = "DELETE FROM senderid WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['status' => true, 'message' => 'Sender deleted successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    default:
        echo json_encode(['status' => false, 'error' => 'Invalid or missing action']);
        break;
}
