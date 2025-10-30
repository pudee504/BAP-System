<?php
// FILENAME: includes/round_robins_standings.php
// DESCRIPTION: Displays the main content for a round-robin tournament.

// --- STATE 1: Not all teams have been added ---
if (!$all_slots_filled):
?>
    <div class="section-header">
        <h2>Groupings Preview</h2>
    </div>
    <p class="warning-message">
        Please add all <?= $category['num_teams'] ?> teams in the 'Teams' tab before arranging the groups.
    </p>

<?php
// --- STATE 2 & 3: All team slots are filled ---
else:
?>
    <?php 
    // Fetch the group lock status; this controls drag-and-drop and button visibility.
    $lockStmt = $pdo->prepare("SELECT groups_locked FROM category WHERE id = ?");
    $lockStmt->execute([$category_id]);
    $groups_are_locked = $lockStmt->fetchColumn();
    
    // Helper function to convert a 0-indexed number to a letter (0=A, 1=B, etc.).
    if (!function_exists('numberToLetter')) {
        function numberToLetter($num) { return chr(65 + (int)$num); }
    }

    // --- STATE 2: Schedule NOT Generated (Show Groupings Preview) ---
    if (!$scheduleGenerated): 
    ?>
    <div class="section-header">
        <h2>Groupings Preview</h2>
    </div>
    
    <?php // Display instructions based on whether groups are locked.
    if (!$groups_are_locked): ?>
        <p>Drag a team and drop it onto another team to swap their positions.</p>
    <?php else: ?>
        <p class="success-message"><strong>Groups are locked.</strong> You can now generate the schedule in the 'Schedule' tab or unlock groups to make changes.</p>
    <?php endif; ?>

    <?php
    // Display any success or error messages from a team swap action.
    if (isset($_SESSION['swap_message'])) {
        $message = $_SESSION['swap_message'];
        $is_error = strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'invalid') !== false;
        $message_class = $is_error ? 'form-error' : 'success-message';
        echo "<div class='{$message_class}'>{$message}</div>";
        unset($_SESSION['swap_message']);
    }

    // Fetch all teams and their assigned group (cluster) for display.
    $groupingStmt = $pdo->prepare("
        SELECT t.id as team_id, t.team_name, c.cluster_name 
        FROM team t 
        JOIN cluster c ON t.cluster_id = c.id 
        WHERE t.category_id = ? 
        ORDER BY c.cluster_name ASC, t.team_name ASC
    ");
    $groupingStmt->execute([$category_id]);
    $team_groupings_raw = $groupingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize the flat team list into a nested array, grouped by cluster_name.
    $grouped_teams = [];
    foreach ($team_groupings_raw as $team) {
        $grouped_teams[$team['cluster_name']][] = $team;
    }
    ksort($grouped_teams); // Sort groups by cluster_name (0, 1, 2...)
    ?>

    <form action="swap_teams.php" method="POST" id="swap-form" style="display: none;">
        <input type="hidden" name="category_id" value="<?= $category_id ?>">
        <input type="hidden" name="team1_id" id="form-team1-id">
        <input type="hidden" name="team2_id" id="form-team2-id">
    </form>

    <div class="groupings-container">
        <?php foreach ($grouped_teams as $group_name => $teams): ?>
            <div class="group-box">
                <h3>Group <?= htmlspecialchars(numberToLetter($group_name)) ?></h3>
                <ol class="group-slots <?= $groups_are_locked ? 'groups-locked' : '' ?>">
                    <?php foreach ($teams as $team): ?>
                        <li class="team-slot" data-team-id="<?= $team['team_id'] ?>">
                            <span class="team-name" draggable="true" data-team-id="<?= $team['team_id'] ?>">
                                <?= htmlspecialchars($team['team_name']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="form-actions" style="text-align: left; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
        <?php if (!$groups_are_locked): ?>
            <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('Are you sure you want to lock these groups?');">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <input type="hidden" name="lock_status" value="1">
                <button type="submit" class="btn btn-primary">Lock Groups</button>
            </form>
        <?php else: ?>
            <?php
            // Check if any games have been played before allowing an unlock.
            $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
            $gamesPlayedStmt->execute([$category_id]);
            $hasFinishedGames = $gamesPlayedStmt->fetchColumn() > 0;
            ?>
            <?php // Only show the "Unlock" button if no games have been completed.
            if (!$hasFinishedGames): ?>
                <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('Are you sure you want to unlock these groups?');">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    <input type="hidden" name="lock_status" value="0">
                    <button type="submit" class="btn btn-secondary">Unlock Groups</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>


    <?php 
    // --- STATE 3: Schedule IS Generated (Show Standings Table) ---
    else: 
    ?>
    <div class="section-header">
        <h2>Standings</h2>
    </div>
    
    <?php
    // Display general success/error messages (e.g., "Playoffs created").
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $is_error = strpos(strtolower($message), 'error') !== false;
        $message_class = $is_error ? 'form-error' : 'success-message';
        echo "<div class='{$message_class}' style='margin-bottom: 1rem;'>{$message}</div>";
        unset($_SESSION['message']);
    }

    // ========================================================================
    // START: Standings Calculation Logic
    // This logic fetches pre-calculated data, groups it, and sorts it.
    // ========================================================================

    // STEP 1: Fetch all pre-calculated standings data from the 'cluster_standing' table.
    $standingsStmt = $pdo->prepare("
        SELECT
            t.id AS team_id,
            t.team_name,
            c.cluster_name,
            cs.matches_played AS mp,
            cs.wins AS w,
            cs.losses AS l,
            cs.point_scored AS ps,
            cs.points_allowed AS pa,
            (cs.point_scored - cs.points_allowed) AS pd
        FROM
            cluster_standing AS cs
        JOIN
            team AS t ON cs.team_id = t.id
        JOIN
            cluster AS c ON cs.cluster_id = c.id
        WHERE
            c.category_id = ?
    ");
    $standingsStmt->execute([$category_id]);
    $all_standings_raw = $standingsStmt->fetchAll(PDO::FETCH_ASSOC);

    // STEP 2: Organize the standings data into a nested array, grouped by cluster_name.
    $grouped_standings = [];
    foreach ($all_standings_raw as $team_stats) {
        $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
    }
    ksort($grouped_standings); // Sort groups by cluster_name (0, 1, 2...)


    // STEP 3: Sort teams within each group based on standings rules.
    foreach ($grouped_standings as &$group) {
        usort($group, function($a, $b) {
            // 1. Sort by Wins (descending)
            if ($b['w'] !== $a['w']) {
                return $b['w'] <=> $a['w'];
            }
            // 2. Sort by Point Differential (descending)
            if ($b['pd'] !== $a['pd']) {
                return $b['pd'] <=> $a['pd'];
            }
            // 3. Sort by Points Scored (descending) as a final tie-breaker
            return $b['ps'] <=> $a['ps'];
        });
    }
    unset($group); // Unset the reference

    // ========================================================================
    // END: Standings Calculation Logic
    // ========================================================================
    ?>

    <?php if (!empty($grouped_standings)): ?>
        <?php // Loop through each group and render its standings table
        foreach ($grouped_standings as $group_name => $teams_in_group): ?>
            <h3 class="group-header">Group <?= htmlspecialchars(numberToLetter($group_name)) ?></h3>
            <div class="table-wrapper">
                <table class="category-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Team</th>
                            <th>MP</th><th>W</th><th>L</th><th>PS</th><th>PA</th><th>PD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams_in_group as $index => $team):
                            // Check if this team's rank is within the advancing-team limit.
                            $advancing_class = ($index < (int)$category['advance_per_group']) ? 'advancing-team' : ''; ?>
                            <tr class="<?= $advancing_class ?>">
                                <td><?= htmlspecialchars($team['team_name']) ?></td>
                                <td><?= $team['mp'] ?></td><td><?= $team['w'] ?></td><td><?= $team['l'] ?></td>
                                <td><?= $team['ps'] ?></td><td><?= $team['pa'] ?></td>
                                <td><?= $team['pd'] > 0 ? '+' : '' ?><?= $team['pd'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="info-message">No standings data found. Ensure games have been played and finalized to populate the standings.</p>
    <?php endif; ?>

    <?php
    // --- ACTION BUTTONS SECTION (e.g., Proceed to Playoffs) ---
    
    // Check if all scheduled group stage games have been marked as finished.
    $totalGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND stage = 'Group Stage'");
    $totalGamesStmt->execute([$category_id]);
    $total_games = $totalGamesStmt->fetchColumn();

    $finishedGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL AND stage = 'Group Stage'");
    $finishedGamesStmt->execute([$category_id]);
    $finished_games_count = $finishedGamesStmt->fetchColumn();
    
    $allGamesAreFinished = ($total_games > 0 && $total_games === $finished_games_count);

    // Check if a playoff category has already been generated from this one.
    $playoffCheckStmt = $pdo->prepare("SELECT playoff_category_id FROM category WHERE id = ?");
    $playoffCheckStmt->execute([$category_id]);
    $playoff_category_id = $playoffCheckStmt->fetchColumn();
    ?>

    <div class="form-actions" style="text-align: left; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
        
        <?php // CASE 1: All games are finished and playoffs have NOT been created.
        if ($allGamesAreFinished && empty($playoff_category_id)): ?>
            <form action="create_playoffs.php" method="POST" onsubmit="return confirm('This will create a new Single Elimination category with the advancing teams. Are you sure you want to proceed?');">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" class="btn btn-primary">Proceed to Playoffs</button>
            </form>

        <?php // CASE 2: All games are finished and playoffs HAVE already been created.
        elseif ($allGamesAreFinished && !empty($playoff_category_id)): ?>
            <button type="button" class="btn btn-primary" disabled>Playoffs Already Created</button>
            <p style="margin-top: 0.5rem;">
                <a href="category_details.php?category_id=<?= htmlspecialchars($playoff_category_id) ?>&tab=schedule" style="font-weight: bold;">
                    View Playoffs &rarr;
                </a>
            </p>

        <?php // CASE 3: Games are still in progress.
        elseif ($groups_are_locked && $finished_games_count > 0): ?>
            <p class="info-message">Finish all group stage games to proceed to the playoffs.</p>

        <?php // CASE 4: No games played. Allow admin to unlock and clear the (empty) schedule.
        elseif ($groups_are_locked && $finished_games_count === 0): ?>
            <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('WARNING: Unlocking the groups will delete the entire generated schedule and any match data. Are you sure you want to proceed?');">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <input type="hidden" name="lock_status" value="0">
                <button type="submit" class="btn btn-danger">Unlock Groups & Clear Schedule</button>
            </form>
        <?php endif; ?>

    </div>

    <?php endif; /* End of schedule generated check */ ?>
<?php endif; /* End of all slots filled check */ ?>


<style>
    /* Styles for Groupings Preview */
    .groupings-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-top: 1.5rem;
    }
    .group-box {
        background-color: var(--bg-light);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 1.5rem;
    }
    .group-box h3 {
        margin-top: 0;
        margin-bottom: 1rem;
        font-size: 1.25rem;
        color: var(--bap-blue);
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 0.75rem;
    }
    .group-slots {
        list-style-type: decimal;
        padding-left: 1.5rem;
        margin: 0;
    }
    .team-slot {
        border: 1px dashed transparent;
        margin-bottom: 0.5rem;
        border-radius: 4px;
        transition: border-color 0.2s ease, background-color 0.2s ease;
    }
    .team-name {
        cursor: grab;
        display: block;
        padding: 0.6rem 0.8rem;
        background-color: #f8f9fa;
        border: 1px solid var(--border-color);
        border-radius: 4px;
        transition: background-color 0.2s ease, box-shadow 0.2s ease;
    }
    .team-name:hover {
        background-color: #e9ecef;
    }
    /* Style for the drop target */
    .team-slot.over {
        border-color: var(--bap-orange);
        background-color: #fff3cd;
    }
    /* Style for the element being dragged */
    .team-name.dragging {
        opacity: 0.5;
        box-shadow: var(--shadow);
    }
    /* Disable drag-and-drop styles if groups are locked */
    .groups-locked .team-name {
        cursor: not-allowed;
    }
    .groups-locked .team-name:hover {
        background-color: #f8f9fa;
    }

    /* Styles for Standings Table */
    .group-header {
        font-size: 1.5rem;
        color: var(--text-dark);
        margin-top: 2.5rem;
        margin-bottom: 1rem;
    }
    .group-header:first-of-type {
        margin-top: 0;
    }
    /* Highlight teams that are advancing */
    .advancing-team td {
        background-color: #d4edda;
        font-weight: 600;
        color: #155724;
    }
    .advancing-team td:first-child::before {
        margin-right: 8px;
        color: #155724;
    }
</style>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const draggableTeams = document.querySelectorAll('.team-name');
    const dropSlots = document.querySelectorAll('.team-slot');
    const swapForm = document.getElementById('swap-form');
    let draggedTeamId = null;

    // Only add drag-and-drop listeners if the groups are not locked.
    if (document.querySelector('.group-slots:not(.groups-locked)')) {
        draggableTeams.forEach(team => {
            // When a drag starts, store the team's ID and add a visual 'dragging' class.
            team.addEventListener('dragstart', (e) => {
                draggedTeamId = e.target.getAttribute('data-team-id');
                e.target.classList.add('dragging');
            });
            team.addEventListener('dragend', (e) => {
                e.target.classList.remove('dragging');
            });
        });

        dropSlots.forEach(slot => {
            // Add/remove 'over' class for visual feedback when dragging over a slot.
            slot.addEventListener('dragover', (e) => { e.preventDefault(); slot.classList.add('over'); });
            slot.addEventListener('dragleave', () => { slot.classList.remove('over'); });
            
            // When a team is dropped onto another team's slot...
            slot.addEventListener('drop', (e) => {
                e.preventDefault();
                slot.classList.remove('over');
                const targetTeamId = slot.getAttribute('data-team-id');
                
                // Check if the drop is valid (not on itself).
                if (draggedTeamId && targetTeamId && draggedTeamId !== targetTeamId) {
                    // Populate the hidden form with the two team IDs and submit it.
                    document.getElementById('form-team1-id').value = draggedTeamId;
                    document.getElementById('form-team2-id').value = targetTeamId;
                    swapForm.submit();
                }
            });
        });
    }
});
</script>