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
        $checkRoom = $pdo->prepare("SELECT * FROM rooms WHERE id = :room_id AND branch_id = :branch_id");
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

        // 🔹 Insert booking
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

        // 🔹 Update room status
        $roomUpdate = $pdo->prepare("UPDATE rooms SET status = 'occupied' 
                             WHERE id = :room_id AND branch_id = :branch_id");
        $roomUpdate->execute([
            ':room_id'   => $data['room_id'],
            ':branch_id' => $branch_id
        ]);

        // 🔹 Calculate initial room charge
        $basePrice   = (float)$room['base_price'];
        $minHours    = (int)$room['min_hours'];
        $extraPrice  = (float)$room['extra_hour_price'];
        $hours       = (int)$data['expected_hours'];

        $roomCharge = $basePrice;
        if ($hours > $minHours) {
            $roomCharge += ($hours - $minHours) * $extraPrice;
        }

        // 🔹 Create initial bill
        $billStmt = $pdo->prepare("
        INSERT INTO bills (booking_id, room_charge, services_charge, discount, total_amount, paid_amount, branch_id)
        VALUES (:booking_id, :room_charge, 0, 0, :total_amount, 0, :branch_id)
    ");
        $billStmt->execute([
            ':booking_id'  => $booking_id,
            ':room_charge' => $roomCharge,
            ':total_amount' => $roomCharge,
            ':branch_id'   => $branch_id
        ]);

        log_audit($pdo, "bookings", $booking_id, "insert", $user_id, $branch_id, json_encode($data));

        echo json_encode(["message" => "Booking created", "id" => $booking_id]);
        break;


    case 'PUT':
        parse_str($_SERVER['QUERY_STRING'], $query);
        $id = $query['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            exit;
        }

        $data = json_decode(file_get_contents("php://input"), true);

        // 🔹 Get the booking and room
        $stmt = $pdo->prepare("
        SELECT b.*, r.base_price, r.min_hours, r.extra_hour_price 
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.id = :id AND b.branch_id = :branch_id
    ");
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

        // 🔹 Prevent conflicts if status = ongoing
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
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(["error" => "Room is already occupied by another ongoing booking."]);
                exit;
            }
        }

        // 🔹 Update booking (check_out only if completed)
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

        // 🔹 If completed → finalize bill
        if ($data['status'] === 'completed') {
            // recalc room charge in case hours were extended
            $basePrice   = (float)$booking['base_price'];
            $minHours    = (int)$booking['min_hours'];
            $extraPrice  = (float)$booking['extra_hour_price'];
            $hours       = (int)$data['expected_hours'];

            $roomCharge = $basePrice;
            if ($hours > $minHours) {
                $roomCharge += ($hours - $minHours) * $extraPrice;
            }

            // finalize bill
            $updateBill = $pdo->prepare("
            UPDATE bills
            SET room_charge = :room_charge,
                total_amount = :room_charge + services_charge - discount
            WHERE booking_id = :booking_id
        ");
            $updateBill->execute([
                ':room_charge' => $roomCharge,
                ':booking_id'  => $id
            ]);

            // free the room
            $roomUpdate = $pdo->prepare("UPDATE rooms SET status = 'available' 
                                     WHERE id = :room_id AND branch_id = :branch_id");
            $roomUpdate->execute([
                ':room_id'   => $room_id,
                ':branch_id' => $branch_id
            ]);
        }

        // 🔹 If cancelled → just free the room
        if ($data['status'] === 'cancelled') {
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
