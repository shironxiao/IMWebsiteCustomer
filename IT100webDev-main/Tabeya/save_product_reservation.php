<?php
/**
 * SAVE PRODUCT RESERVATION
 * Handles saving catering reservation with selected products
 */

// START OUTPUT BUFFERING IMMEDIATELY
ob_start();

// Configure error handling - NO OUTPUT BEFORE JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "tabeya_system";

// Set JSON header FIRST
header('Content-Type: application/json; charset=utf-8');

try {
    // Check if AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(400);
        echo json_encode(array("status" => "error", "message" => "Invalid request"));
        ob_end_flush();
        exit;
    }

    // Verify POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array("status" => "error", "message" => "Only POST requests allowed"));
        ob_end_flush();
        exit;
    }

    // Connect to database
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array("status" => "error", "message" => "Database connection failed"));
        ob_end_flush();
        exit;
    }

    $conn->set_charset("utf8mb4");

    // ============================================================
    // PARSE INPUT DATA
    // ============================================================

    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $total_price = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;
    $products_json = isset($_POST['selected_products']) ? $_POST['selected_products'] : '[]';
    
    $products = json_decode($products_json, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(array("status" => "error", "message" => "Invalid product data"));
        $conn->close();
        ob_end_flush();
        exit;
    }

    // ============================================================
    // VALIDATE INPUT
    // ============================================================

    if (empty($customer_id) || empty($products) || count($products) === 0) {
        http_response_code(400);
        echo json_encode(array("status" => "error", "message" => "Missing required data"));
        $conn->close();
        ob_end_flush();
        exit;
    }

    // ============================================================
    // START TRANSACTION
    // ============================================================

    $conn->begin_transaction();

    // ============================================================
    // DELETE PLACEHOLDER ITEMS
    // ============================================================

    // First, find the latest reservation for this customer
    $find_res_sql = "SELECT ReservationID FROM reservations 
                     WHERE CustomerID = ? 
                     ORDER BY ReservationDate DESC LIMIT 1";
    
    $find_res_stmt = $conn->prepare($find_res_sql);
    
    if (!$find_res_stmt) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(array("status" => "error", "message" => "Database error"));
        $conn->close();
        ob_end_flush();
        exit;
    }

    $find_res_stmt->bind_param("i", $customer_id);
    $find_res_stmt->execute();
    $res_result = $find_res_stmt->get_result();

    if ($res_result->num_rows === 0) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(array("status" => "error", "message" => "Reservation not found"));
        $find_res_stmt->close();
        $conn->close();
        ob_end_flush();
        exit;
    }

    $reservation_row = $res_result->fetch_assoc();
    $reservation_id = intval($reservation_row['ReservationID']);
    $find_res_stmt->close();

    // Delete placeholder items
    $delete_sql = "DELETE FROM reservation_items 
                   WHERE ReservationID = ? AND ProductName = 'Menu Selection Pending'";
    
    $delete_stmt = $conn->prepare($delete_sql);
    
    if ($delete_stmt) {
        $delete_stmt->bind_param("i", $reservation_id);
        $delete_stmt->execute();
        $delete_stmt->close();
    }

    // ============================================================
    // INSERT SELECTED PRODUCTS
    // ============================================================

    $insert_sql = "INSERT INTO reservation_items 
                   (ReservationID, ProductName, Quantity, UnitPrice, TotalPrice) 
                   VALUES (?, ?, ?, ?, ?)";

    $insert_stmt = $conn->prepare($insert_sql);

    if (!$insert_stmt) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(array("status" => "error", "message" => "Database error"));
        $conn->close();
        ob_end_flush();
        exit;
    }

    foreach ($products as $product) {
        $product_name = isset($product['name']) ? $product['name'] : '';
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 0;
        $unit_price = isset($product['price']) ? floatval($product['price']) : 0;
        $item_total = $quantity * $unit_price;

        if (empty($product_name) || $quantity <= 0) {
            continue;
        }

        $insert_stmt->bind_param(
            "isidi",
            $reservation_id,
            $product_name,
            $quantity,
            $unit_price,
            $item_total
        );

        if (!$insert_stmt->execute()) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(array("status" => "error", "message" => "Failed to save products"));
            $insert_stmt->close();
            $conn->close();
            ob_end_flush();
            exit;
        }
    }

    $insert_stmt->close();

    // ============================================================
    // UPDATE PAYMENT AMOUNT
    // ============================================================

    $update_payment_sql = "UPDATE reservation_payments 
                           SET AmountPaid = ? 
                           WHERE ReservationID = ?";

    $update_payment_stmt = $conn->prepare($update_payment_sql);

    if ($update_payment_stmt) {
        $update_payment_stmt->bind_param("di", $total_price, $reservation_id);
        $update_payment_stmt->execute();
        $update_payment_stmt->close();
    }

    // ============================================================
    // UPDATE CUSTOMER RESERVATION COUNT
    // ============================================================

    $update_customer_sql = "UPDATE customers 
                            SET ReservationCount = ReservationCount + 1,
                                LastTransactionDate = NOW()
                            WHERE CustomerID = ?";

    $update_customer_stmt = $conn->prepare($update_customer_sql);

    if ($update_customer_stmt) {
        $update_customer_stmt->bind_param("i", $customer_id);
        $update_customer_stmt->execute();
        $update_customer_stmt->close();
    }

    // ============================================================
    // LOG TRANSACTION
    // ============================================================

    $log_sql = "INSERT INTO customer_logs 
                (CustomerID, TransactionType, Details) 
                VALUES (?, ?, ?)";

    $log_stmt = $conn->prepare($log_sql);

    if ($log_stmt) {
        $transaction_type = 'RESERVATION_COMPLETED';
        $details = "Products added to reservation #" . $reservation_id;

        $log_stmt->bind_param(
            "iss",
            $customer_id,
            $transaction_type,
            $details
        );

        $log_stmt->execute();
        $log_stmt->close();
    }

    // ============================================================
    // COMMIT TRANSACTION
    // ============================================================

    $conn->commit();
    $conn->close();

    // ============================================================
    // SUCCESS RESPONSE
    // ============================================================

    http_response_code(201);
    echo json_encode(array(
        "status" => "success",
        "message" => "Reservation saved successfully!",
        "reservation_id" => $reservation_id,
        "total_amount" => $total_price,
        "product_count" => count($products)
    ));

    ob_end_flush();
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "An error occurred"
    ));
    ob_end_flush();
    exit;
}

ob_end_flush();
?>