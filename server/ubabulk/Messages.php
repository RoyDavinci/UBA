<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once './conn.php';
require_once './log.php';
require_once './JWTUser.php'; 

$conn = getConnection();
$action = $_REQUEST['action'] ?? null;
$userData = getUserFromJWT();
$user_id = $userData['user_id'] ?? null;
$full_name = $userData['full_name'] ?? null;

switch ($action) {
    case 'create':
        $category_name = $_REQUEST['category_name'] ?? null;

        if (!$category_name) {
            echo json_encode(['status' => false, 'error' => 'Category Name is required']);
            break;
        }

        $sql = "INSERT INTO msg_cat (category_name) VALUES ('$category_name')";
        if (mysqli_query($conn, $sql)) {
            audit_log($conn, $user_id, $full_name, "Created message category: $category_name");
            echo json_encode(['status' => true, 'message' => 'Category added successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'read':
        $result = mysqli_query($conn, "SELECT * FROM msg_cat ORDER BY id DESC");
        $categories = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $categories[] = $row;
        }
        echo json_encode(['status' => true, 'data' => $categories]);
        break;

    case 'update':
        $id = $_REQUEST['id'] ?? null;
        $category_name = $_REQUEST['category_name'] ?? null;

        if (!$id || !$category_name) {
            echo json_encode(['status' => false, 'error' => 'ID and Category Name are required']);
            break;
        }

        $sql = "UPDATE msg_cat SET category_name = '$category_name' WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            audit_log($conn, $user_id, $full_name, "Updated message category: $category_name");
            echo json_encode(['status' => true, 'message' => 'Category updated successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    case 'delete':
        $id = $_REQUEST['id'] ?? null;
        if (!$id) {
            echo json_encode(['status' => false, 'error' => 'Category ID is required']);
            break;
        }

        $sql = "DELETE FROM msg_cat WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            audit_log($conn, $user_id, $full_name, "Deleted message category with ID: $id");
            echo json_encode(['status' => true, 'message' => 'Category deleted successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => mysqli_error($conn)]);
        }
        break;

    default:
        echo json_encode(['status' => false, 'error' => 'Invalid or missing action']);
        break;
}
