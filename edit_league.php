<?php 
// edit_league.php

session_start();
require 'db.php';
require_once 'logger.php'; // Include activity logger

// --- Check login ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- Authorization Check ---
require_once 'includes/auth_functions.php';
$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!has_league_permission($pdo, $_SESSION['user_id'], 'league', $league_id)) {
    $_SESSION['error'] = 'You do not have permission to edit this league.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for league {$league_id} on edit_league.php");
    header('Location: dashboard.php');
    exit;
}

// --- Validate league ID ---
$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$league_id) {
    log_action('EDIT_LEAGUE', 'FAILURE', 'Invalid league ID access attempt.');
    die("Invalid league ID.");
}

// --- Fetch league details ---
$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    log_action('EDIT_LEAGUE', 'FAILURE', "Non-existent league (ID: {$league_id}).");
    die("League not found.");
}

// --- Handle form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_name = trim($_POST['league_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = trim($_POST['status'] ?? '');

    // Validate inputs
    if ($league_name && $location && $status) {
        try {
            // Update league record
            $update = $pdo->prepare("UPDATE league SET league_name = ?, location = ?, status = ? WHERE id = ?");
            $update->execute([$league_name, $location, $status, $league_id]);

            // --- Log changes ---
            $changes = [];
            if ($league['league_name'] !== $league_name) $changes[] = "name from '{$league['league_name']}' to '{$league_name}'";
            if ($league['location'] !== $location) $changes[] = "location from '{$league['location']}' to '{$location}'";
            if ($league['status'] !== $status) $changes[] = "status from '{$league['status']}' to '{$status}'";

            if (!empty($changes)) {
                log_action('UPDATE_LEAGUE', 'SUCCESS', "Updated league '{$league['league_name']}' (ID: {$league_id}): " . implode(', ', $changes) . ".");
            } else {
                log_action('UPDATE_LEAGUE', 'INFO', "No changes made to league '{$league['league_name']}' (ID: {$league_id}).");
            }

        } catch (PDOException $e) {
            // Log DB error
            log_action('UPDATE_LEAGUE', 'FAILURE', "DB error updating league (ID: {$league_id}): " . $e->getMessage());
            die("A database error occurred.");
        }

        header("Location: dashboard.php");
        exit;
    } else {
        // Log missing input error
        $error = "All fields are required.";
        log_action('UPDATE_LEAGUE', 'FAILURE', "Failed update for league '{$league['league_name']}' (ID: {$league_id}) due to missing fields.");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit League</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="form-container">
    <h1>Edit League</h1>

    <?php if (!empty($error)): ?>
        <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Edit form -->
    <form method="POST" action="edit_league.php?id=<?= $league_id ?>">
        <div class="form-group">
            <label for="league_name">League Name</label>
            <input type="text" id="league_name" name="league_name" value="<?= htmlspecialchars($league['league_name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" value="<?= htmlspecialchars($league['location']) ?>" required>
        </div>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" required>
                <option value="Upcoming" <?= $league['status'] === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="Active" <?= $league['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Completed" <?= $league['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update League</button>
        </div>
    </form>
</div>
</body>
</html>
