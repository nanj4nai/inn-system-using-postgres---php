<?php
// rooms.php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/db.php"; // PostgreSQL PDO connection

// Make sure user is logged in and has branch_id + user_id
if (!isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "No session context found. Please log in."]);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$user_id   = $_SESSION['user_id'];
$method    = $_SERVER['REQUEST_METHOD'];

/**
 * Enhanced audit log helper
 */
function log_audit($pdo, $table, $record_id, $action, $user_id, $branch_id, $details = null) {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (table_name, record_id, action, user_id, branch_id, details)
        VALUES (:table_name, :record_id, :action, :user_id, :branch_id, :details)
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':record_id'  => $record_id,
        ':action'     => $action,
        ':user_id'    => $user_id,
        ':branch_id'  => $branch_id,
        ':details'    => $details
    ]);
}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = :id AND branch_id = :branch_id");
            $stmt->execute([':id' => $_GET['id'], ':branch_id' => $branch_id]);
            echo json_encode($stmt->fetch());
        } else {
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE branch_id = :branch_id ORDER BY id ASC");
            $stmt->execute([':branch_id' => $branch_id]);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        $sql = "INSERT INTO rooms 
        (room_number, room_type, status, min_hours, base_price, extra_hour_price, capacity, branch_id)
        VALUES (:room_number, :room_type, :status, :min_hours, :base_price, :extra_hour_price, :capacity, :branch_id)
        RETURNING id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':room_number'      => $data['room_number'],
            ':room_type'        => $data['room_type'],
            ':status'           => $data['status'],
            ':min_hours'        => $data['min_hours'],
            ':base_price'       => $data['base_price'],
            ':extra_hour_price' => $data['extra_hour_price'],
            ':capacity'         => $data['capacity'],
            ':branch_id'        => $branch_id
        ]);

        $room_id = $stmt->fetchColumn();
        log_audit($pdo, "rooms", $room_id, "insert", $user_id, $branch_id, json_encode($data));

        echo json_encode(["message" => "Room added", "id" => $room_id]);
        break;

    case 'PUT':
        parse_str($_SERVER['QUERY_STRING'], $query);
        $id = $query['id'] ?? null;
        if (!$id) { http_response_code(400); exit; }

        $data = json_decode(file_get_contents("php://input"), true);
        $sql = "UPDATE rooms 
        SET room_number=:room_number,
            room_type=:room_type,
            status=:status,
            min_hours=:min_hours,
            base_price=:base_price,
            extra_hour_price=:extra_hour_price,
            capacity=:capacity
        WHERE id=:id AND branch_id=:branch_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':room_number'      => $data['room_number'],
            ':room_type'        => $data['room_type'],
            ':status'           => $data['status'],
            ':min_hours'        => $data['min_hours'],
            ':base_price'       => $data['base_price'],
            ':extra_hour_price' => $data['extra_hour_price'],
            ':capacity'         => $data['capacity'],
            ':id'               => $id,
            ':branch_id'        => $branch_id
        ]);

        log_audit($pdo, "rooms", $id, "update", $user_id, $branch_id, json_encode($data));

        echo json_encode(["message" => "Room updated"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}
