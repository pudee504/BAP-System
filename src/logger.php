<?php
// logger.php

// Make sure this file is not accessed directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Access denied.');
}

function log_action($action, $status, $details = '') {
    // **REQUIRED CHANGE:** Capture the returned PDO object from db.php
    $pdo = require 'db.php';

    // --- Gather information for the log ---
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'Guest';
    
    // For failed login attempts, the username isn't in the session yet.
    if ($action === 'LOGIN_ATTEMPT' && $status === 'FAILURE') {
        preg_match('/user: (.*?)$/', $details, $matches);
        if (isset($matches[1])) {
            $username = $matches[1];
        }
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // --- Insert the log into the database using a prepared statement ---
    try {
        $sql = "INSERT INTO logs (user_id, username, action, status, details, ip_address) VALUES (:user_id, :username, :action, :status, :details, :ip_address)";
        
        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            ':user_id'    => $user_id,
            ':username'   => $username,
            ':action'     => $action,
            ':status'     => $status,
            ':details'    => $details,
            ':ip_address' => $ip_address
        ]);

    } catch (PDOException $e) {
        // You can uncomment this line during development to see errors.
        // error_log('Logging to database failed: ' . $e->getMessage());
    }
}
?>