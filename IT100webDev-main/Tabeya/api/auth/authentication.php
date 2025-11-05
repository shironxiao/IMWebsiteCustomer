<?php
/**
 * Authentication Module
 * Main authentication logic for register and login
 */

require_once(__DIR__ . '/../config/db_config.php');
require_once(__DIR__ . '/../functions/validation.php');
require_once(__DIR__ . '/../functions/security.php');
require_once(__DIR__ . '/../functions/Customer.php');

setJsonHeaders();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ], 405);
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    // Try to get from POST body
    $data = $_POST;
}

// Create customer instance
$customer = new Customer($conn);

// ====================================================================
// === REGISTRATION ===
// ====================================================================

if ($action === 'register') {
    // Validate required fields
    $required = ['firstName', 'lastName', 'email', 'contactNumber', 'password'];
    $validation = validateRequiredFields($data, $required);
    
    if (!$validation['isValid']) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $validation['missing'])
        ], 400);
    }
    
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = trim($data['email']);
    $contactNumber = trim($data['contactNumber']);
    $password = $data['password'];
    
    // Validate inputs
    if (!validateName($firstName) || !validateName($lastName)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'First and last names must be at least 2 characters long.'
        ], 400);
    }
    
    if (!validateEmail($email)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid email format.'
        ], 400);
    }
    
    if (!validateContactNumber($contactNumber)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid contact number. Use format: 09XXXXXXXXX'
        ], 400);
    }
    
    $passwordValidation = validatePasswordStrength($password);
    if (!$passwordValidation['isValid']) {
        sendJsonResponse([
            'success' => false,
            'message' => $passwordValidation['message']
        ], 400);
    }
    
    // Check if email exists
    if ($customer->emailExists($email)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'An account with this email already exists.'
        ], 409);
    }
    
    // Hash password
    $passwordHash = hashPassword($password);
    
    // Register customer
    $result = $customer->register($firstName, $lastName, $email, $contactNumber, $passwordHash);
    
    if ($result['success']) {
        sendJsonResponse([
            'success' => true,
            'customerId' => $result['customerId'],
            'message' => 'Registration successful!',
            'customer' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email
            ]
        ], 201);
    } else {
        sendJsonResponse([
            'success' => false,
            'message' => 'Registration failed: ' . $result['error']
        ], 500);
    }
}

// ====================================================================
// === LOGIN ===
// ====================================================================

else if ($action === 'login') {
    // Validate required fields
    $validation = validateRequiredFields($data, ['email', 'password']);
    
    if (!$validation['isValid']) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Email and password are required.'
        ], 400);
    }
    
    $email = trim($data['email']);
    $password = $data['password'];
    
    // Validate email format
    if (!validateEmail($email)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid email format.'
        ], 400);
    }
    
    // Get customer by email
    $customerData = $customer->getByEmail($email);
    
    if (!$customerData) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Email not found or account is inactive.'
        ], 401);
    }
    
    // Verify password
    if (!verifyPassword($password, $customerData['PasswordHash'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid password.'
        ], 401);
    }
    
    // Update last login
    $customer->updateLastLogin($customerData['CustomerID']);
    
    // Log the login
    $customer->logTransaction($customerData['CustomerID'], 'LOGIN', 'Customer logged in successfully');
    
    // Remove sensitive data
    unset($customerData['PasswordHash']);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Login successful!',
        'customer' => $customerData
    ], 200);
}

// ====================================================================
// === INVALID ACTION ===
// ====================================================================

else {
    sendJsonResponse([
        'success' => false,
        'message' => 'Invalid action. Use "register" or "login".'
    ], 400);
}

?>