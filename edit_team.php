<?php
// FILENAME: edit_team.php
// DESCRIPTION: Displays and processes a form to update an existing team's name.

require 'db.php';
session_start();
require_once 'logger.php'; // For logging admin actions

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- Authorization Check ---
require_once 'includes/auth_functions.php';
$team_id = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);
if (!has_league_permission($pdo, $_SESSION['user_id'], 'team', $team_id)) {
    $_SESSION['error'] = 'You do not have permission to edit this team.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for team {$team_id} on edit_team.php");
    header('Location: dashboard.php');
    exit;
}

// --- 1. Get and Validate Team ID ---
$team_id = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);
if (!$team_id) {
    log_action('EDIT_TEAM', 'FAILURE', 'Attempted to access edit page with an invalid team ID.');
    die("Invalid team ID");
}

// --- 2. Fetch Current Team Data ---
// Get the existing data to pre-fill the form.
$stmt = $pdo->prepare("SELECT * FROM team WHERE id = ?");
$stmt->execute([$team_id]);
$team = $stmt->fetch();

if (!$team) {
    log_action('EDIT_TEAM', 'FAILURE', "Attempted to edit a non-existent team (ID: {$team_id}).");
    die("Team not found");
}

// --- 3. Handle Form Submission (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_name = trim($_POST['team_name']);
    
    // Validate that the name is not empty.
    if ($new_name) {
        try {
            // Update the team name in the database.
            $update = $pdo->prepare("UPDATE team SET team_name = ? WHERE id = ?");
            $update->execute([$new_name, $team_id]);

            // --- Log the update ---
            if ($team['team_name'] !== $new_name) {
                // Log if the name actually changed.
                $log_details = "Updated team in category ID {$team['category_id']}: changed name from '{$team['team_name']}' to '{$new_name}' (Team ID: {$team_id}).";
                log_action('UPDATE_TEAM', 'SUCCESS', $log_details);
            } else {
                // Log if the user submitted without making a change.
                $log_details = "Submitted update for team '{$team['team_name']}' (ID: {$team_id}) with no changes.";
                log_action('UPDATE_TEAM', 'INFO', $log_details);
            }
        } catch (PDOException $e) {
            // Log any database errors.
            $log_details = "Database error updating team '{$team['team_name']}' (ID: {$team_id}). Error: " . $e->getMessage();
            log_action('UPDATE_TEAM', 'FAILURE', $log_details);
            die("A database error occurred.");
        }

        // Redirect back to the team list on success.
        header("Location: category_details.php?category_id=" . $team['category_id'] . "&tab=teams");
        exit;
    } else {
        // Handle validation error (empty name).
        $error = "Team name cannot be empty.";
        $log_details = "Failed to update team (ID: {$team_id}) because the name was empty.";
        log_action('UPDATE_TEAM', 'FAILURE', $log_details);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Team</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="form-container">
        <h1>Edit Team</h1>

        <?php if (!empty($error)): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="team_name">Team Name</label>
                <input type="text" id="team_name" name="team_name" value="<?= htmlspecialchars($team['team_name']) ?>" required>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Update Team</button>
                 <a href="category_details.php?category_id=<?= $team['category_id'] ?>&tab=teams" class="back-link">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>