<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";

if (!isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$user_id   = $_SESSION['user_id'];
$method    = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);

        // 🔹 Get service info
        $stmt = $pdo->prepare("SELECT price FROM services WHERE id = :service_id AND branch_id = :branch_id");
        $stmt->execute([
            ':service_id' => $data['service_id'],
            ':branch_id'  => $branch_id
        ]);
        $service = $stmt->fetch();

        if (!$service) {
            http_response_code(404);
            echo json_encode(["error" => "Service not found"]);
            exit;
        }

        $qty = (int)$data['qty'];
        $subtotal = $qty * (float)$service['price'];

        // 🔹 Insert into booking_services
        $stmt = $pdo->prepare("
            INSERT INTO booking_services (booking_id, service_id, quantity, total_price)
            VALUES (:booking_id, :service_id, :qty, :total_price)
        ");
        $stmt->execute([
            ':booking_id' => $data['booking_id'],
            ':service_id' => $data['service_id'],
            ':qty'        => $qty,
            ':total_price'=> $subtotal
        ]);

        // 🔹 Update bill (services_charge + total_amount)
        $update = $pdo->prepare("
            UPDATE bills
            SET services_charge = services_charge + :subtotal,
                total_amount = room_charge + services_charge + :subtotal - discount
            WHERE booking_id = :booking_id
        ");
        $update->execute([
            ':subtotal'   => $subtotal,
            ':booking_id' => $data['booking_id']
        ]);

        echo json_encode(["message" => "Service added"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}
