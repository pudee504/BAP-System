<?php
session_start();
include 'db.php';

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
  <title>Login - Basketball League Management and Statistics System</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body class="login-page">
  <div class="login-container">
    <h1>Welcome</h1>

    <!-- Display error message if one exists -->
    <?php if (!empty($_SESSION['error'])): ?>
      <p class="error"><?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php unset($_SESSION['error']); // Clear the error after showing it ?>
    <?php endif; ?>

    <!-- Login form -->
    <form action="login.php" method="POST">
      <!-- CSRF protection -->
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

      <!-- Username input (retains last entered value if login failed) -->
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

      <!-- Password input with show/hide toggle -->
      <div class="form-group">
        <label for="password">Password</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          required
        >
        <input type="checkbox" id="togglePassword">
        <label for="togglePassword" class="toggle-label">Show Password</label>
      </div>

      <button type="submit" class="login-button">Login</button>
    </form>
  </div>

  <!-- JavaScript to toggle password visibility -->
  <script>
    const toggle = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    toggle.addEventListener('change', function () {
      passwordInput.type = this.checked ? 'text' : 'password';
    });
  </script>
</body>
</html>
