<?php
// ============================================
// File: includes/category_tabs_teams.php
// Purpose: Displays the "Teams" tab for a category, 
// allowing team creation, viewing, editing, and deletion.
// ============================================
?>
<div class="tab-content <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams">
    <div class="section-header">
        <h2>Teams</h2>
    </div>

    <?php if (!$all_slots_filled): ?>
        <!-- Form for adding a new team -->
        <form action="add_team.php" method="POST" class="add-team-form">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <input type="text" name="team_name" placeholder="Enter New Team Name" required>
            <button type="submit" class="btn btn-primary">Add Team</button>
        </form>
    <?php else: ?>
        <!-- Message shown when all team slots are filled -->
        <p class="success-message">
            <strong>All team slots are filled.</strong> You can now arrange groups or seeding in the 'Standings' tab.
        </p>
    <?php endif; ?>

    <p><strong>Teams Registered:</strong> <?= $team_count ?> / <?= $category['num_teams'] ?></p>

    <div class="table-wrapper">
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
                    <!-- No teams found -->
                    <tr class="no-results">
                        <td colspan="4">No teams have been added yet.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Converts numeric value (1, 2, 3...) to letter (A, B, C...)
                    if (!function_exists('numberToLetter')) {
                        function numberToLetter($num) { return chr(64 + (int)$num); }
                    }
                    ?>
                    <?php foreach ($teams as $index => $team): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><a href="team_details.php?team_id=<?= $team['id'] ?>"><?= htmlspecialchars($team['team_name']) ?></a></td>

                            <?php if (strtolower($category['format_name']) === 'round robin'): ?>
                                <td>
                                    <?php  
                                    if (!empty($team['cluster_id'])) {
                                        $clusterStmt = $pdo->prepare("SELECT cluster_name FROM cluster WHERE id = ?");
                                        $clusterStmt->execute([$team['cluster_id']]);
                                        $cluster_name = $clusterStmt->fetchColumn();
                                        echo $cluster_name ? htmlspecialchars(numberToLetter($cluster_name)) : 'N/A';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>

                            <td class="actions">
                                <a href="edit_team.php?team_id=<?= $team['id'] ?>">Edit</a>
                                
                                <?php if (!$isLocked): ?>
                                    <!-- Delete team form -->
                                    <form action="delete_team.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this team? This action cannot be undone.');">
                                        <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                        <input type="hidden" name="category_id" value="<?= $category_id ?>">
                                        <button type="submit" class="action-delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
