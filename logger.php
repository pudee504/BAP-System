<?php
// Make sure this file is not accessed directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Access denied.');
}

function log_action($action, $status, $details = '') {
    // Include the database connection. 
    require_once 'db.php'; 

    // Get the database connection object from db.php
    // We assume your db.php creates a PDO object named $pdo
    global $pdo;

    // --- Gather information for the log ---
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'Guest';
    
    // For failed login attempts, the username isn't in the session yet.
    // We'll capture it from the details if available.
    if ($action === 'LOGIN_ATTEMPT' && $status === 'FAILURE') {
        // Extract username from details for better logging
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
        // In a real application, you might want to log this error to a file
        // error_log('Logging failed: ' . $e->getMessage());
        // Do not expose database errors to the user.
    }
}
?>