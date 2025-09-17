<?php
header("Content-Type: application/json");
require_once "db.php"; // your PostgreSQL PDO connection file
session_start(); // 🔑 make sure session is active

$action = $_GET['action'] ?? null;

// 🟢 Ensure logged-in user
if (!isset($_SESSION['user_id'], $_SESSION['branch_id'])) {
    echo json_encode(["success" => false, "message" => "Not authenticated"]);
    exit;
}

$user_id = $_SESSION['user_id'];   // logged-in user
$branch_id = $_SESSION['branch_id']; // logged-in user’s branch

// 🟢 VIEW ALL ITEMS
if ($action === "list") {
    try {
        // Get categories (global)
        $catStmt = $pdo->query("SELECT id, name, description FROM inventory_categories ORDER BY name");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items with category name
        $stmt = $pdo->query("
            SELECT i.id, i.name, i.description, i.quantity, i.unit,
                   c.id AS category_id, c.name AS category_name
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON i.category_id = c.id
            ORDER BY i.id DESC
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["categories" => $categories, "items" => $items]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

// 🟢 GET SINGLE ITEM
if ($action === "get" && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($item ?: []);
    exit;
}

// 🟢 ADD / UPDATE CATEGORY
if ($action === "saveCategory") {
    $input = json_decode(file_get_contents("php://input"), true);
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE inventory_categories SET name=?, description=? WHERE id=?");
            $stmt->execute([$name, $description, $id]);

            // Audit log
            $audit = $pdo->prepare("INSERT INTO audit_logs (table_name, record_id, action, user_id, branch_id, details)
                            VALUES ('inventory_categories', ?, 'update', ?, ?, ?)");
            $audit->execute([$id, $user_id, $branch_id, "Category updated: $name"]);

            echo json_encode(["success" => true, "message" => "Category updated"]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO inventory_categories (name, description) VALUES (?, ?) RETURNING id");
            $stmt->execute([$name, $description]);
            $newCatId = $stmt->fetchColumn();

            // Audit log
            $audit = $pdo->prepare("INSERT INTO audit_logs (table_name, record_id, action, user_id, branch_id, details)
                            VALUES ('inventory_categories', ?, 'insert', ?, ?, ?)");
            $audit->execute([$newCatId, $user_id, $branch_id, "Category added: $name"]);

            echo json_encode(["success" => true, "message" => "Category added"]);
        }
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

// 🟢 ADD / UPDATE ITEM
$input = json_decode(file_get_contents("php://input"), true);
if ($input) {
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $category_id = $input['category_id'] ?? null;
    $quantity = $input['quantity'] ?? 0;
    $unit = $input['unit'] ?? 'pcs';
    $description = $input['description'] ?? '';

    try {
        if ($id) {
            // 🔹 Get old quantity before update
            $oldStmt = $pdo->prepare("SELECT quantity FROM inventory_items WHERE id=?");
            $oldStmt->execute([$id]);
            $oldQty = (int)$oldStmt->fetchColumn();

            // UPDATE
            $stmt = $pdo->prepare("UPDATE inventory_items 
                SET name=?, category_id=?, quantity=?, unit=?, description=?
                WHERE id=?");
            $stmt->execute([$name, $category_id, $quantity, $unit, $description, $id]);

            // 🔹 Determine change
            $diff = $quantity - $oldQty;
            $actionType = $diff > 0 ? 'add' : ($diff < 0 ? 'remove' : 'adjust');

            // Log transaction
            $log = $pdo->prepare("INSERT INTO inventory_transactions (item_id, user_id, action, quantity, notes, branch_id) 
                          VALUES (?, ?, ?, ?, ?, ?)");
            $log->execute([$id, $user_id, $actionType, abs($diff), "Adjusted from $oldQty to $quantity", $branch_id]);

            // Audit log
            $audit = $pdo->prepare("INSERT INTO audit_logs (table_name, record_id, action, user_id, branch_id, details)
                            VALUES ('inventory_items', ?, 'update', ?, ?, ?)");
            $audit->execute([$id, $user_id, $branch_id, "Item updated: $name"]);

            echo json_encode(["success" => true, "message" => "Item updated"]);
        } else {
            // INSERT
            $stmt = $pdo->prepare("INSERT INTO inventory_items (name, category_id, quantity, unit, description, branch_id) 
                           VALUES (?, ?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$name, $category_id, $quantity, $unit, $description, $branch_id]);
            $newItemId = $stmt->fetchColumn();

            // Log transaction as add
            $log = $pdo->prepare("INSERT INTO inventory_transactions (item_id, user_id, action, quantity, notes, branch_id) 
                          VALUES (?, ?, 'add', ?, ?, ?)");
            $log->execute([$newItemId, $user_id, $quantity, "New item added", $branch_id]);

            // Audit log
            $audit = $pdo->prepare("INSERT INTO audit_logs (table_name, record_id, action, user_id, branch_id, details)
                            VALUES ('inventory_items', ?, 'insert', ?, ?, ?)");
            $audit->execute([$newItemId, $user_id, $branch_id, "Item added: $name"]);

            echo json_encode(["success" => true, "message" => "Item added"]);
        }
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request"]);
