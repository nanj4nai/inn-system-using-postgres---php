<?php
// php/guests.php
session_start();
header("Content-Type: application/json");
require_once __DIR__ . "/db.php"; // PostgreSQL PDO connection

if (!isset($_SESSION['branch_id']) || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "No session context found. Please log in."]);
    exit;
}

$branch_id = $_SESSION['branch_id'];
$user_id   = $_SESSION['user_id'];
$method    = $_SERVER['REQUEST_METHOD'];

// Helper: write to audit_logs
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
            $stmt = $pdo->prepare("SELECT * FROM guests WHERE id = :id AND branch_id = :branch_id");
            $stmt->execute([':id' => $_GET['id'], ':branch_id' => $branch_id]);
            echo json_encode($stmt->fetch());
        } else {
            $stmt = $pdo->prepare("SELECT * FROM guests WHERE branch_id = :branch_id ORDER BY id DESC");
            $stmt->execute([':branch_id' => $branch_id]);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        $sql = "INSERT INTO guests (full_name, phone, email, branch_id) 
                VALUES (:full_name, :phone, :email, :branch_id) RETURNING id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':phone'     => $data['phone'] ?? null,
            ':email'     => $data['email'] ?? null,
            ':branch_id' => $branch_id
        ]);

        $guest_id = $stmt->fetchColumn();
        log_audit($pdo, "guests", $guest_id, "insert", $user_id, $branch_id, json_encode($data));


        echo json_encode(["message" => "Guest added", "id" => $guest_id]);
        break;

    case 'PUT':
        parse_str($_SERVER['QUERY_STRING'], $query);
        $id = $query['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            exit;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $sql = "UPDATE guests SET 
                    full_name = :full_name,
                    phone = :phone,
                    email = :email
                WHERE id = :id AND branch_id = :branch_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':phone'     => $data['phone'] ?? null,
            ':email'     => $data['email'] ?? null,
            ':id'        => $id,
            ':branch_id' => $branch_id
        ]);

        log_audit($pdo, "guests", $id, "update", $user_id, $branch_id, json_encode($data));


        echo json_encode(["message" => "Guest updated"]);
        break;

    case 'DELETE':
        parse_str($_SERVER['QUERY_STRING'], $query);
        $id = $query['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM guests WHERE id = :id AND branch_id = :branch_id");
        $stmt->execute([':id' => $id, ':branch_id' => $branch_id]);

        log_audit($pdo, "guests", $id, "delete", $user_id, $branch_id, "Guest deleted");

        echo json_encode(["message" => "Guest deleted"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
}
