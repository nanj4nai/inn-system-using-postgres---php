<?php
require 'db.php'; // connection file

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

switch ($action) {
    case "read":
        $result = $pdo->query("SELECT * FROM services ORDER BY id DESC");
        echo json_encode($result->fetchAll(PDO::FETCH_ASSOC));
        break;

    case "create":
        $stmt = $pdo->prepare("INSERT INTO services (name, price, branch_id) VALUES (?, ?, 1)");
        $stmt->execute([$data['name'], $data['price']]);
        echo "Service added successfully";
        break;

    case "update":
        $stmt = $pdo->prepare("UPDATE services SET name=?, price=? WHERE id=?");
        $stmt->execute([$data['name'], $data['price'], $data['id']]);
        echo "Service updated successfully";
        break;

    default:
        echo "Invalid action";
}
?>
