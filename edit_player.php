<?php
require 'db.php';
session_start(); // << START SESSION
require_once 'logger.php'; // << INCLUDE THE LOGGER

$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

if (!$id || !$team_id) {
    log_action('EDIT_PLAYER', 'FAILURE', 'Attempted to access edit page with an invalid ID.');
    die("Invalid ID provided.");
}

// Fetch player data
$stmt = $pdo->prepare("SELECT * FROM player WHERE id = ?");
$stmt->execute([$id]);
$player = $stmt->fetch();

if (!$player) {
    log_action('EDIT_PLAYER', 'FAILURE', "Attempted to edit a non-existent player (ID: {$id}).");
    die("Player not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name']);
    $last = trim($_POST['last_name']);
    $pos = $_POST['position'];

    if ($first && $last && $pos) {
        try {
            $update = $pdo->prepare("UPDATE player SET first_name = ?, last_name = ?, position = ? WHERE id = ?");
            $update->execute([$first, $last, $pos, $id]);

            // --- DETAILED SUCCESS LOGGING ---
            $changes = [];
            // Compare old values from $player with new submitted values
            if ($player['first_name'] !== $first) {
                $changes[] = "first name from '{$player['first_name']}' to '{$first}'";
            }
            if ($player['last_name'] !== $last) {
                $changes[] = "last name from '{$player['last_name']}' to '{$last}'";
            }
            if ($player['position'] !== $pos) {
                $changes[] = "position from '{$player['position']}' to '{$pos}'";
            }

            if (!empty($changes)) {
                $log_details = "Updated player '{$player['first_name']} {$player['last_name']}' (ID: {$id}): changed " . implode(', ', $changes) . ".";
                log_action('UPDATE_PLAYER', 'SUCCESS', $log_details);
            } else {
                // Log if the user clicked "Save" but made no changes
                $log_details = "Submitted update for player '{$player['first_name']} {$player['last_name']}' (ID: {$id}) with no changes.";
                log_action('UPDATE_PLAYER', 'INFO', $log_details);
            }

        } catch (PDOException $e) {
            $log_details = "Database error updating player '{$player['first_name']} {$player['last_name']}' (ID: {$id}). Error: " . $e->getMessage();
            log_action('UPDATE_PLAYER', 'FAILURE', $log_details);
            die("A database error occurred.");
        }
        
        header("Location: team_details.php?team_id=$team_id");
        exit;

    } else {
        $error = "All fields are required.";
        // --- FAILURE LOGGING ---
        $log_details = "Failed to update player (ID: {$id}) due to missing fields.";
        log_action('UPDATE_PLAYER', 'FAILURE', $log_details);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Player</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <div class="login-container">
    <h1>Edit Player</h1>
    
    <?php if (!empty($error)): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>First Name:</label><br>
        <input type="text" name="first_name" value="<?= htmlspecialchars($player['first_name']) ?>" required><br>

        <label>Last Name:</label><br>
        <input type="text" name="last_name" value="<?= htmlspecialchars($player['last_name']) ?>" required><br>

        <label>Position:</label><br>
        <select name="position" required>
          <?php
          $positions = ['Center', 'Power Forward', 'Small Forward', 'Shooting Guard', 'Point Guard'];
          foreach ($positions as $p) {
              $selected = ($p == $player['position']) ? 'selected' : '';
              echo "<option value=\"$p\" $selected>" . htmlspecialchars($p) . "</option>";
          }
          ?>
        </select><br><br>

        <button class="login-button" type="submit">Save Changes</button>
    </form>
  </div>
</body>
</html>