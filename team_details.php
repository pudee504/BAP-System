<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

$team_id = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT);
if (!$team_id) {
    die("Invalid team.");
}

// Fetch team details
$stmt = $pdo->prepare("SELECT t.team_name, c.category_name, c.id as category_id FROM team t JOIN category c ON t.category_id = c.id WHERE t.id = ?");
$stmt->execute([$team_id]);
$team = $stmt->fetch();

if (!$team) {
    die("Team not found.");
}

// Handle new player form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $position = trim($_POST['position'] ?? '');

    if ($first_name && $last_name && $position) {
        try {
            // Start a transaction for safety
            $pdo->beginTransaction();

            // 1. Insert player
            $insert_player = $pdo->prepare("INSERT INTO player (first_name, last_name, position) VALUES (?, ?, ?)");
            $insert_player->execute([$first_name, $last_name, $position]);

            $player_id = $pdo->lastInsertId();

            // 2. Link player to team
            $link_stmt = $pdo->prepare("INSERT INTO player_team (player_id, team_id) VALUES (?, ?)");
            $link_stmt->execute([$player_id, $team_id]);
            
            // If both queries succeed, commit the changes
            $pdo->commit();

            // LOGGING: Record the successful creation
            $log_details = "Added player '{$first_name} {$last_name}' (ID: {$player_id}) to team '{$team['team_name']}' (ID: {$team_id}). Position: {$position}.";
            log_action('ADD_PLAYER', 'SUCCESS', $log_details);

        } catch (PDOException $e) {
            // If anything fails, undo all changes
            $pdo->rollBack();

            // LOGGING: Record the database error
            $log_details = "Database error adding player '{$first_name} {$last_name}' to team '{$team['team_name']}'. Error: " . $e->getMessage();
            log_action('ADD_PLAYER', 'FAILURE', $log_details);
            die("A database error occurred while adding the player.");
        }

        header("Location: team_details.php?team_id=" . $team_id);
        exit;
    } else {
        $error = "All fields are required.";
        // LOGGING: Record the validation failure
        log_action('ADD_PLAYER', 'FAILURE', "Attempted to add a player to team '{$team['team_name']}' with missing fields.");
    }
}

// Fetch players for this team
$players = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name, p.position
    FROM player p
    JOIN player_team pt ON p.id = pt.player_id
    WHERE pt.team_id = ?
");
$players->execute([$team_id]);
$player_list = $players->fetchAll();

// --- ADD THIS CODE to team_details.php ---

// Fetch "Free Agents" (players not on any team)
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
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($team['team_name']) ?> - Player Management</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
<div class="dashboard-container">
  <h1>Team: <?= htmlspecialchars($team['team_name']) ?></h1>
  <p><strong>Category:</strong> <a href="category_details.php?category_id=<?= $team['category_id'] ?>"><?= htmlspecialchars($team['category_name']) ?></a></p>

  <h2>Add Player</h2>
  <?php if (!empty($error)): ?>
    <p style="color: red;"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>
  <form method="POST">
    <label>First Name:</label><br>
    <input type="text" name="first_name" required><br>

    <label>Last Name:</label><br>
    <input type="text" name="last_name" required><br>

    <label>Position:</label><br>
    <select name="position" required>
      <option value="">-- Select Position --</option>
      <option value="Center">Center</option>
      <option value="Power Forward">Power Forward</option>
      <option value="Small Forward">Small Forward</option>
      <option value="Shooting Guard">Shooting Guard</option>
      <option value="Point Guard">Point Guard</option>
    </select><br>

    <button class="create-league-button" type="submit">Add Player</button>
  </form>

  <hr style="margin: 2rem 0;">

<h2>Assign Existing Player (Free Agent)</h2>
<?php if ($free_agents): ?>
    <form action="assign_player.php" method="POST">
        <input type="hidden" name="team_id" value="<?= $team_id ?>">
        
        <label>Select Player:</label><br>
        <select name="player_id" required>
            <option value="">-- Select a Free Agent --</option>
            <?php foreach ($free_agents as $agent): ?>
                <option value="<?= $agent['id'] ?>">
                    <?= htmlspecialchars($agent['last_name'] . ', ' . $agent['first_name']) ?>
                </option>
            <?php endforeach; ?>
        </select><br>

        <button class="create-league-button" type="submit">Assign Player to Team</button>
    </form>
<?php else: ?>
    <p>There are no free agents available to assign.</p>
<?php endif; ?>

  <h2>Players</h2>
<?php if ($player_list): ?>
  <table class="category-table">
    <thead>
      <tr>
        <th>#</th>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Position</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($player_list as $index => $player): ?>
        <tr>
          <td><?= $index + 1 ?></td>
          <td><?= htmlspecialchars($player['first_name']) ?></td>
          <td><?= htmlspecialchars($player['last_name']) ?></td>
          <td><?= htmlspecialchars($player['position']) ?></td>
          <td>
            <a href="edit_player.php?id=<?= $player['id'] ?>&team_id=<?= $team_id ?>">Edit</a> |
            <a href="delete_player.php?id=<?= $player['id'] ?>&team_id=<?= $team_id ?>" onclick="return confirm('Are you sure you want to delete this player?');">Delete</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p>No players registered yet.</p>
<?php endif; ?>

</div>
</body>
</html>