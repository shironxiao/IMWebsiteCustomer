<?php
/**
 * FETCH PRODUCTS
 * Returns all available products from the database
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
    // Connect to database
    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(array(
            "success" => false,
            "message" => "Database connection failed"
        ));
        ob_end_flush();
        exit;
    }

    $conn->set_charset("utf8mb4");

    // ============================================================
    // FETCH ALL AVAILABLE PRODUCTS
    // ============================================================

    $sql = "SELECT ProductID, ProductName, Category, Description, Price, 
                   Availability, ServingSize, Image, SpicyLevel, PopularityTag
            FROM products 
            WHERE Availability = 'Available'
            ORDER BY ProductID ASC";

    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(array(
            "success" => false,
            "message" => "Query failed"
        ));
        $conn->close();
        ob_end_flush();
        exit;
    }

    $products = array();

    while ($row = $result->fetch_assoc()) {
        $row['ProductID'] = intval($row['ProductID']);
        $row['Price'] = floatval($row['Price']);
        $products[] = $row;
    }

    $conn->close();

    // ============================================================
    // SUCCESS RESPONSE
    // ============================================================

    http_response_code(200);
    echo json_encode(array(
        "success" => true,
        "message" => "Products fetched successfully",
        "count" => count($products),
        "products" => $products
    ));

    ob_end_flush();
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        "success" => false,
        "message" => "Error fetching products"
    ));
    ob_end_flush();
    exit;
}

ob_end_flush();
?>