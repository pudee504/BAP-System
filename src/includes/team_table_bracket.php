<?php
// This PHP block prepares the data needed for the dropdowns
$usedSeeds = [];
if (!empty($teams)) {
    foreach ($teams as $team) {
        if ($team['seed']) {
            $usedSeeds[] = $team['seed'];
        }
    }
}
?>

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
            <select 
              onchange="updateSeed(this, <?= $team['id'] ?>)" 
              <?= $category['is_locked'] ? 'disabled title="Seedings are locked"' : '' ?>>

              <option value="" <?= (is_null($team['seed']) || $team['seed'] == 0) ? 'selected' : '' ?>>--</option>
              
              <?php for ($i = 1; $i <= count($teams); $i++): ?>
                <?php
                // An option is disabled if it's in use by ANOTHER team
                $isUsedByAnotherTeam = in_array($i, $usedSeeds) && ($team['seed'] != $i);
                ?>
                <option 
                  value="<?= $i ?>" 
                  <?= ($team['seed'] == $i ? 'selected' : '') ?> 
                  <?= $isUsedByAnotherTeam ? 'disabled' : '' ?>>
                  <?= $i ?>
                </option>
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