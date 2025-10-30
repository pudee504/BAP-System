<?php
// FILENAME: users.php
// DESCRIPTION: Admin-only page for managing users (view list, create new users, assign roles and leagues).

session_start();
// --- Security Check ---
// Redirect if user is not logged in or is not an Admin.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'Admin') {
    header('Location: login.php');
    exit();
}

$pdo = require 'db.php'; // Database connection
require_once 'logger.php'; // Logging functionality

$error = '';
$success = '';

/** Validates password complexity. */
function is_password_valid($password) {
    $errors = [];
    if (strlen($password) < 8) { $errors[] = "must be at least 8 characters long"; }
    if (!preg_match('/[A-Z]/', $password)) { $errors[] = "must contain at least one uppercase letter"; }
    if (!preg_match('/[a-z]/', $password)) { $errors[] = "must contain at least one lowercase letter"; }
    if (!preg_match('/[0-9]/', $password)) { $errors[] = "must contain at least one number"; }
    if (!preg_match('/[\W_]/', $password)) { $errors[] = "must contain at least one special character"; }
    return empty($errors) ? true : "Password " . implode(', ', $errors) . ".";
}

// --- Handle Create User Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role_id = $_POST['role_id'];
    $assigned_leagues = isset($_POST['leagues']) ? $_POST['leagues'] : []; // Leagues selected for assignment

    // Basic validation.
    if (empty($username) || empty($password) || empty($role_id)) {
        $error = 'Username, password, and role are required.';
    } else {
        // Password complexity validation.
        $password_validation_result = is_password_valid($password);
        if ($password_validation_result !== true) {
            $error = $password_validation_result;
        } else {
            // Check if username already exists.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
                log_action('USER_CREATE_ATTEMPT', 'FAILURE', "Attempted to create user '{$username}' but it already exists.");
            } else {
                // --- Create User and Assign Leagues ---
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role_id) VALUES (?, ?, ?)");
                
                if ($stmt->execute([$username, $password_hash, $role_id])) {
                    $user_id = $pdo->lastInsertId(); // Get the ID of the new user
                    // Assign selected leagues if any.
                    if (!empty($assigned_leagues)) {
                        $assignment_id = 1; // Assuming a default assignment type
                        $stmt_assign = $pdo->prepare("INSERT INTO league_manager_assignment (user_id, league_id, assignment_id) VALUES (?, ?, ?)");
                        foreach ($assigned_leagues as $league_id) {
                            $stmt_assign->execute([$user_id, $league_id, $assignment_id]);
                        }
                    }
                    $success = 'User created successfully!';
                    log_action('USER_CREATE', 'SUCCESS', "Successfully created new user '{$username}' (ID: {$user_id}).");
                } else {
                    $error = 'Failed to create user.';
                    log_action('USER_CREATE', 'FAILURE', "Database error while trying to create user '{$username}'.");
                }
            }
        }
    }
}

// --- Fetch Data for Display ---
// Get all users with their roles and assigned leagues (concatenated).
$stmt = $pdo->query("
    SELECT 
        u.id, u.username, r.role_name,
        GROUP_CONCAT(l.league_name ORDER BY l.league_name SEPARATOR '|') as assigned_leagues
    FROM users u
    JOIN role r ON u.role_id = r.id
    LEFT JOIN league_manager_assignment lma ON u.id = lma.user_id AND r.role_name != 'Admin' /* Don't list leagues for Admins */
    LEFT JOIN league l ON lma.league_id = l.id
    GROUP BY u.id, u.username, r.role_name
    ORDER BY u.username
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all roles and leagues for the creation form.
$roles = $pdo->query("SELECT id, role_name FROM role ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
$leagues = $pdo->query("SELECT id, league_name FROM league ORDER BY league_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" href="css/style.css"> 
    
    <style>
        /* Styles for password validation UI */
        #password-validation-ui { list-style-type: none; padding: 0; margin-top: 0.75rem; font-size: 0.9em; }
        #password-validation-ui li { color: var(--bap-red); transition: color 0.3s ease; }
        #password-validation-ui li.valid { color: #155724; } /* Green */
        #password-validation-ui li.valid::before { content: '✓ '; font-weight: bold; }
        #password-validation-ui li::before { content: '✗ '; font-weight: bold; }
        /* Styles for scrolling league assignment box */
        .league-checkbox-group { max-height: 150px; overflow-y: auto; border: 1px solid var(--border-color); padding: 1rem; background-color: #fdfdff; border-radius: 8px; }
        .league-checkbox-group label { display: block; font-weight: normal; }
        .league-checkbox-group input[type="checkbox"] { width: auto; margin-right: 0.5rem; vertical-align: middle; }
        /* Styles for displaying assigned leagues in the table */
        .league-list { list-style-type: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 0.25rem; }
        .league-list li { background-color: #e9ecef; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85em; }
        .all-leagues-badge { display: inline-block; background-color: var(--bap-blue); color: var(--text-light); padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.85em; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main>
        <div class="dashboard-container">
            <div class="page-header">
                <h1>User Management</h1>
            </div>

            <?php if ($error): ?><div class="form-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="success-message"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="section-header">
                <h2>Create New User</h2>
            </div>
            <form action="users.php" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Password must contain at least one number, one uppercase, one lowercase, one special character, and be at least 8 characters long.">
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
                        <option value="">-- Select a Role --</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Leagues (for League Managers):</label>
                    <div class="league-checkbox-group">
                    <?php if (empty($leagues)): ?>
                        <p class="info-message" style="padding:0;">No leagues available.</p>
                    <?php else: ?>
                        <?php foreach ($leagues as $league): ?>
                            <label>
                                <input type="checkbox" name="leagues[]" value="<?= $league['id'] ?>">
                                <?= htmlspecialchars($league['league_name']) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
                <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
            </form>

            <div class="section-header">
                <h2>Existing Users</h2>
            </div>
            <div class="table-wrapper">
                <table class="category-table">
                    <thead>
                        <tr><th>Username</th><th>Role</th><th>Assigned Leagues</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['role_name']) ?></td>
                            <td>
                                <?php if ($user['role_name'] === 'Admin'): ?>
                                    <span class="all-leagues-badge">All Leagues</span>
                                <?php elseif ($user['assigned_leagues']): ?>
                                    <ul class="league-list">
                                        <?php 
                                        // Split the concatenated league names back into an array
                                        $user_leagues = explode('|', $user['assigned_leagues']);
                                        foreach ($user_leagues as $league_name):
                                        ?>
                                            <li><?= htmlspecialchars($league_name) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    None
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a href="edit_user.php?id=<?= $user['id'] ?>">Edit</a>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <a href="delete_user.php?id=<?= $user['id'] ?>" class="action-delete" onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
    // JavaScript for password visibility toggle and real-time validation UI.
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const togglePasswordCheckbox = document.getElementById('togglePassword');
        
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
            // Check each validation rule and add/remove 'valid' class accordingly.
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