<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$league_id) {
    log_action('EDIT_LEAGUE', 'FAILURE', 'Attempted to access edit page with an invalid league ID.');
    die("Invalid league ID.");
}

// Fetch current league data
$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    log_action('EDIT_LEAGUE', 'FAILURE', "Attempted to edit a non-existent league (ID: {$league_id}).");
    die("League not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_name = trim($_POST['league_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = trim($_POST['status'] ?? '');

    if ($league_name && $location && $status) {
        try {
            $update = $pdo->prepare("UPDATE league SET league_name = ?, location = ?, status = ? WHERE id = ?");
            $update->execute([$league_name, $location, $status, $league_id]);

            // --- DETAILED SUCCESS LOGGING ---
            $changes = [];
            // Compare old values from $league with new values from the form
            if ($league['league_name'] !== $league_name) {
                $changes[] = "name from '{$league['league_name']}' to '{$league_name}'";
            }
            if ($league['location'] !== $location) {
                $changes[] = "location from '{$league['location']}' to '{$location}'";
            }
            if ($league['status'] !== $status) {
                $changes[] = "status from '{$league['status']}' to '{$status}'";
            }

            if (!empty($changes)) {
                $log_details = "Updated league '{$league['league_name']}' (ID: {$league_id}): changed " . implode(', ', $changes) . ".";
                log_action('UPDATE_LEAGUE', 'SUCCESS', $log_details);
            } else {
                // Optional: Log when user clicks update but makes no changes
                $log_details = "Submitted update for league '{$league['league_name']}' (ID: {$league_id}) with no changes.";
                log_action('UPDATE_LEAGUE', 'INFO', $log_details);
            }

        } catch (PDOException $e) {
            $log_details = "Database error updating league '{$league['league_name']}' (ID: {$league_id}). Error: " . $e->getMessage();
            log_action('UPDATE_LEAGUE', 'FAILURE', $log_details);
            die("A database error occurred.");
        }

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "All fields are required.";
        // --- FAILURE LOGGING ---
        $log_details = "Failed to update league '{$league['league_name']}' (ID: {$league_id}) due to missing fields.";
        log_action('UPDATE_LEAGUE', 'FAILURE', $log_details);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit League</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
<div class="login-container">
    <h1>Edit League</h1>

    <?php if (!empty($error)): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>League Name:</label><br>
        <input type="text" name="league_name" value="<?= htmlspecialchars($league['league_name']) ?>" required><br>

        <label>Location:</label><br>
        <input type="text" name="location" value="<?= htmlspecialchars($league['location']) ?>" required><br>

        <label>Status:</label><br>
        <select name="status" required>
            <option value="Upcoming" <?= $league['status'] === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
            <option value="Active" <?= $league['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Completed" <?= $league['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
        </select><br><br>

        <button type="submit">Update League</button>
    </form>
</div>
</body>
</html>