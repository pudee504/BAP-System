<?php
// 1. Start the session to access session data.
session_start();

// 2. Include your new logger.
require_once 'logger.php';

// --- START: DATABASE LOGGING ---

// Log the logout action before the session is destroyed.
if (isset($_SESSION['user_id'])) {
    log_action('LOGOUT', 'SUCCESS');
}

// --- END: DATABASE LOGGING ---

// 3. Unset all of the session variables.
$_SESSION = array();

// 4. Destroy the session completely.
session_destroy();

// 5. Redirect the user to the login page.
header("Location: index.php");
exit; // Ensures no more code is executed after the redirect.
?>