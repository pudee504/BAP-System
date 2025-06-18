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

if (!$category) {
    die("Category not found.");
}
// Ensure clusters exist for Round Robin format
if (strtolower($category['format_name']) === 'round robin') {
    $checkClusters = $pdo->prepare("SELECT COUNT(*) FROM cluster WHERE category_id = ?");
    $checkClusters->execute([$category_id]);
    $existing_clusters = (int) $checkClusters->fetchColumn();

    if ($existing_clusters === 0 && $category['num_groups'] > 0) {
        $insertCluster = $pdo->prepare("INSERT INTO cluster (category_id, cluster_name) VALUES (?, ?)");
        for ($i = 1; $i <= $category['num_groups']; $i++) {
            $insertCluster->execute([$category_id, $i]);
        }
    }
}


// Determine format
$is_round_robin = strtolower($category['format_name']) === 'round robin';

// Fetch clusters (groups)
$clusterStmt = $pdo->prepare("SELECT * FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
$clusterStmt->execute([$category_id]);
$clusters = $clusterStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all teams
$teamStmt = $pdo->prepare("SELECT * FROM team WHERE category_id = ? ORDER BY seed ASC");
$teamStmt->execute([$category_id]);
$teams = $teamStmt->fetchAll();
$team_count = count($teams);
$remaining_slots = $category['num_teams'] - $team_count;

// Organize teams by cluster
$teams_by_cluster = [];
foreach ($clusters as $cluster) {
    $teams_by_cluster[$cluster['id']] = [];
}
foreach ($teams as $team) {
    if ($team['cluster_id']) {
        $teams_by_cluster[$team['cluster_id']][] = $team;
    }
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
      <?php foreach ($clusters as $cluster): ?>
  <?php $team_count_in_group = count($teams_by_cluster[$cluster['id']] ?? []); ?>
  <h3>Group <?= chr(64 + $cluster['cluster_name']) ?> (<?= $team_count_in_group ?> team<?= $team_count_in_group !== 1 ? 's' : '' ?>)</h3>

        <table class="category-table">
          <thead>
            <tr>
              <th>Team Name</th>
              <th>Move to Group</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($teams_by_cluster[$cluster['id']])): ?>
              <?php foreach ($teams_by_cluster[$cluster['id']] as $team): ?>
                <tr>
                  <td><?= htmlspecialchars($team['team_name']) ?></td>
                  <td>
                    <select onchange="moveToCluster(this, <?= $team['id'] ?>)">
                      <option value="">Select Group</option>
                      <?php foreach ($clusters as $opt): ?>
                        <option value="<?= $opt['id'] ?>" <?= $opt['id'] == $team['cluster_id'] ? 'selected' : '' ?>>
                          Group <?= chr(64 + $opt['cluster_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <a href="edit_team.php?team_id=<?= $team['id'] ?>">Edit</a> |
                    <form action="delete_team.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                      <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                      <input type="hidden" name="category_id" value="<?= $category_id ?>">
                      <button type="submit" style="background: none; border: none; color: red;">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="3">No teams in this group yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!$is_round_robin): ?>
  <?php if ($teams): ?>
    <h3>All Teams</h3>
    <table class="category-table">
      <thead>
        <tr>
          <th>Seed</th>
          <th>Team Name</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teams as $team): ?>
          <tr>
            <td>
              <select onchange="updateSeed(this, <?= $team['id'] ?>)">
                <option value="">--</option>
                <?php for ($i = 1; $i <= count($teams); $i++): ?>
                  <option value="<?= $i ?>" <?= ($team['seed'] == $i ? 'selected' : '') ?>><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </td>
            <td>
              <a href="team_details.php?team_id=<?= $team['id'] ?>">
                <?= htmlspecialchars($team['team_name']) ?>
              </a>
            </td>
            <td>
              <a href="edit_team.php?team_id=<?= $team['id'] ?>">Edit</a> |
              <form action="delete_team.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete this team?');">
                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" style="background: none; border: none; color: red;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>No teams registered yet.</p>
  <?php endif; ?>
<?php endif; ?>

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
