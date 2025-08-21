<?php
session_start();
require 'includes/category_info.php';

// Validate category ID
$category_id = $_GET['category_id'] ?? '';
if (!$category_id) {
    die("Invalid category ID.");
}

// Determine active tab (default to 'teams')
$active_tab = $_GET['tab'] ?? 'teams';
$valid_tabs = ['teams', 'schedule', 'standings'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'teams';
}

// Check if schedule is generated
$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
$scheduleInfo = $check->fetch(PDO::FETCH_ASSOC);
$scheduleGenerated = $scheduleInfo['schedule_generated'] ?? false;

// This query should ideally be combined with the one in 'category_info.php' for efficiency.
$lockStmt = $pdo->prepare("SELECT is_locked FROM category_format WHERE category_id = ?");
$lockStmt->execute([$category_id]);
$lockInfo = $lockStmt->fetch(PDO::FETCH_ASSOC);
$seedingsLocked = $lockInfo['is_locked'] ?? false;

$hasFinalGames = false;
if ($scheduleGenerated) {
    // This query now checks for a 'Final' status OR a set winner ID
    $finalCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM game 
        WHERE category_id = ? AND (game_status = 'Final' OR winnerteam_id IS NOT NULL)
    ");
    $finalCheck->execute([$category_id]);
    if ($finalCheck->fetchColumn() > 0) {
        $hasFinalGames = true;
    }
}
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
  <div class="tab-container">
    <?php include 'includes/category_tabs_teams.php'; ?>
    <?php include 'includes/category_tabs_schedule.php'; ?>
    <?php include 'includes/category_tabs_standings.php'; ?>
  </div>
</div>

<script>
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
    tab.classList.add('active');
    const tabId = tab.getAttribute('data-tab');
    document.getElementById(tabId).classList.add('active');
    // Update URL without reloading
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);
  });
});

function updateSeed(selectElem, teamId) {
  const newSeed = selectElem.value;
  fetch('update_seed.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    // Pass category_id for the duplicate check
    body: `team_id=${teamId}&new_seed=${newSeed}&category_id=<?= $category_id ?>`
  })
  .then(res => {
      if (!res.ok) {
          // If server returns an error (like a duplicate), get the error text
          return res.text().then(text => { throw new Error(text) });
      }
      return res.text();
  })
  .then(response => {
    if (response.trim() === 'success') {
      location.reload(); // This is CORRECT for re-sorting the table
    }
  })
  .catch(error => {
      // Display the specific error message from the server
      alert('Failed to update seed: ' + error.message);
      location.reload(); // Reload even on error to revert the dropdown
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

function toggleDateForm(gameId) {
  const form = document.getElementById('date-form-' + gameId);
  if (form) {
    form.style.display = (form.style.display === 'none') ? 'block' : 'none';
  }
}
</script>
</body>
</html>