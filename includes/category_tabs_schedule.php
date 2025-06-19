<div class="tab-content <?= $active_tab === 'schedule' ? 'active' : '' ?>" id="schedule">
    <h2>Schedule</h2>
    <?php if ($category['format_name'] === 'Single Elimination'): ?>
  <?php if (!$scheduleGenerated): ?>
    <form action="single_elimination.php" method="POST" onsubmit="return confirm('This will generate a full single elimination bracket. Proceed?')">
      <input type="hidden" name="category_id" value="<?= $category_id ?>">
      <button type="submit">Generate Single Elimination Schedule</button>
    </form>
  <?php else: ?>
    <p style="color: green;"><strong>Schedule already generated.</strong></p>
  <?php endif; ?>
<?php endif; ?>


<?php if ($category['format_name'] === 'Double Elimination'): ?>
  <?php if (!$scheduleGenerated): ?>
    <form action="double_elimination.php" method="POST" onsubmit="return confirm('This will generate a full double elimination bracket. Proceed?')">
      <input type="hidden" name="category_id" value="<?= $category_id ?>">
      <button type="submit">Generate Double Elimination Schedule</button>
    </form>
  <?php else: ?>
    <p style="color: green;"><strong>Schedule already generated.</strong></p>
  <?php endif; ?>
<?php endif; ?>





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
       <td class="match-cell">
  <div class="match-grid">
    <div class="team-name">
      <?= $game['hometeam_id'] ? '<a href="team_details.php?team_id=' . $game['hometeam_id'] . '">' . htmlspecialchars($game['home_name']) . '</a>' : 'TBD' ?>
    </div>
    <div class="team-result">
      <?php
        if ($game['game_status'] === 'final') {
          echo ($game['winner_team_id'] == $game['hometeam_id']) ? 'W' : 'L';
        } else {
          echo '-';
        }
      ?>
    </div>

    <div class="team-name">
      <?= $game['awayteam_id'] ? '<a href="team_details.php?team_id=' . $game['awayteam_id'] . '">' . htmlspecialchars($game['away_name']) . '</a>' : 'TBD' ?>
    </div>
    <div class="team-result">
      <?php
        if ($game['game_status'] === 'final') {
          echo ($game['winner_team_id'] == $game['awayteam_id']) ? 'W' : 'L';
        } else {
          echo '-';
        }
      ?>
    </div>
  </div>
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