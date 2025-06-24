<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require_once 'conn.php';
require_once 'log.php';
require_once 'audit_log.php';

$conn = getConnection();
$action = $_REQUEST['action'] ?? null;

switch ($action) {

    case 'create':
        $category_name = $_REQUEST['category_name'] ?? null;

        if (!$category_name) {
            echo json_encode(['status' => false, 'error' => 'Category Name name is required']);
            break;
        }

        $stmt = $conn->prepare("INSERT INTO msg_cat (category_name) VALUES (?)");
        $stmt->bind_param("s", $category_name);

        if ($stmt->execute()) {
             // Audit log the successful creation
            audit_log($conn, "Created message category: $category_name");

            echo json_encode(['status' => true, 'message' => 'Category added successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;

    case 'read':
        $result = mysqli_query($conn, "SELECT * FROM msg_cat ORDER BY id DESC");
        $category = [];

        while ($row = mysqli_fetch_assoc($result)) {
            $category[] = $row;
        }

        echo json_encode(['status' => true, 'data' => $category]);
        break;

    case 'update':
        $id = $_REQUEST['id'] ?? null;
        $category_name = $_REQUEST['category_name'] ?? null;

        if (!$id || !$category_name) {
            echo json_encode(['status' => false, 'error' => 'ID and message category are required']);
            break;
        }

        $stmt = $conn->prepare("UPDATE msg_cat SET category_name = ? WHERE id = ?");
        $stmt->bind_param("si", $category_name, $id);

        if ($stmt->execute()) {
             // Audit log the successful creation
            audit_log($conn, "Updated message category: $category_name");
            echo json_encode(['status' => true, 'message' => 'Category updated successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;

    case 'delete':
        $id = $_REQUEST['id'] ?? null;

        if (!$id) {
            echo json_encode(['status' => false, 'error' => 'Category ID is required']);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM msg_cat WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => true, 'message' => 'Category deleted successfully']);
        } else {
            echo json_encode(['status' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['status' => false, 'error' => 'Invalid or missing action']);
        break;
}
