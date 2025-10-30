<?php
// FILENAME: logger.php
// DESCRIPTION: Provides a function to log user actions to the database.

// Prevent direct access to this file.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Access denied.');
}

/**
 * Logs an action to the 'logs' database table.
 * @param string $action Short code representing the action (e.g., 'LOGIN', 'ADD_TEAM').
 * @param string $status Result of the action ('SUCCESS', 'FAILURE', 'INFO').
 * @param string $details Optional details about the action.
 */
function log_action($action, $status, $details = '') {
    // Include and establish the database connection.
    $pdo = require 'db.php';

    // --- Gather information for the log entry ---
    $user_id = $_SESSION['user_id'] ?? null; // Get user ID from session, if available.
    $username = $_SESSION['username'] ?? 'Guest'; // Get username from session, default to 'Guest'.
    
    // Special case: Extract username from details for failed login attempts.
    if ($action === 'LOGIN_ATTEMPT' && $status === 'FAILURE') {
        // Attempt to find 'user: username' pattern in the details string.
        preg_match('/user: (.*?)$/', $details, $matches);
        if (isset($matches[1])) {
            $username = $matches[1]; // Use the username from the details if found.
        }
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'; // Get the user's IP address.

    // --- Insert the log into the database ---
    try {
        $sql = "INSERT INTO logs (user_id, username, action, status, details, ip_address) VALUES (:user_id, :username, :action, :status, :details, :ip_address)";
        
        $stmt = $pdo->prepare($sql);
        
        // Execute the prepared statement with the gathered data.
        $stmt->execute([
            ':user_id'    => $user_id,
            ':username'   => $username,
            ':action'     => $action,
            ':status'     => $status,
            ':details'    => $details,
            ':ip_address' => $ip_address
        ]);

    } catch (PDOException $e) {
        // Silently handle database errors during logging to avoid breaking the main script.
        error_log('Logging to database failed: ' . $e->getMessage()); 
    }
}
?>
