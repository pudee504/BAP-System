<?php
// FILENAME: logout.php
// DESCRIPTION: Logs the user out by logging the action, destroying the session, and redirecting to the login page.

// 1. Start session to access session data.
session_start();

// 2. Include the logger function.
require_once 'logger.php';

// --- Log the logout action ---
// Log before destroying the session so user details are still available.
if (isset($_SESSION['user_id'])) {
    log_action('LOGOUT', 'SUCCESS');
}

// 3. Clear all session variables.
$_SESSION = array();

// 4. Destroy the session itself.
session_destroy();

// 5. Redirect the user to the login page (index.php).
header("Location: index.php");
exit; // Stop further script execution.
?>