<?php
require 'db.php';
session_start();

$team_id = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT);
if (!$team_id) {
    die("Invalid team.");
}

// Fetch team details
$stmt = $pdo->prepare("SELECT t.team_name, c.category_name FROM team t JOIN category c ON t.category_id = c.id WHERE t.id = ?");
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
        // Insert player
        $insert_player = $pdo->prepare("INSERT INTO player (first_name, last_name, position) VALUES (?, ?, ?)");
        $insert_player->execute([$first_name, $last_name, $position]);

        // Get inserted player_id
        $player_id = $pdo->lastInsertId();

        // Link player to team
        $link_stmt = $pdo->prepare("INSERT INTO player_team (player_id, team_id) VALUES (?, ?)");
        $link_stmt->execute([$player_id, $team_id]);

        header("Location: team_details.php?team_id=" . $team_id);
        exit;
    } else {
        $error = "All fields are required.";
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
?>

<!DOCTYPE html>
<html>
<head>
  <title><?= htmlspecialchars($team['team_name']) ?> - Player Management</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="dashboard-container">
  <h1>Team: <?= htmlspecialchars($team['team_name']) ?></h1>
  <p><strong>Category:</strong> <?= htmlspecialchars($team['category_name']) ?></p>

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


    <button  class="create-league-button" type="submit">Add Player</button>
  </form>

  <h2>Players</h2>
<?php if ($player_list): ?>
  <table class="category-table">
    <thead>
  <tr>
    <th>#</th>
    <th>First Name</th>
    <th>Last Name</th>
    <th>Position</th>
    <th>Actions</th> <!-- Add this to match Edit/Delete column -->
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
