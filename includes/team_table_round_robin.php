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
            <td>
  <a href="team_details.php?team_id=<?= $team['id'] ?>">
    <?= htmlspecialchars($team['team_name']) ?>
  </a>
</td>

            <td>
              <?php if (!$category['is_locked']): ?>
  <select onchange="moveToCluster(this, <?= $team['id'] ?>)">
<?php else: ?>
  <select disabled>
<?php endif; ?>
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
