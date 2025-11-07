<?php
/**
 * Authentication Module
 * Main authentication logic for register and login
 */
// ✅ ADD THIS FOR DEBUGGING
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log errors to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../error.log');
// ✅ START SESSION FIRST
require_once(__DIR__ . '/session.php');
require_once(__DIR__ . '/../config/db_config.php');
require_once(__DIR__ . '/../functions/validation.php');
require_once(__DIR__ . '/../functions/security.php');
require_once(__DIR__ . '/../functions/Customer.php');

setJsonHeaders();

// Catch any errors and return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $errstr . ' in ' . basename($errfile) . ':' . $errline
    ]);
    exit;
});

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
    exit;
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    $data = $_POST;
}

$customer = new Customer($conn);

// ====================================================================
// === REGISTRATION ===
// ====================================================================

if ($action === 'register') {
    try {
        // Validate required fields
        $required = ['firstName', 'lastName', 'email', 'contactNumber', 'password'];
        $validation = validateRequiredFields($data, $required);
        
        if (!$validation['isValid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $validation['missing'])
            ]);
            exit;
        }
        
        $firstName = trim($data['firstName']);
        $lastName = trim($data['lastName']);
        $email = trim($data['email']);
        $contactNumber = trim($data['contactNumber']);
        $password = $data['password'];
        
        // Validate email format
        if (!validateEmail($email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email format.'
            ]);
            exit;
        }
        
        // Validate contact number
        if (!validateContactNumber($contactNumber)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid contact number. Use format: 09XXXXXXXXX'
            ]);
            exit;
        }
        
        // Validate password strength
        $passwordValidation = validatePasswordStrength($password);
        if (!$passwordValidation['isValid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $passwordValidation['message']
            ]);
            exit;
        }
        
        // Check if email exists
        if ($customer->emailExists($email)) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'An account with this email already exists.'
            ]);
            exit;
        }
        
        // Hash password
        $passwordHash = hashPassword($password);
        
        // Register customer
        $result = $customer->register($firstName, $lastName, $email, $contactNumber, $passwordHash);
        
        if ($result['success']) {
            // ✅ SET SESSION AFTER REGISTRATION
            setUserSession(
                $result['customerId'],
                $email,
                $firstName,
                $lastName
            );
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'customerId' => $result['customerId'],
                'message' => 'Registration successful!',
                'customer' => [
                    'FirstName' => $firstName,
                    'LastName' => $lastName,
                    'Email' => $email,
                    'CustomerID' => $result['customerId']
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Registration failed: ' . $result['message']
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}

// ====================================================================
// === LOGIN ===
// ====================================================================

else if ($action === 'login') {
    try {
        // Validate required fields
        $validation = validateRequiredFields($data, ['email', 'password']);
        
        if (!$validation['isValid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required.'
            ]);
            exit;
        }
        
        $email = trim($data['email']);
        $password = $data['password'];
        
        // Validate email format
        if (!validateEmail($email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email format.'
            ]);
            exit;
        }
        
        // Get customer by email
        $customerData = $customer->getByEmail($email);
        
        if (!$customerData) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Email not found or account is inactive.'
            ]);
            exit;
        }
        
        // Verify password
        if (!verifyPassword($password, $customerData['PasswordHash'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid password.'
            ]);
            exit;
        }
        
        // ✅ SET SESSION AFTER LOGIN
        setUserSession(
            $customerData['CustomerID'],
            $customerData['Email'],
            $customerData['FirstName'],
            $customerData['LastName']
        );
        
        $customer->updateLastLogin($customerData['CustomerID']);
        $customer->logTransaction($customerData['CustomerID'], 'LOGIN', 'Customer logged in successfully');
        
        // Remove sensitive data
        unset($customerData['PasswordHash']);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Login successful!',
            'customer' => $customerData
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}

// ====================================================================
// === LOGOUT ===
// ====================================================================

else if ($action === 'logout') {
    try {
        if (isUserLoggedIn()) {
            $customerId = getCurrentUserId();
            $customer->logTransaction($customerId, 'LOGOUT', 'Customer logged out');
        }
        
        destroyUserSession();
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Logged out successfully!'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}

// ====================================================================
// === INVALID ACTION ===
// ====================================================================

else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action. Use "register", "login", or "logout".'
    ]);
}

?>