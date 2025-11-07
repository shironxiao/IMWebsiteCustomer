<?php
/**
 * Customer Class
 */

class Customer {
    private $conn;
    private $table = 'customers';
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function emailExists($email) {
        $sql = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE Email = ?";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] > 0;
    }
    
    public function register($firstName, $lastName, $email, $contactNumber, $passwordHash) {
        $sql = "INSERT INTO " . $this->table . 
               " (FirstName, LastName, Email, PasswordHash, ContactNumber, CustomerType, CreatedDate, AccountStatus) 
               VALUES (?, ?, ?, ?, ?, 'Online', NOW(), 'Active')";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return ['success' => false, 'error' => $this->conn->error];
        }
        
        $stmt->bind_param("sssss", $firstName, $lastName, $email, $passwordHash, $contactNumber);
        
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'error' => $stmt->error];
        }
        
        $customerId = $stmt->insert_id;
        $stmt->close();
        
        return [
            'success' => true,
            'customerId' => $customerId,
            'message' => 'Registration successful!'
        ];
    }
    
    public function getByEmail($email) {
        $sql = "SELECT * FROM " . $this->table . 
               " WHERE Email = ? AND AccountStatus = 'Active'";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return null;
        }
        
        $customer = $result->fetch_assoc();
        $stmt->close();
        
        return $customer;
    }
    
    public function updateLastLogin($customerId) {
        $sql = "UPDATE " . $this->table . " SET LastLoginDate = NOW() WHERE CustomerID = ?";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("i", $customerId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
    
    public function logTransaction($customerId, $transactionType, $details) {
        $sql = "INSERT INTO customer_logs (CustomerID, TransactionType, Details, LogDate) 
                VALUES (?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("iss", $customerId, $transactionType, $details);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}

?>