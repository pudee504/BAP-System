<?php
session_start();
require 'db.php'; // Connect to database using PDO

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid CSRF token';
        header("Location: index.php");
        exit;
    }

    // Sanitize input
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Basic input validation
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please fill in all fields';
        $_SESSION['temp_username'] = $username; // Save input for reuse
        header("Location: index.php");
        exit;
    }

    // Query the database for the user
    $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check password if user exists
    if ($user && password_verify($password, $user['password'])) {
        // ✅ Login successful: Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        header("Location: dashboard.php");
        exit;
    } else {
        // ❌ Login failed: Set error and retain username
        $_SESSION['error'] = 'Invalid username or password';
        $_SESSION['temp_username'] = $username;
        header("Location: index.php");
        exit;
    }
} else {
    // Deny access to the login script if not using POST
    header("Location: index.php");
    exit;
}
