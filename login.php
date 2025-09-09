<?php
session_start();
require 'db.php'; // Connect to database using PDO
require_once 'logger.php'; // Include the logger

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid CSRF token';
        log_action('CSRF_VALIDATION', 'FAILURE', 'Invalid CSRF token from login form.');
        header("Location: index.php");
        exit;
    }

    // Sanitize input
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Basic input validation
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please fill in all fields';
        $_SESSION['temp_username'] = $username;
        header("Location: index.php");
        exit;
    }

    // --- CHANGED: Modified query to JOIN the 'role' table and get the role_name ---
    $stmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.username, 
            u.password, 
            r.role_name 
        FROM users u
        JOIN role r ON u.role_id = r.id
        WHERE u.username = :username
    ");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check password if user exists
    if ($user && password_verify($password, $user['password'])) {
        // ✅ Login successful: Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
        // --- CHANGED: Store the user's role NAME, not their ID ---
        $_SESSION['role_name'] = $user['role_name'];

        // LOGGING: Log the successful login
        log_action('LOGIN', 'SUCCESS');

        header("Location: dashboard.php");
        exit;
    } else {
        // ❌ Login failed: Set error and retain username
        $_SESSION['error'] = 'Invalid username or password';
        $_SESSION['temp_username'] = $username;
        
        // LOGGING: Log the failed login attempt
        $log_details = 'Invalid username or password for user: ' . htmlspecialchars($username);
        log_action('LOGIN_ATTEMPT', 'FAILURE', $log_details);
        
        header("Location: index.php");
        exit;
    }
} else {
    // Deny access to the login script if not using POST
    header("Location: index.php");
    exit;
}