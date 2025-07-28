<div class="tab-content <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams">
  <h3>Add New Team</h3>

  <?php if ($is_round_robin && $category['is_locked']): ?>
    <p style="color:red;"><strong>Groupings are locked. Cannot add more teams.</strong></p>
  <?php elseif ($team_count >= $category['num_teams'] && !$is_round_robin): ?>
    <p style="color:red;"><strong>Team limit reached. No more teams can be added.</strong></p>
  <?php else: ?>
    <form action="add_team.php" method="POST" style="margin-bottom: 20px;">
      <input type="hidden" name="category_id" value="<?= $category_id ?>">
      <input type="text" name="team_name" placeholder="Team Name" required>
      <button type="submit">Add Team</button>
    </form>
  <?php endif; ?>

  <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>

  <?php if ($is_round_robin): ?>
    <?php include 'includes/team_table_round_robin.php'; ?>
  <?php else: ?>
    <?php include 'includes/team_table_bracket.php'; ?>
  <?php endif; ?>

  <?php include 'includes/lock_controls.php'; ?>
</div>