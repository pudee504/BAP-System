<?php
require 'db.php';

$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

// Fetch player data
$stmt = $pdo->prepare("SELECT * FROM player WHERE id = ?");
$stmt->execute([$id]);
$player = $stmt->fetch();

if (!$player) {
    die("Player not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first_name']);
    $last = trim($_POST['last_name']);
    $pos = $_POST['position'];

    $update = $pdo->prepare("UPDATE player SET first_name = ?, last_name = ?, position = ? WHERE id = ?");
    $update->execute([$first, $last, $pos, $id]);

    header("Location: team_details.php?team_id=$team_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Player</title>
  <link rel="stylesheet" href="style.css">

</head>
<body class="login-page">
  <div class="login-container">
<h1>Edit Player</h1>
<form method="POST">
    <label>First Name:</label><br>
    <input type="text" name="first_name" value="<?= htmlspecialchars($player['first_name']) ?>" required><br>

    <label>Last Name:</label><br>
    <input type="text" name="last_name" value="<?= htmlspecialchars($player['last_name']) ?>" required><br>

    <label>Position:</label><br>
    <select name="position" required>
      <?php
      $positions = ['Center', 'Power Forward', 'Small Forward', 'Shooting Guard', 'Point Guard'];
      foreach ($positions as $pos) {
          $selected = $pos == $player['position'] ? 'selected' : '';
          echo "<option value='$pos' $selected>$pos</option>";
      }
      ?>
    </select><br><br>

    <button type="submit">Save Changes</button>
</form>
    </div>
    </body>
</html>