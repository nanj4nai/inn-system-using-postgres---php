<?php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/db.php"; // PostgreSQL PDO connection

// Make sure user is logged in
if (!isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "No session context found. Please log in."]);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$user_id   = $_SESSION['user_id'];
$method    = $_SERVER['REQUEST_METHOD'];

/**
 * Audit log helper
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
        if (isset($_GET['id'])) {
            // Get specific bill
            $stmt = $pdo->prepare("
                SELECT b.*, g.full_name AS guest_name, r.room_number
                FROM bills b
                JOIN bookings bk ON b.booking_id = bk.id
                JOIN guests g ON bk.guest_id = g.id
                JOIN rooms r ON bk.room_id = r.id
                WHERE b.id = :id AND b.branch_id = :branch_id
            ");
            $stmt->execute([
                ':id'        => $_GET['id'],
                ':branch_id' => $branch_id
            ]);
            echo json_encode($stmt->fetch());
        } else {
            // Get all bills for this branch
            $stmt = $pdo->prepare("
                SELECT b.*, g.full_name AS guest_name, r.room_number, bk.status AS booking_status
                FROM bills b
                JOIN bookings bk ON b.booking_id = bk.id
                JOIN guests g ON bk.guest_id = g.id
                JOIN rooms r ON bk.room_id = r.id
                WHERE b.branch_id = :branch_id
                ORDER BY b.id DESC
            ");
            $stmt->execute([':branch_id' => $branch_id]);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        // Process payment
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['bill_id'], $data['amount_paid'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing payment details"]);
            exit;
        }

        $bill_id        = (int)$data['bill_id'];
        $amount_paid    = floatval($data['amount_paid']);
        $payment_method = $data['payment_method'] ?? 'cash';

        // Fetch bill + booking status
        $stmt = $pdo->prepare("
            SELECT b.*, bk.status AS booking_status
            FROM bills b
            JOIN bookings bk ON b.booking_id = bk.id
            WHERE b.id = :id AND b.branch_id = :branch_id
        ");
        $stmt->execute([':id' => $bill_id, ':branch_id' => $branch_id]);
        $bill = $stmt->fetch();

        if (!$bill) {
            // Log failed attempt: bill not found
            $details = json_encode([
                'attempted_bill_id' => $bill_id,
                'attempted_amount'  => $amount_paid,
                'payment_method'    => $payment_method,
                'reason'            => 'Bill not found'
            ]);
            log_audit($pdo, "bills", $bill_id, "failed_payment", $user_id, $branch_id, $details);

            http_response_code(404);
            echo json_encode(["error" => "Bill not found"]);
            exit;
        }

        // ❌ Prevent payments on completed bookings
        if ($bill['booking_status'] === 'completed') {
            // Log failed attempt: booking already completed
            $details = json_encode([
                'bill_id'           => $bill_id,
                'booking_status'    => $bill['booking_status'],
                'attempted_amount'  => $amount_paid,
                'payment_method'    => $payment_method,
                'reason'            => 'Booking already completed'
            ]);
            log_audit($pdo, "bills", $bill_id, "failed_payment", $user_id, $branch_id, $details);

            http_response_code(400);
            echo json_encode(["error" => "Booking already completed. No further payments allowed."]);
            exit;
        }

        $new_paid = $bill['paid_amount'] + $amount_paid;
        if ($new_paid > $bill['total_amount']) {
            // Log failed attempt: overpayment
            $details = json_encode([
                'bill_id'           => $bill_id,
                'current_paid'      => $bill['paid_amount'],
                'attempted_amount'  => $amount_paid,
                'resulting_paid'    => $new_paid,
                'total_amount'      => $bill['total_amount'],
                'payment_method'    => $payment_method,
                'reason'            => 'Overpayment attempt'
            ]);
            log_audit($pdo, "bills", $bill_id, "failed_payment", $user_id, $branch_id, $details);

            http_response_code(400);
            echo json_encode(["error" => "Payment exceeds total amount"]);
            exit;
        }

        // ✅ Update bill
        $stmt = $pdo->prepare("
            UPDATE bills
            SET paid_amount = :paid_amount,
                payment_method = :payment_method
            WHERE id = :id AND branch_id = :branch_id
        ");
        $stmt->execute([
            ':paid_amount'    => $new_paid,
            ':payment_method' => $payment_method,
            ':id'             => $bill_id,
            ':branch_id'      => $branch_id
        ]);

        log_audit($pdo, "bills", $bill_id, "update", $user_id, $branch_id, json_encode([
            'action' => 'payment',
            'attempted_amount' => $amount_paid,
            'new_paid_amount' => $new_paid,
            'payment_method' => $payment_method
        ]));

        // 🔹 Auto-complete booking if fully paid
        if ($new_paid >= $bill['total_amount']) {
            $stmt = $pdo->prepare("SELECT booking_id FROM bills WHERE id = :id");
            $stmt->execute([':id' => $bill_id]);
            $booking_id = $stmt->fetchColumn();

            if ($booking_id) {
                // Mark booking completed
                $stmt = $pdo->prepare("
                    UPDATE bookings
                    SET status = 'completed', check_out = NOW()
                    WHERE id = :booking_id AND branch_id = :branch_id
                    RETURNING room_id
                ");
                $stmt->execute([
                    ':booking_id' => $booking_id,
                    ':branch_id'  => $branch_id
                ]);

                $room_id = $stmt->fetchColumn();

                // Free room
                if ($room_id) {
                    $stmt = $pdo->prepare("
                        UPDATE rooms
                        SET status = 'available'
                        WHERE id = :room_id AND branch_id = :branch_id
                    ");
                    $stmt->execute([':room_id' => $room_id, ':branch_id' => $branch_id]);

                    log_audit($pdo, "rooms", $room_id, "update", $user_id, $branch_id, "Room freed after full payment");
                }

                log_audit($pdo, "bookings", $booking_id, "update", $user_id, $branch_id, "Auto-completed after full payment");
            }
        }

        echo json_encode(["success" => true, "message" => "Payment recorded"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}
