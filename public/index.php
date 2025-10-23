<?php
session_start();
include '../src/db.php';

// Generate a CSRF token if it doesn't exist yet
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Retrieve previously entered username from session if available
$username = $_SESSION['temp_username'] ?? '';

// Remove it so it doesn't persist on future visits
unset($_SESSION['temp_username']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - BAP Federation Makilala Chapter</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="login-page">
  <div class="login-container">
    <img src="bap_logo.jpg" alt="BAP Federation Logo" class="login-logo">
    <h1>Welcome</h1>

    <?php if (!empty($_SESSION['error'])): ?>
      <p class="error"><?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form action="login.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

      <div class="form-group">
        <label for="username">Username</label>
        <input 
          type="text" 
          id="username" 
          name="username" 
          required 
          value="<?= htmlspecialchars($username) ?>"
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          required
        >
        <div class="password-toggle">
            <input type="checkbox" id="togglePassword">
            <label for="togglePassword">Show Password</label>
        </div>
      </div>

      <button type="submit" class="login-button">Login</button>
    </form>
  </div>

  <script>
    const toggle = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    toggle.addEventListener('change', function () {
      passwordInput.type = this.checked ? 'text' : 'password';
    });
  </script>
</body>
</html>
