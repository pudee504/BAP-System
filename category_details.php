<?php
session_start();
$active_tab = $_GET['tab'] ?? 'teams';

require 'includes/category_info.php';
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
<?php include 'includes/header.php'; ?>
<div class="dashboard-container">
  <h1>Category: <?= htmlspecialchars($category['category_name']) ?></h1>
  <p><strong>Format:</strong> <?= htmlspecialchars($category['format_name']) ?></p>
  <p><strong>Number of Teams:</strong> <?= $category['num_teams'] ?></p>
  
  
  <?php if ($category['num_groups']): ?>
    <p><strong>Groups:</strong> <?= $category['num_groups'] ?> (<?= $category['advance_per_group'] ?> advance per group)</p>
  <?php endif; ?>
<div class="tabs">
  <div class="tab <?= $active_tab === 'teams' ? 'active' : '' ?>" data-tab="teams">Teams</div>
  <div class="tab <?= $active_tab === 'schedule' ? 'active' : '' ?>" data-tab="schedule">Schedule</div>
  <div class="tab <?= $active_tab === 'standings' ? 'active' : '' ?>" data-tab="standings">Standings</div>
</div>


 
<div class="tab-content <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams">
    <h3>Add New Team</h3>
  <form action="add_team.php" method="POST" style="margin-bottom: 20px;">
    <input type="hidden" name="category_id" value="<?= $category_id ?>">
    <input type="text" name="team_name" placeholder="Team Name" required>
    <button type="submit">Add Team</button>
  </form>
  <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>
  <?php if ($team_count >= $category['num_teams'] && !$is_round_robin): ?>
  <p style="color:red;"><strong>Team limit reached. No more teams can be added.</strong></p>
<?php endif; ?>
    <?php if ($is_round_robin): ?>
  <?php include 'includes/team_table_round_robin.php'; ?>
<?php else: ?>
  <?php include 'includes/team_table_bracket.php'; ?>
<?php endif; ?>

<?php include 'includes/lock_controls.php'; ?>

  </div>

  <div class="tab-content <?= $active_tab === 'schedule' ? 'active' : '' ?>" id="schedule">
    <h2>Schedule</h2>
    <form action="single_elimination.php" method="POST" onsubmit="return confirm('This will generate a full single elimination bracket. Proceed?')">
  <input type="hidden" name="category_id" value="<?= $category_id ?>">
  <button type="submit">Generate Single Elimination Schedule</button>
</form>

<?php
$schedule = $pdo->prepare("SELECT g.*, t1.team_name AS home_name, t2.team_name AS away_name
  FROM game g
  LEFT JOIN team t1 ON g.hometeam_id = t1.id
  LEFT JOIN team t2 ON g.awayteam_id = t2.id
  WHERE g.category_id = ?
  ORDER BY round ASC, id ASC
");
$schedule->execute([$category_id]);
$games = $schedule->fetchAll();
?>

<?php if ($games): ?>
  <table class="category-table">
  <thead>
    <tr>
      <th>Match #</th>
      <th>Round</th>
      <th>Match</th>
      <th>Status</th>
      <th>Game Date</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($games as $index => $game): ?>
<tr>
  <td><?= $index + 1 ?></td>

        <td>Round <?= $game['round'] ?></td>
        <td>
  <?= $game['hometeam_id'] ? '<a href="team_details.php?team_id=' . $game['hometeam_id'] . '">' . htmlspecialchars($game['home_name']) . '</a>' : 'TBD' ?>
  vs
  <?= $game['awayteam_id'] ? '<a href="team_details.php?team_id=' . $game['awayteam_id'] . '">' . htmlspecialchars($game['away_name']) . '</a>' : 'TBD' ?>
</td>


        <td><?= $game['game_status'] ?></td>
  <td id="game-date-<?= $game['id'] ?>">
  <?php if ($game['game_date'] && $game['game_date'] !== '0000-00-00 00:00'): ?>
    <?= date("F j, Y g A", strtotime($game['game_date'])) ?>
  <?php else: ?>
    <span style="color: red;">None</span>
  <?php endif; ?>
</td>

        <td>
  <button type="button" onclick="toggleDateForm(<?= $game['id'] ?>)">Set Date</button>
  <form id="date-form-<?= $game['id'] ?>" action="update_game_date.php" method="POST" style="display: none; margin-top: 5px;">
    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
    <input type="hidden" name="category_id" value="<?= $category_id ?>">
    <input type="datetime-local" name="game_date" required>
    <button type="submit">Save</button>
  </form>
  <button disabled>Start Game</button>
</td>


      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>


  </div>

  <div class="tab-content <?= $active_tab === 'standings' ? 'active' : '' ?>" id="standings">
    <h2>Standings</h2>
    <p>This will compute standings once games are played.</p>
  </div>
</div>

<script>
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));

    tab.classList.add('active');
    document.getElementById(tab.getAttribute('data-tab')).classList.add('active');
  });
});

function updateSeed(selectElem, teamId) {
  const newSeed = selectElem.value;
  fetch('update_seed.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'team_id=' + teamId + '&new_seed=' + newSeed
  })
  .then(res => res.text())
  .then(response => {
    if (response.trim() === 'success') {
      location.reload();
    } else {
      alert('Failed to update seed.');
    }
  });
}

function moveToCluster(selectElem, teamId) {
  const newCluster = selectElem.value;
  fetch('update_group.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'team_id=' + teamId + '&cluster_id=' + newCluster
  })
  .then(res => res.text())
  .then(response => {
    if (response.trim() === 'success') {
      location.reload();
    } else {
      alert('Failed to move team.');
    }
  });
}
</script>
<script>
function toggleDateForm(gameId) {
  const form = document.getElementById('date-form-' + gameId);
  form.style.display = (form.style.display === 'none') ? 'block' : 'none';
}
</script>


</body>
</html>
