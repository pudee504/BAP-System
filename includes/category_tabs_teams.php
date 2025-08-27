<div class="tab-content <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams">
    <h3>Teams</h3>

    <?php if (!$all_slots_filled): ?>
        <form action="add_team.php" method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <input type="text" name="team_name" placeholder="Team Name" required>
            <button type="submit">Add Team</button>
        </form>
    <?php else: ?>
        <p style="color:green;">
            <strong>All team slots are filled. You can now arrange the groups in the 'Standings' tab.</strong>
        </p>
    <?php endif; ?>

    <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>

    <table class="category-table">
        <thead>
            <tr>
                <th>Position</th>
                <th>Team Name</th>
                
                <?php if (strtolower($category['format_name']) === 'round robin'): ?>
                    <th>Group</th>
                <?php endif; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($teams)): ?>
                <tr>
                    <td colspan="4">No teams have been added yet.</td>
                </tr>
            <?php else: ?>
                <?php
                // Function to convert cluster number to a letter (e.g., 1 -> A)
                if (!function_exists('numberToLetter')) {
                    function numberToLetter($num) { return chr(64 + $num); }
                }
                ?>
                <?php foreach ($teams as $index => $team): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($team['team_name']) ?></td>

                        <?php if (strtolower($category['format_name']) === 'round robin'): ?>
                            <td>
                                <?php 
                                // Fetch and display the group letter for the team
                                if (!empty($team['cluster_id'])) {
                                    $clusterStmt = $pdo->prepare("SELECT cluster_name FROM cluster WHERE id = ?");
                                    $clusterStmt->execute([$team['cluster_id']]);
                                    $cluster_name = $clusterStmt->fetchColumn();
                                    // Check if a name was found before converting to a letter
                                    echo $cluster_name ? numberToLetter($cluster_name) : 'N/A';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <a href="edit_team.php?team_id=<?= $team['id'] ?>">Edit</a> |
                            <a href="delete_team.php?team_id=<?= $team['id'] ?>" onclick="return confirm('Are you sure?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>