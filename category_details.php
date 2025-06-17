<?php
require 'db.php';
session_start();

// Validate category ID
$category_id = filter_var($_GET['category_id'], FILTER_VALIDATE_INT);


// Fetch category details
$stmt = $pdo->prepare("
    SELECT c.category_name, f.format_name, cf.format_id, cf.num_teams, cf.num_groups, cf.advance_per_group
    FROM category c
    JOIN category_format cf ON c.id = cf.category_id
    JOIN format f ON cf.format_id = f.id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$category = $stmt->fetch();

// Get how many teams are already registered
$teamStmt = $pdo->prepare("SELECT * FROM team WHERE category_id = ?");
$teamStmt->execute([$category_id]);
$teams = $teamStmt->fetchAll();
$team_count = count($teams);

// How many more can be added
$remaining_slots = $category['num_teams'] - $team_count;


if (!$category) {
    die("Category not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Category Details</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .tabs {
      display: flex;
      margin-top: 20px;
    }
    .tab {
      padding: 10px 20px;
      background: #eee;
      margin-right: 5px;
      cursor: pointer;
      border-radius: 5px 5px 0 0;
    }
    .tab.active {
      background: #fff;
      font-weight: bold;
    }
    .tab-content {
      display: none;
      padding: 20px;
      background: #fff;
      border: 1px solid #ccc;
      border-top: none;
    }
    .tab-content.active {
      display: block;
    }
  </style>
</head>
<body>
    <?php include 'header.php'; ?>
  <div class="dashboard-container">
    <h1>Category: <?= htmlspecialchars($category['category_name']) ?></h1>
    <p><strong>Format:</strong> <?= htmlspecialchars($category['format_name']) ?></p>
    <p><strong>Number of Teams:</strong> <?= $category['num_teams'] ?></p>
    <?php if ($category['num_groups']): ?>
      <p><strong>Groups:</strong> <?= $category['num_groups'] ?> (<?= $category['advance_per_group'] ?> advance per group)</p>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
      <div class="tab active" onclick="showTab('teams')">Teams</div>
      <div class="tab" onclick="showTab('schedule')">Schedule</div>
      <div class="tab" onclick="showTab('standings')">Standings</div>
    </div>

    <!-- Tab Contents -->
    <div class="tab-content active" id="teams">

    <?php
$is_round_robin = ($category['format_id'] == 3);
$allow_add_team = $is_round_robin || $remaining_slots > 0;
?>

<h2>Teams (<?= $team_count ?><?= !$is_round_robin ? ' / ' . $category['num_teams'] : '' ?> Registered)</h2>

<?php if (!$allow_add_team): ?>
  <p><strong>All team slots are filled.</strong></p>
<?php else: ?>
  <form action="add_team.php" method="POST">
    <input type="hidden" name="category_id" value="<?= $category_id ?>">
    <label for="team_name">Team Name</label>
    <input type="text" name="team_name" id="team_name" required>
    <button type="submit">Add Team</button>
  </form>
  <?php if (!$is_round_robin): ?>
    <p><?= $remaining_slots ?> team slot(s) left</p>
  <?php endif; ?>
<?php endif; ?>


<!-- List of registered teams -->
<ul>
  <?php foreach ($teams as $team): ?>
    <li><?= htmlspecialchars($team['team_name']) ?></li>
  <?php endforeach; ?>
  </ul>

    </div>

    <div class="tab-content" id="schedule">
      <h2>Schedule</h2>
      <!-- Schedule logic -->
      <p>This will handle match creation and schedule viewing.</p>
    </div>

    <div class="tab-content" id="standings">
      <h2>Standings</h2>
      <!-- Standings logic -->
      <p>This will compute standings once games are played.</p>
    </div>
  </div>

  <script>
    function showTab(id) {
      document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
      document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

      document.querySelector([onclick="showTab('${id}')"]).classList.add('active');
      document.getElementById(id).classList.add('active');
    }
  </script>
</body>
</html>