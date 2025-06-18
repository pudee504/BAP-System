<?php
session_start();
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
  
  <?php if ($team_count >= $category['num_teams'] && !$is_round_robin): ?>
  <p style="color:red;"><strong>Team limit reached. No more teams can be added.</strong></p>
<?php endif; ?>


  <?php if ($category['num_groups']): ?>
    <p><strong>Groups:</strong> <?= $category['num_groups'] ?> (<?= $category['advance_per_group'] ?> advance per group)</p>
  <?php endif; ?>

  <div class="tabs">
    <div class="tab active" data-tab="teams">Teams</div>
    <div class="tab" data-tab="schedule">Schedule</div>
    <div class="tab" data-tab="standings">Standings</div>
  </div>

  <div class="tab-content active" id="teams">
    <h3>Add New Team</h3>
  <form action="add_team.php" method="POST" style="margin-bottom: 20px;">
    <input type="hidden" name="category_id" value="<?= $category_id ?>">
    <input type="text" name="team_name" placeholder="Team Name" required>
    <button type="submit">Add Team</button>
  </form>
  <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>
    <?php if ($is_round_robin): ?>
  <?php include 'includes/team_table_round_robin.php'; ?>
<?php else: ?>
  <?php include 'includes/team_table_bracket.php'; ?>
<?php endif; ?>

<?php include 'includes/lock_controls.php'; ?>

  </div>

  <div class="tab-content" id="schedule">
    <h2>Schedule</h2>
    <p>This will handle match creation and schedule viewing.</p>
  </div>

  <div class="tab-content" id="standings">
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
</body>
</html>
