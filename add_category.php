<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_id = (int) $_POST['league_id'];
    $category_name = trim($_POST['category_name']);
    $format_id = (int) $_POST['game_format'];

    if ($format_id === 3) {
        $num_teams = (int) ($_POST['num_teams_input'] ?? 0);
    } else {
        $num_teams = (int) ($_POST['num_teams_dropdown'] ?? 0);
    }

    if (!$league_id || !$category_name || !$format_id || !$num_teams) {
        die("Invalid input.");
    }

    // Insert into category
    $stmt = $pdo->prepare("INSERT INTO category (league_id, category_name) VALUES (?, ?)");
    $stmt->execute([$league_id, $category_name]);
    $category_id = $pdo->lastInsertId();

    // Insert into category_format
    $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams) VALUES (?, ?, ?)");
    $stmt->execute([$category_id, $format_id, $num_teams]);

    header("Location: league_details.php?id=" . $league_id);
    exit;
}

// Make sure the league_id exists in the URL
if (!isset($_GET['league_id'])) {
    die("Missing league ID in URL. Example: add_category.php?league_id=1");
}

$league_id = (int) $_GET['league_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Category</title>
  <link rel="stylesheet" href="style.css">

</head>
<body class="login-page">
  <div class="login-container">
    <h1>Add Category</h1>
    <form action="add_category.php" method="POST">
      <label for="categoryName">Category Name</label>
      <input type="text" name="category_name" id="categoryName" required>

      <label for="gameFormat">Game Format</label>
      <select name="game_format" id="gameFormat" required onchange="toggleTeamsInput(this.value)">
        <option value="">-- Select Format --</option>
        <option value="1">Single</option>
        <option value="2">Double</option>
        <option value="3">Round Robin</option>
      </select>

      <div id="dropdownTeams" style="display:none;">
        <label for="numTeamsSelect">Number of Teams</label>
        <select name="num_teams_dropdown" id="numTeamsSelect">
          <option value="">-- Select Teams --</option>
          <option value="2">2</option>
          <option value="4">4</option>
          <option value="8">8</option>
          <option value="16">16</option>
          <option value="32">32</option>
        </select>
      </div>

      <div id="customTeams" style="display:none;">
        <label for="numTeamsInput">Number of Teams</label>
        <input type="number" name="num_teams_input" id="numTeamsInput" min="2">
      </div>

      <!-- Keep the league_id as hidden input -->
      <input type="hidden" name="league_id" value="<?= $league_id ?>">

      <button type="submit" class="login-button">Add Category</button>
    </form>
  </div>

  <script>
    function toggleTeamsInput(format) {
      const dropdown = document.getElementById('dropdownTeams');
      const custom = document.getElementById('customTeams');

      if (format === '1' || format === '2') {
        dropdown.style.display = 'block';
        custom.style.display = 'none';
      } else if (format === '3') {
        dropdown.style.display = 'none';
        custom.style.display = 'block';
      } else {
        dropdown.style.display = 'none';
        custom.style.display = 'none';
      }
    }
  </script>
</body>
</html>
