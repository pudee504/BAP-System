<?php
session_start();

// Redirect to login page if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = require '../src/db.php';

$error = '';
$success = '';

// Password validation function (consistent with users.php)
function is_password_valid($password) {
    $errors = [];
    if (strlen($password) < 8) { $errors[] = "must be at least 8 characters long"; }
    if (!preg_match('/[A-Z]/', $password)) { $errors[] = "must contain at least one uppercase letter"; }
    if (!preg_match('/[a-z]/', $password)) { $errors[] = "must contain at least one lowercase letter"; }
    if (!preg_match('/[0-9]/', $password)) { $errors[] = "must contain at least one number"; }
    if (!preg_match('/[\W_]/', $password)) { $errors[] = "must contain at least one special character"; }
    return empty($errors) ? true : "Password " . implode(', ', $errors) . ".";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];

    // Fetch the user's current hashed password from the database
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($current_password, $user['password'])) {
        $error = 'Your current password is not correct.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'The new passwords do not match.';
    } else {
        $validation_result = is_password_valid($new_password);
        if ($validation_result !== true) {
            $error = $validation_result;
        } else {
            // All checks passed, update the password
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
    <link rel="stylesheet" href="style.css"> 
    
    <style>
        /* Using the same consistent container style */
        .main-container { max-width: 960px; margin: 2rem auto; background: white; color: #333; padding: 2rem; border-radius: 12px; }
        .main-container h1 { color: #1f2593; margin-bottom: 1.5rem; }
        .form-section { background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #eee; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; color: #333; }
        .error { background-color: #f8d7da; color: #721c24; }
        .success { background-color: #d4edda; color: #155724; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #333; font-weight: bold; }
        .main-container input[type="password"] { color: #333; background-color: #fff; border: 1px solid #ccc; width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #ff6b00; color: white; border: none; cursor: pointer; border-radius: 8px; font-weight: bold; }
        button:hover { background-color: #e65c00; }
        .password-toggle { margin-top: 10px; }
        #password-validation-ui { list-style-type: none; padding: 0; margin-top: 10px; font-size: 0.9em; }
        #password-validation-ui li { color: #dc3545; margin-bottom: 4px; }
        #password-validation-ui li.valid { color: #28a745; }
        #password-validation-ui li.valid::before { content: '✓ '; }
        #password-validation-ui li::before { content: '✗ '; }
    </style>
</head>
<body>
    <?php include '../src/includes/header.php'; ?>

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
        
        // Password visibility toggle for the new password field
        togglePasswordCheckbox.addEventListener('change', function() {
            newPasswordInput.type = this.checked ? 'text' : 'password';
        });

        const validators = {
            length: pass => pass.length >= 8,
            upper: pass => /[A-Z]/.test(pass),
            lower: pass => /[a-z]/.test(pass),
            number: pass => /[0-9]/.test(pass),
            special: pass => /[\W_]/.test(pass)
        };

        newPasswordInput.addEventListener('keyup', function() {
            const pass = newPasswordInput.value;
            // Only show validation UI if user starts typing a new password
            document.getElementById('password-validation-ui').style.display = pass.length > 0 ? 'block' : 'none';

            for (const [id, validator] of Object.entries(validators)) {
                const el = document.getElementById(id);
                if (validator(pass)) {
                    el.classList.add('valid');
                } else {
                    el.classList.remove('valid');
                }
            }
        });

        // Initially hide the validation UI
        document.getElementById('password-validation-ui').style.display = 'none';
    });
    </script>
</body>
</html>
