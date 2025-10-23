<?php
session_start();
// Security check to ensure only Admins can access this page
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'Admin') {
    header('Location: dashboard.php'); // Redirect non-admins
    exit();
}

$pdo = require 'db.php';

$user_id_to_edit = $_GET['id'] ?? null;
if (!$user_id_to_edit) {
    header('Location: users.php');
    exit();
}

$error = '';
$success = '';

// Password validation function
function is_password_valid($password) {
    $errors = [];
    if (strlen($password) < 8) { $errors[] = "must be at least 8 characters long"; }
    if (!preg_match('/[A-Z]/', $password)) { $errors[] = "must contain at least one uppercase letter"; }
    if (!preg_match('/[a-z]/', $password)) { $errors[] = "must contain at least one lowercase letter"; }
    if (!preg_match('/[0-9]/', $password)) { $errors[] = "must contain at least one number"; }
    if (!preg_match('/[\W_]/', $password)) { $errors[] = "must contain at least one special character"; }
    return empty($errors) ? true : "Password " . implode(', ', $errors) . ".";
}

// Handle form submission for updating a user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $role_id = $_POST['role_id'];
    $assigned_leagues = isset($_POST['leagues']) ? $_POST['leagues'] : [];

    $can_proceed = true;

    // 1. Update password only if a new one is provided AND it's valid
    if (!empty($password)) {
        $password_validation_result = is_password_valid($password);
        if ($password_validation_result !== true) {
            $error = $password_validation_result;
            $can_proceed = false; // Stop the update if password is new but invalid
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$password_hash, $user_id_to_edit]);
        }
    }

    if ($can_proceed) {
        // 2. Update the role
        $stmt_role = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
        $stmt_role->execute([$role_id, $user_id_to_edit]);

        // 3. Update league assignments
        $stmt_delete = $pdo->prepare("DELETE FROM league_manager_assignment WHERE user_id = ?");
        $stmt_delete->execute([$user_id_to_edit]);

        if (!empty($assigned_leagues)) {
            $assignment_id = 1; 
            $stmt_assign = $pdo->prepare("INSERT INTO league_manager_assignment (user_id, league_id, assignment_id) VALUES (?, ?, ?)");
            foreach ($assigned_leagues as $league_id) {
                $stmt_assign->execute([$user_id_to_edit, $league_id, $assignment_id]);
            }
        }
        $success = "User updated successfully!";
    }
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id_to_edit]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit();
}

// Fetch all roles, leagues, and the user's current assignments for the form
$all_roles = $pdo->query("SELECT id, role_name FROM role ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
$all_leagues = $pdo->query("SELECT id, league_name FROM league ORDER BY league_name")->fetchAll(PDO::FETCH_ASSOC);
$stmt_assigned = $pdo->prepare("SELECT league_id FROM league_manager_assignment WHERE user_id = ?");
$stmt_assigned->execute([$user_id_to_edit]);
$current_assignments = $stmt_assigned->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link rel="stylesheet" href="style.css">
    
    <style>
        /* Page-specific styles for custom components */
        #password-validation-ui {
            list-style-type: none;
            padding: 0;
            margin-top: 0.75rem;
            font-size: 0.9em;
        }
        #password-validation-ui li {
            color: var(--bap-red);
            margin-bottom: 0.25rem;
            transition: color 0.3s ease;
        }
        #password-validation-ui li.valid {
            color: #155724; /* A good, accessible green */
        }
        #password-validation-ui li.valid::before {
            content: '✓ ';
            font-weight: bold;
        }
        #password-validation-ui li::before {
            content: '✗ ';
            font-weight: bold;
        }
        .league-checkbox-group {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            padding: 1rem;
            background-color: #fdfdff;
            border-radius: 8px;
        }
        .league-checkbox-group label {
            display: block;
            font-weight: normal;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }
        .league-checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 0.5rem;
            vertical-align: middle;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main>
        <div class="form-container">
            <div class="page-header">
                <h1>Edit User: <?= htmlspecialchars($user['username']) ?></h1>
            </div>

            <?php if ($error): ?><div class="form-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="success-message"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <form action="edit_user.php?id=<?= $user_id_to_edit ?>" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="password">New Password:</label>
                    <input type="password" id="password" name="password" placeholder="Leave blank to keep current password" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must contain at least one number, one uppercase, one lowercase, one special character, and be at least 8 characters long.">
                    <div class="password-toggle">
                        <input type="checkbox" id="togglePassword">
                        <label for="togglePassword">Show Password</label>
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
                    <label for="role_id">Role:</label>
                    <select id="role_id" name="role_id" required>
                        <?php foreach ($all_roles as $role): ?>
                            <option value="<?= $role['id'] ?>" <?= ($user['role_id'] == $role['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assign Leagues (for League Managers):</label>
                    <div class="league-checkbox-group">
                    <?php foreach ($all_leagues as $league): ?>
                        <label>
                            <input type="checkbox" name="leagues[]" value="<?= $league['id'] ?>" <?= in_array($league['id'], $current_assignments) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($league['league_name']) ?>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update User</button>
                    <a href="users.php" class="btn btn-secondary">Back to User List</a>
                </div>
            </form>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const togglePasswordCheckbox = document.getElementById('togglePassword');
        const validationUI = document.getElementById('password-validation-ui');
        
        // Initially hide the validation UI
        validationUI.style.display = 'none';

        togglePasswordCheckbox.addEventListener('change', function() {
            passwordInput.type = this.checked ? 'text' : 'password';
        });

        const validators = {
            length: pass => pass.length >= 8,
            upper: pass => /[A-Z]/.test(pass),
            lower: pass => /[a-z]/.test(pass),
            number: pass => /[0-9]/.test(pass),
            special: pass => /[\W_]/.test(pass)
        };

        passwordInput.addEventListener('keyup', function() {
            const pass = passwordInput.value;
            // Only show validation UI if user starts typing a new password
            validationUI.style.display = pass.length > 0 ? 'block' : 'none';

            for (const [id, validator] of Object.entries(validators)) {
                const el = document.getElementById(id);
                if (validator(pass)) {
                    el.classList.add('valid');
                } else {
                    el.classList.remove('valid');
                }
            }
        });
    });
    </script>
</body>
</html>