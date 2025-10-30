<?php
// FILENAME: team_details.php
// DESCRIPTION: Displays the roster for a specific team and provides forms to add new players
// or assign existing "free agent" players to this team.

session_start();
require 'db.php';
require_once 'logger.php'; // For logging actions

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- 1. Get and Validate Team ID ---
$team_id = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT);
if (!$team_id) {
    die("Invalid team.");
}

// --- 2. Fetch Team Details ---
// Get team name and its category for display and links.
$stmt = $pdo->prepare("SELECT t.team_name, c.category_name, c.id as category_id FROM team t JOIN category c ON t.category_id = c.id WHERE t.id = ?");
$stmt->execute([$team_id]);
$team = $stmt->fetch();

if (!$team) {
    die("Team not found.");
}

// --- 3. Handle "Add New Player" Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_player'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $position = trim($_POST['position'] ?? '');

    // Basic validation: ensure all fields are filled.
    if ($first_name && $last_name && $position) {
        try {
            $pdo->beginTransaction();

            // a. Insert the new player into the 'player' table.
            $insert_player = $pdo->prepare("INSERT INTO player (first_name, last_name, position) VALUES (?, ?, ?)");
            $insert_player->execute([$first_name, $last_name, $position]);
            $player_id = $pdo->lastInsertId();

            // b. Link the new player to the current team in 'player_team'.
            $link_stmt = $pdo->prepare("INSERT INTO player_team (player_id, team_id) VALUES (?, ?)");
            $link_stmt->execute([$player_id, $team_id]);
            
            $pdo->commit();

            // Log the successful addition.
            $log_details = "Added player '{$first_name} {$last_name}' (ID: {$player_id}) to team '{$team['team_name']}' (ID: {$team_id}). Position: {$position}.";
            log_action('ADD_PLAYER', 'SUCCESS', $log_details);

        } catch (PDOException $e) {
            // Handle database errors.
            $pdo->rollBack();
            $log_details = "Database error adding player '{$first_name} {$last_name}' to team '{$team['team_name']}'. Error: " . $e->getMessage();
            log_action('ADD_PLAYER', 'FAILURE', $log_details);
            die("A database error occurred while adding the player.");
        }

        // Redirect back to this page to refresh the player list.
        header("Location: team_details.php?team_id=" . $team_id);
        exit;
    } else {
        // Handle validation error.
        $error = "All fields are required to add a new player.";
        log_action('ADD_PLAYER', 'FAILURE', "Attempted to add a player to team '{$team['team_name']}' with missing fields.");
    }
}

// --- 4. Fetch Current Roster ---
// Get all players currently linked to this team.
$players = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name, p.position
    FROM player p
    JOIN player_team pt ON p.id = pt.player_id
    WHERE pt.team_id = ?
    ORDER BY p.last_name, p.first_name
");
$players->execute([$team_id]);
$player_list = $players->fetchAll();

// --- 5. Fetch Free Agents ---
// Get players who are NOT currently linked to ANY team.
$free_agent_stmt = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name
    FROM player p
    LEFT JOIN player_team pt ON p.id = pt.player_id
    WHERE pt.player_id IS NULL
    ORDER BY p.last_name, p.first_name
");
$free_agent_stmt->execute();
$free_agents = $free_agent_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($team['team_name']) ?> - Player Management</title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <div class="page-header">
        <h1>Team: <?= htmlspecialchars($team['team_name']) ?></h1>
        <p style="margin: 0; font-size: 1.1rem;">
            <strong>Category:</strong> 
            <a href="category_details.php?category_id=<?= $team['category_id'] ?>"><?= htmlspecialchars($team['category_name']) ?></a>
        </p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="team-management-grid">
        <div class="management-forms">
            <div class="form-container">
                <h2>Add New Player</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="position">Position</label>
                        <select id="position" name="position" required>
                            <option value="">Select Position</option>
                            <option value="Center">Center</option>
                            <option value="Power Forward">Power Forward</option>
                            <option value="Small Forward">Small Forward</option>
                            <option value="Shooting Guard">Shooting Guard</option>
                            <option value="Point Guard">Point Guard</option>
                        </select>
                    </div>
                    <div class="form-actions">
                         <button type="submit" name="add_new_player" class="btn btn-primary">Create and Add Player</button>
                    </div>
                </form>
            </div>
            
            <div class="form-container" style="margin-top: 2.5rem;">
                <h2>Assign Free Agent</h2>
                <?php if ($free_agents): ?>
                    <form action="assign_player.php" method="POST">
                        <input type="hidden" name="team_id" value="<?= $team_id ?>">
                        <div class="form-group">
                            <label for="player_id">Select Player</label>
                            <select id="player_id" name="player_id" required>
                                <option value="">Select a Free Agent</option>
                                <?php foreach ($free_agents as $agent): ?>
                                    <option value="<?= $agent['id'] ?>">
                                        <?= htmlspecialchars($agent['last_name'] . ', ' . $agent['first_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Assign to Team</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="info-message" style="padding: 1rem 0;">There are no free agents available to assign.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="roster-container">
            <h2>Current Roster</h2>
            <div class="table-wrapper">
                <?php if ($player_list): ?>
                    <table class="category-table">
                        <thead>
                            <tr>
                                <th>First Name</th><th>Last Name</th><th>Position</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($player_list as $player): ?>
                                <tr>
                                    <td><?= htmlspecialchars($player['first_name']) ?></td>
                                    <td><?= htmlspecialchars($player['last_name']) ?></td>
                                    <td><?= htmlspecialchars($player['position']) ?></td>
                                    <td class="actions">
                                        <a href="edit_player.php?id=<?= $player['id'] ?>&team_id=<?= $team_id ?>">Edit</a>
                                        <a href="delete_player.php?id=<?= $player['id'] ?>&team_id=<?= $team_id ?>" class="action-delete" onclick="return confirm('Are you sure you want to remove this player from the team?');">Remove</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="info-message">No players have been added to this team yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>