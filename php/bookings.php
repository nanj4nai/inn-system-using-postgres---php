<?php
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
function log_audit($pdo, $table, $record_id, $action, $user_id, $branch_id, $details = null)
{
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
        // Fetch bookings for this branch
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT b.*, g.full_name AS guest, r.room_number, r.room_type
                                   FROM bookings b
                                   JOIN guests g ON b.guest_id = g.id
                                   JOIN rooms r ON b.room_id = r.id
                                   WHERE b.id = :id AND r.branch_id = :branch_id");
            $stmt->execute([':id' => $_GET['id'], ':branch_id' => $branch_id]);
            echo json_encode($stmt->fetch());
        } else {
            $stmt = $pdo->prepare("SELECT b.*, g.full_name AS guest, r.room_number, r.room_type
                                   FROM bookings b
                                   JOIN guests g ON b.guest_id = g.id
                                   JOIN rooms r ON b.room_id = r.id
                                   WHERE r.branch_id = :branch_id
                                   ORDER BY b.id DESC");
            $stmt->execute([':branch_id' => $branch_id]);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        // Add new booking
        $data = json_decode(file_get_contents("php://input"), true);

        // 🔹 Check if room is available before booking
        $checkRoom = $pdo->prepare("SELECT status FROM rooms WHERE id = :room_id AND branch_id = :branch_id");
        $checkRoom->execute([
            ':room_id'   => $data['room_id'],
            ':branch_id' => $branch_id
        ]);
        $room = $checkRoom->fetch();

        if (!$room) {
            http_response_code(404);
            echo json_encode(["error" => "Room not found"]);
            exit;
        }

        if ($room['status'] !== 'available') {
            http_response_code(400);
            echo json_encode(["error" => "Room is currently {$room['status']} and cannot be booked."]);
            exit;
        }

        // 🔹 Insert booking if room is available
        $sql = "INSERT INTO bookings 
        (room_id, guest_id, user_id, branch_id, check_in, expected_hours, status)
        VALUES (:room_id, :guest_id, :user_id, :branch_id, :check_in, :expected_hours, 'ongoing')
        RETURNING id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':room_id'        => $data['room_id'],
            ':guest_id'       => $data['guest_id'],
            ':user_id'        => $user_id,
            ':branch_id'      => $branch_id,
            ':check_in'       => date("Y-m-d H:i:s"),
            ':expected_hours' => $data['expected_hours']
        ]);

        $booking_id = $stmt->fetchColumn();

        // 🔹 Update room status to occupied
        $roomUpdate = $pdo->prepare("UPDATE rooms SET status = 'occupied' 
                                 WHERE id = :room_id AND branch_id = :branch_id");
        $roomUpdate->execute([
            ':room_id'   => $data['room_id'],
            ':branch_id' => $branch_id
        ]);

        log_audit($pdo, "bookings", $booking_id, "insert", $user_id, $branch_id, json_encode($data));

        echo json_encode(["message" => "Booking created", "id" => $booking_id]);
        break;

    case 'PUT':
        // Update booking
        parse_str($_SERVER['QUERY_STRING'], $query);
        $id = $query['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            exit;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // 🔹 Get the room linked to this booking
        $stmt = $pdo->prepare("SELECT room_id FROM bookings WHERE id = :id AND branch_id = :branch_id");
        $stmt->execute([
            ':id'        => $id,
            ':branch_id' => $branch_id
        ]);
        $booking = $stmt->fetch();

        if (!$booking) {
            http_response_code(404);
            echo json_encode(["error" => "Booking not found"]);
            exit;
        }

        $room_id = $booking['room_id'];

        // 🔹 Safeguard: if trying to set status = ongoing, ensure no other ongoing booking exists for this room
        if ($data['status'] === 'ongoing') {
            $check = $pdo->prepare("SELECT COUNT(*) FROM bookings 
                                WHERE room_id = :room_id 
                                  AND branch_id = :branch_id 
                                  AND status = 'ongoing' 
                                  AND id <> :id");
            $check->execute([
                ':room_id'   => $room_id,
                ':branch_id' => $branch_id,
                ':id'        => $id
            ]);
            $conflicts = $check->fetchColumn();

            if ($conflicts > 0) {
                http_response_code(400);
                echo json_encode(["error" => "Room is already occupied by another ongoing booking."]);
                exit;
            }
        }

        // 🔹 Update booking record
        $sql = "UPDATE bookings 
            SET expected_hours = :expected_hours,
                status = :status,
                check_out = CASE WHEN :status = 'completed' THEN NOW() ELSE check_out END
            WHERE id = :id AND branch_id = :branch_id
            RETURNING room_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':expected_hours' => $data['expected_hours'],
            ':status'         => $data['status'],
            ':id'             => $id,
            ':branch_id'      => $branch_id
        ]);

        $room_id = $stmt->fetchColumn();

        // 🔹 If booking completed or cancelled → free the room
        if ($data['status'] === 'completed' || $data['status'] === 'cancelled') {
            $roomUpdate = $pdo->prepare("UPDATE rooms SET status = 'available' 
                                     WHERE id = :room_id AND branch_id = :branch_id");
            $roomUpdate->execute([
                ':room_id'   => $room_id,
                ':branch_id' => $branch_id
            ]);
        }

        log_audit($pdo, "bookings", $id, "update", $user_id, $branch_id, json_encode($data));

        echo json_encode(["message" => "Booking updated"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}
