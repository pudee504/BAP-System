<?php
session_start();
$active_tab = $_GET['tab'] ?? 'teams';

require 'includes/category_info.php';

// NEW: check if schedule is already generated
$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
$scheduleInfo = $check->fetch();
$scheduleGenerated = $scheduleInfo['schedule_generated'];

$active_tab = $_GET['tab'] ?? 'teams';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Category Details</title>
  <?php include 'includes/head_styles.php'; ?>
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

  <?php include 'includes/category_tabs.php'; ?>
  <?php include 'includes/category_tabs_teams.php'; ?>
  <?php include 'includes/category_tabs_schedule.php'; ?>
  <?php include 'includes/category_tabs_standings.php'; ?>

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
