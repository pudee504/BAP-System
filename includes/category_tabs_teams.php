<div class="tab-content <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams">
    <h3>Teams</h3>

    <?php 
    // The '$all_slots_filled' variable comes from 'includes/category_info.php'
    if (!$all_slots_filled): 
    ?>
        <!-- This form is only displayed if there are still open slots for teams. -->
        <form action="add_team.php" method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <input type="text" name="team_name" placeholder="Team Name" required>
            <button type="submit">Add Team</button>
        </form>
    <?php else: ?>
        <!-- Once all team slots are filled, this message guides the user to the next step. -->
        <p style="color:green;">
            <strong>All team slots are filled. You can now arrange the bracket in the 'Standings' tab.</strong>
        </p>
    <?php endif; ?>

    <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>

    <!-- A simple table to display the list of registered teams. -->
    <table class="category-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Team Name</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($teams)): ?>
                <tr>
                    <td colspan="3">No teams have been added yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($teams as $index => $team): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($team['team_name']) ?></td>
                        <td>
                            <!-- Basic management actions for each team. -->
                            <a href="edit_team.php?team_id=<?= $team['id'] ?>">Edit</a> |
                            <a href="delete_team.php?team_id=<?= $team['id'] ?>" onclick="return confirm('Are you sure you want to delete this team? This action cannot be undone.')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
