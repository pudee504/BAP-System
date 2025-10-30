<?php
// FILENAME: change_password.php
// DESCRIPTION: Allows a logged-in user to change their own password.

session_start();

// --- 1. Authentication Check ---
// Redirect to login page if user is not logged in.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = require 'db.php';

$error = '';
$success = '';

/**
 * Validates a password against security rules.
 * @return true|string True if valid, or an error message string if invalid.
 */
function is_password_valid($password) {
    $errors = [];
    if (strlen($password) < 8) { $errors[] = "must be at least 8 characters long"; }
    if (!preg_match('/[A-Z]/', $password)) { $errors[] = "must contain at least one uppercase letter"; }
    if (!preg_match('/[a-z]/', $password)) { $errors[] = "must contain at least one lowercase letter"; }
    if (!preg_match('/[0-9]/', $password)) { $errors[] = "must contain at least one number"; }
    if (!preg_match('/[\W_]/', $password)) { $errors[] = "must contain at least one special character"; }
    return empty($errors) ? true : "Password " . implode(', ', $errors) . ".";
}

// --- 2. Handle Form Submission (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];

    // --- 3. Validation Logic ---
    // Fetch the user's current hashed password.
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // a. Check if the current password is correct.
    if (!$user || !password_verify($current_password, $user['password'])) {
        $error = 'Your current password is not correct.';
    // b. Check if the new passwords match.
    } elseif ($new_password !== $confirm_password) {
        $error = 'The new passwords do not match.';
    } else {
        // c. Check if the new password meets security rules.
        $validation_result = is_password_valid($new_password);
        if ($validation_result !== true) {
            $error = $validation_result;
        } else {
            // --- 4. Update Password ---
            // All checks passed, hash and update the new password.
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($update_stmt->execute([$new_password_hash, $user_id])) {
                $success = 'Your password has been changed successfully!';
            } else {
                $error = 'An error occurred while updating your password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link rel="stylesheet" href="css/style.css"> 
    
    <style>
        /* Styles for the change password form */
        .main-container { max-width: 960px; margin: 2rem auto; background: white; color: #333; padding: 2rem; border-radius: 12px; }
        .main-container h1 { color: #1f2593; margin-bottom: 1.5rem; }
        .form-section { background-color: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eee; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; }
        .error { background-color: #f8d7da; color: #721c24; }
        .success { background-color: #d4edda; color: #155724; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        .main-container input[type="password"] { border: 1px solid #ccc; width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #ff6b00; color: white; border: none; cursor: pointer; border-radius: 8px; font-weight: bold; }
        /* Styles for the real-time password validation UI */
        #password-validation-ui { list-style-type: none; padding: 0; margin-top: 10px; font-size: 0.9em; }
        #password-validation-ui li { color: #dc3545; }
        #password-validation-ui li.valid { color: #28a745; }
        #password-validation-ui li.valid::before { content: '✓ '; }
        #password-validation-ui li::before { content: '✗ '; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main>
        <div class="main-container">
            <h1>Change Your Password</h1>

            <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <section class="form-section">
                <form action="change_password.php" method="POST">
                    <div class="form-group">
                        <label for="current_password">Current Password:</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" required>
                        <div class="password-toggle">
                            <input type="checkbox" id="togglePassword">
                            <label for="togglePassword" style="font-weight:normal; display:inline;">Show Password</label>
                        </div>
                        <ul id="password-validation-ui">
                            <li id="length">At least 8 characters</li>
                            <li id="upper">At least one uppercase letter</li>
                            <li id="lower">At least one lowercase letter</li>
                            <li id="number">At least one number</li>
                            <li id="special">At least one special character</li>
                        </ul>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit">Update Password</button>
                </form>
            </section>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const newPasswordInput = document.getElementById('new_password');
        const togglePasswordCheckbox = document.getElementById('togglePassword');
        
        // Toggle password visibility (text/password).
        togglePasswordCheckbox.addEventListener('change', function() {
            newPasswordInput.type = this.checked ? 'text' : 'password';
        });

        // Define the validation rules.
        const validators = {
            length: pass => pass.length >= 8,
            upper: pass => /[A-Z]/.test(pass),
            lower: pass => /[a-z]/.test(pass),
            number: pass => /[0-9]/.test(pass),
            special: pass => /[\W_]/.test(pass)
        };

        // Run validation on every keyup event in the new password field.
        newPasswordInput.addEventListener('keyup', function() {
            const pass = newPasswordInput.value;
            // Only show the checklist if the user has started typing.
            document.getElementById('password-validation-ui').style.display = pass.length > 0 ? 'block' : 'none';

            // Check each rule and add/remove the 'valid' class.
            for (const [id, validator] of Object.entries(validators)) {
                const el = document.getElementById(id);
                if (validator(pass)) {
                    el.classList.add('valid');
                } else {
                    el.classList.remove('valid');
                }
            }
        });

        // Hide the validation checklist initially.
        document.getElementById('password-validation-ui').style.display = 'none';
    });
    </script>
</body>
</html>