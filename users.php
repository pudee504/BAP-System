<?php
session_start();
// Redirect to login page if user is not logged in or not an Admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name']) || $_SESSION['role_name'] !== 'Admin') {
    header('Location: login.php');
    exit();
}

$pdo = require 'db.php';
require_once 'logger.php'; // --- ADDED: Include the logger file ---

$error = '';
$success = '';

function is_password_valid($password) {
    $errors = [];
    if (strlen($password) < 8) { $errors[] = "must be at least 8 characters long"; }
    if (!preg_match('/[A-Z]/', $password)) { $errors[] = "must contain at least one uppercase letter"; }
    if (!preg_match('/[a-z]/', $password)) { $errors[] = "must contain at least one lowercase letter"; }
    if (!preg_match('/[0-9]/', $password)) { $errors[] = "must contain at least one number"; }
    if (!preg_match('/[\W_]/', $password)) { $errors[] = "must contain at least one special character"; }
    return empty($errors) ? true : "Password " . implode(', ', $errors) . ".";
}

// Handle form submission for creating a new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role_id = $_POST['role_id'];
    $assigned_leagues = isset($_POST['leagues']) ? $_POST['leagues'] : [];

    if (empty($username) || empty($password) || empty($role_id)) {
        $error = 'Username, password, and role are required.';
    } else {
        $password_validation_result = is_password_valid($password);
        if ($password_validation_result !== true) {
            $error = $password_validation_result;
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'Username already exists.';
                // --- ADDED: Log failed attempt (username exists) ---
                $log_details = "Attempted to create user '{$username}' but it already exists.";
                log_action('USER_CREATE_ATTEMPT', 'FAILURE', $log_details);
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role_id) VALUES (?, ?, ?)");
                
                if ($stmt->execute([$username, $password_hash, $role_id])) {
                    $user_id = $pdo->lastInsertId();
                    if (!empty($assigned_leagues)) {
                        $assignment_id = 1; 
                        $stmt_assign = $pdo->prepare("INSERT INTO league_manager_assignment (user_id, league_id, assignment_id) VALUES (?, ?, ?)");
                        foreach ($assigned_leagues as $league_id) {
                            $stmt_assign->execute([$user_id, $league_id, $assignment_id]);
                        }
                    }
                    $success = 'User created successfully!';
                    // --- ADDED: Log successful creation ---
                    $log_details = "Successfully created new user '{$username}' (ID: {$user_id}).";
                    log_action('USER_CREATE', 'SUCCESS', $log_details);
                } else {
                    $error = 'Failed to create user.';
                    // --- ADDED: Log failed attempt (database error) ---
                    $log_details = "Database error while trying to create user '{$username}'.";
                    log_action('USER_CREATE', 'FAILURE', $log_details);
                }
            }
        }
    }
}

// Fetch all users, their roles, and their assigned leagues
$stmt = $pdo->query("
    SELECT 
        u.id, 
        u.username, 
        r.role_name,
        GROUP_CONCAT(l.league_name ORDER BY l.league_name SEPARATOR '|') as assigned_leagues
    FROM users u
    JOIN role r ON u.role_id = r.id
    LEFT JOIN league_manager_assignment lma ON u.id = lma.user_id AND r.role_name != 'Admin'
    LEFT JOIN league l ON lma.league_id = l.id
    GROUP BY u.id, u.username, r.role_name
    ORDER BY u.username
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all available roles and leagues for the form
$roles = $pdo->query("SELECT id, role_name FROM role ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
$leagues = $pdo->query("SELECT id, league_name FROM league ORDER BY league_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <link rel="stylesheet" href="style.css"> 
    
    <style>
        /* All your existing styles for this page remain the same */
        .users-container { max-width: 960px; margin: 2rem auto; background: white; color: #333; padding: 2rem; border-radius: 12px; }
        .users-container h1, .users-container h2 { color: #1f2593; margin-bottom: 1.5rem; }
        .form-section, .list-section { background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; color: #333; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; }
        .actions a { margin-right: 10px; color: #007bff; text-decoration: none; }
        .actions a:hover { text-decoration: underline; }
        .actions .delete { color: #dc3545; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; color: #333; }
        .error { background-color: #f8d7da; color: #721c24; }
        .success { background-color: #d4edda; color: #155724; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #333; font-weight: bold; }
        .users-container input[type="text"], .users-container input[type="password"], .users-container select { color: #333; background-color: #fff; border: 1px solid #ccc; width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 15px; background-color: #ff6b00; color: white; border: none; cursor: pointer; border-radius: 8px; font-weight: bold; }
        button:hover { background-color: #e65c00; }
        .password-wrapper { position: relative; }
        .password-toggle { margin-top: 10px; }
        #password-validation-ui { list-style-type: none; padding: 0; margin-top: 10px; font-size: 0.9em; }
        #password-validation-ui li { color: #dc3545; margin-bottom: 4px; }
        #password-validation-ui li.valid { color: #28a745; }
        #password-validation-ui li.valid::before { content: '✓ '; }
        #password-validation-ui li::before { content: '✗ '; }
        .league-checkbox-group { max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background-color: #fff; border-radius: 4px; }
        .league-checkbox-group label { display: block; font-weight: normal; margin-bottom: 5px; }
        .league-list { list-style-type: none; padding: 0; margin: 0; }
        .league-list li { background-color: #e9ecef; padding: 4px 8px; border-radius: 4px; margin-bottom: 4px; font-size: 0.9em; }
        .all-leagues-badge { background-color: #17a2b8; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.9em; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main>
        <div class="users-container">
            <h1>User Management</h1>

            <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <section class="form-section">
                <h2>Create New User</h2>
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
                        <label for="role_id">Role:</label>
                        <select id="role_id" name="role_id" required>
                            <option value="">-- Select a Role --</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Assign Leagues:</label>
                        <div class="league-checkbox-group">
                        <?php if (empty($leagues)): ?>
                            <p>No leagues available.</p>
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
                    
                    <button type="submit" name="create_user">Create User</button>
                </form>
            </section>

            <section class="list-section">
                <h2>Existing Users</h2>
                <table>
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
                                <a href="delete_user.php?id=<?= $user['id'] ?>" class="delete" onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>

    <script>
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