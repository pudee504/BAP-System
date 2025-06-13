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
if ($format_id === 3) {
    $num_groups = (int) ($_POST['num_groups'] ?? 0);
    $advance_per_group = (int) ($_POST['advance_per_group'] ?? 0);

    $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams, num_groups, advance_per_group) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$category_id, $format_id, $num_teams, $num_groups, $advance_per_group]);
} else {
    $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams) VALUES (?, ?, ?)");
    $stmt->execute([$category_id, $format_id, $num_teams]);
}



    header("Location: league_details.php?id=" . $league_id);
    exit;
}

// Make sure the league_id exists in the URL
if (!isset($_GET['league_id']) || !filter_var($_GET['league_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])) {
    die("Invalid or missing league ID in URL. Example: add_category.php?league_id=1");
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
  <?php
  // Fetch formats from database
  $stmt = $pdo->query("SELECT id, format_name FROM format");
  while ($format = $stmt->fetch()) {
      echo '<option value="' . $format['id'] . '">' . htmlspecialchars($format['format_name']) . '</option>';
  }
  ?>
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

      <div id="roundRobinOptions" style="display: none;">
  <label for="numGroups">Number of Groups</label>
  <input type="number" name="num_groups" id="numGroups" min="1" max="8">

  <label for="advancePerGroup">Teams Advancing per Group</label>
  <select name="advance_per_group" id="advancePerGroup">
    <option value="">-- Select Teams --</option>
    <option value="1">1</option>
    <option value="2">2</option>
</select>
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
  const roundOptions = document.getElementById('roundRobinOptions');

  if (format === '1' || format === '2') {
    dropdown.style.display = 'block';
    custom.style.display = 'none';
    roundOptions.style.display = 'none';
  } else if (format === '3') {
    dropdown.style.display = 'none';
    custom.style.display = 'block';
    roundOptions.style.display = 'block';
  } else {
    dropdown.style.display = 'none';
    custom.style.display = 'none';
    roundOptions.style.display = 'none';
  }
}
</script>

</body>
</html>
