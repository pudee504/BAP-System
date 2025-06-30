<?php
require_once 'db.php';

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) die('No game_id provided');

// Fetch existing settings
$stmt = $pdo->prepare("SELECT * FROM game_settings WHERE game_id = ?");
$stmt->execute([$game_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
  // Insert default settings
  $stmt = $pdo->prepare("INSERT INTO game_settings (game_id, quarter_duration, max_team_fouls_per_qtr, timeouts_per_half) VALUES (?, 600, 4, 2)");
  $stmt->execute([$game_id]);

  // Re-fetch the settings after insertion
  $stmt = $pdo->prepare("SELECT * FROM game_settings WHERE game_id = ?");
  $stmt->execute([$game_id]);
  $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html>
<head><title>Game Settings</title>
<link rel="stylesheet" href="style.css">

</head>
<body class="login-page">
    <div class="login-container">
<h2>Edit Quarter Durations for Game #<?= htmlspecialchars($game_id) ?></h2>
<form method="POST" action="update_game_settings.php">
  <input type="hidden" name="game_id" value="<?= $game_id ?>">

  <?php
    $quarters = ['q1', 'q2', 'q3', 'q4'];
    foreach ($quarters as $q) {
      $label = strtoupper($q) . " Duration (minutes):";
      $min = 1;
      $max = 12;
      $value = isset($settings[$q . '_duration']) ? $settings[$q . '_duration'] / 60 : 10;
      echo "<label>$label</label><br>";
      echo "<input type='number' name='{$q}_duration' value='{$value}' min='{$min}' max='{$max}'><br><br>";
    }
  ?>

  <button type="submit">Save Settings</button>
</form>

</body>
</html>
