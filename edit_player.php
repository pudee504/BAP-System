<?php
// FILENAME: edit_player.php
// DESCRIPTION: Displays and processes a form to update an existing player's details (name, position).

require 'db.php';
session_start();
require_once 'logger.php'; 

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- Authorization Check ---
require_once 'includes/auth_functions.php';
$player_id = (int) ($_REQUEST['id'] ?? 0);
if (!has_league_permission($pdo, $_SESSION['user_id'], 'player', $player_id)) {
    $_SESSION['error'] = 'You do not have permission to edit this player.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for player {$player_id} on edit_player.php");
    header('Location: dashboard.php');
    exit;
}
// --- 1. Get and Validate IDs ---
// Get the player ID to edit and the team ID for redirecting back.
$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

if (!$id || !$team_id) {
    log_action('EDIT_PLAYER', 'FAILURE', 'Attempted to access edit page with an invalid ID.');
    die("Invalid ID provided.");
}

// --- 2. Fetch Current Player Data ---
// Get the existing data to pre-fill the form.
$stmt = $pdo->prepare("SELECT * FROM player WHERE id = ?");
$stmt->execute([$id]);
$player = $stmt->fetch();

if (!$player) {
    log_action('EDIT_PLAYER', 'FAILURE', "Attempted to edit a non-existent player (ID: {$id}).");
    die("Player not found.");
}

// --- 3. Handle Form Submission (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name']);
    $last = trim($_POST['last_name']);
    $pos = $_POST['position'];

    // Validate that all fields are filled.
    if ($first && $last && $pos) {
        try {
            // Update the player record in the database.
            $update = $pdo->prepare("UPDATE player SET first_name = ?, last_name = ?, position = ? WHERE id = ?");
            $update->execute([$first, $last, $pos, $id]);

            // --- Build a detailed log message ---
            // Check exactly which fields were changed.
            $changes = [];
            if ($player['first_name'] !== $first) {
                $changes[] = "first name from '{$player['first_name']}' to '{$first}'";
            }
            if ($player['last_name'] !== $last) {
                $changes[] = "last name from '{$player['last_name']}' to '{$last}'";
            }
            if ($player['position'] !== $pos) {
                $changes[] = "position from '{$player['position']}' to '{$pos}'";
            }

            // Log the specific changes or log "no changes".
            if (!empty($changes)) {
                $log_details = "Updated player '{$player['first_name']} {$player['last_name']}' (ID: {$id}): changed " . implode(', ', $changes) . ".";
                log_action('UPDATE_PLAYER', 'SUCCESS', $log_details);
            } else {
                $log_details = "Submitted update for player '{$player['first_name']} {$player['last_name']}' (ID: {$id}) with no changes.";
                log_action('UPDATE_PLAYER', 'INFO', $log_details);
            }

        } catch (PDOException $e) {
            // Log any database errors.
            $log_details = "Database error updating player '{$player['first_name']} {$player['last_name']}' (ID: {$id}). Error: " . $e->getMessage();
            log_action('UPDATE_PLAYER', 'FAILURE', $log_details);
            die("A database error occurred.");
        }
        
        // Redirect back to the team details page on success.
        header("Location: team_details.php?team_id=$team_id");
        exit;

    } else {
        // Handle validation error (empty fields).
        $error = "All fields are required.";
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
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="form-container">
    <h1>Edit Player</h1>
    
    <?php if (!empty($error)): ?>
        <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="edit_player.php?id=<?= $id ?>&team_id=<?= $team_id ?>">
        <div class="form-group">
            <label for="first_name">First Name:</label>
            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($player['first_name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="last_name">Last Name:</label>
            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($player['last_name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="position">Position:</label>
            <select id="position" name="position" required>
                <?php
                // Loop through positions and mark the player's current one as 'selected'.
                $positions = ['Center', 'Power Forward', 'Small Forward', 'Shooting Guard', 'Point Guard'];
                foreach ($positions as $p) {
                    $selected = ($p == $player['position']) ? 'selected' : '';
                    echo "<option value=\"$p\" $selected>" . htmlspecialchars($p) . "</option>";
                }
                ?>
            </select>
        </div>
        
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save Changes</button>
             <a href="team_details.php?team_id=<?= $team_id ?>" class="back-link">Cancel</a>
        </div>
    </form>
</div>
</body>
</html>