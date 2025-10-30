<?php
// login.php — Handles user authentication and logging

session_start();
require 'db.php'; // Connect to database
require_once 'logger.php'; // Include action logger

// --- Allow only POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Validate CSRF token ---
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid CSRF token';
        log_action('CSRF_VALIDATION', 'FAILURE', 'Invalid CSRF token from login form.');
        header("Location: index.php");
        exit;
    }

    // --- Sanitize and check inputs ---
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please fill in all fields';
        $_SESSION['temp_username'] = $username;
        header("Location: index.php");
        exit;
    }

    // --- Fetch user and role from database ---
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

    // --- Verify password ---
    if ($user && password_verify($password, $user['password'])) {
        // Successful login → create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_name'] = $user['role_name'];

        log_action('LOGIN', 'SUCCESS');
        header("Location: dashboard.php");
        exit;
    } else {
        // Failed login → show error and log attempt
        $_SESSION['error'] = 'Invalid username or password';
        $_SESSION['temp_username'] = $username;

        $log_details = 'Invalid username or password for user: ' . htmlspecialchars($username);
        log_action('LOGIN_ATTEMPT', 'FAILURE', $log_details);

        header("Location: index.php");
        exit;
    }

} else {
    // Redirect non-POST requests
    header("Location: index.php");
    exit;
}
