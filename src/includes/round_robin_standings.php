<?php
// This is the complete and corrected file for both the Groupings Preview and the final Standings.

// --- PRIMARY CHECK: Have all team slots been filled? ---
if (!$all_slots_filled):
?>
    <div class="section-header">
        <h2>Groupings Preview</h2>
    </div>
    <p class="warning-message">
        Please add all <?= $category['num_teams'] ?> teams in the 'Teams' tab before arranging the groups.
    </p>

<?php
// If all slots ARE filled, then we show either the preview or the final standings.
else:
?>
    <?php 
    // Fetch the lock status early so we can use it throughout the file.
    $lockStmt = $pdo->prepare("SELECT groups_locked FROM category WHERE id = ?");
    $lockStmt->execute([$category_id]);
    $groups_are_locked = $lockStmt->fetchColumn();
    
    // FIX #1: Modified numberToLetter to handle 0-indexed group numbers (0=A, 1=B).
    if (!function_exists('numberToLetter')) {
        function numberToLetter($num) { return chr(65 + (int)$num); }
    }

    // --- VIEW 1: PRE-SCHEDULE (Show Team Groupings with Drag & Drop) ---
    if (!$scheduleGenerated): 
    ?>
    <div class="section-header">
        <h2>Groupings Preview</h2>
    </div>
    
    <?php if (!$groups_are_locked): ?>
        <p>Drag a team and drop it onto another team to swap their positions.</p>
    <?php else: ?>
        <p class="success-message"><strong>Groups are locked.</strong> You can now generate the schedule in the 'Schedule' tab or unlock groups to make changes.</p>
    <?php endif; ?>

    <?php
    // Display any feedback messages from the server after a swap
    if (isset($_SESSION['swap_message'])) {
        $message = $_SESSION['swap_message'];
        $is_error = strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'invalid') !== false;
        $message_class = $is_error ? 'form-error' : 'success-message';
        echo "<div class='{$message_class}'>{$message}</div>";
        unset($_SESSION['swap_message']);
    }

    // Fetch and organize teams for the preview
    $groupingStmt = $pdo->prepare("SELECT t.id as team_id, t.team_name, c.cluster_name FROM team t JOIN cluster c ON t.cluster_id = c.id WHERE t.category_id = ? ORDER BY c.cluster_name ASC, t.team_name ASC");
    $groupingStmt->execute([$category_id]);
    $team_groupings_raw = $groupingStmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped_teams = [];
    foreach ($team_groupings_raw as $team) {
        $grouped_teams[$team['cluster_name']][] = $team;
    }
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
            $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
            $gamesPlayedStmt->execute([$category_id]);
            $hasFinishedGames = $gamesPlayedStmt->fetchColumn() > 0;
            ?>
            <?php if (!$hasFinishedGames): ?>
                <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('Are you sure you want to unlock these groups?');">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    <input type="hidden" name="lock_status" value="0">
                    <button type="submit" class="btn btn-secondary">Unlock Groups</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>


    <?php 
    // --- VIEW 2: POST-SCHEDULE (Show Standings Table) ---
    else: 
    ?>
    <div class="section-header">
        <h2>Standings</h2>
    </div>
    
    <?php
    // --- Display general session messages from other scripts (like create_playoffs.php) ---
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $is_error = strpos(strtolower($message), 'error') !== false;
        $message_class = $is_error ? 'form-error' : 'success-message';
        echo "<div class='{$message_class}' style='margin-bottom: 1rem;'>{$message}</div>";
        unset($_SESSION['message']);
    }

    // STEP 1: Fetch teams with their CLUSTER NAME.
    $teamsStmt = $pdo->prepare("
        SELECT t.id, t.team_name, c.cluster_name 
        FROM team t 
        JOIN cluster c ON t.cluster_id = c.id 
        WHERE t.category_id = ? 
        ORDER BY c.cluster_name ASC, t.team_name ASC
    ");
    $teamsStmt->execute([$category_id]);
    $all_teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize standings array
    $standings = [];
    foreach ($all_teams as $team) {
        $standings[$team['id']] = [
            'team_id' => $team['id'], 'team_name' => $team['team_name'], 
            'cluster_name' => $team['cluster_name'], 
            'mp' => 0, 'w' => 0, 'l' => 0, 
            'ps' => 0, 'pa' => 0, 'pd' => 0
        ];
    }

    // Fetch and process finished games
    $gamesStmt = $pdo->prepare("
        SELECT hometeam_id, awayteam_id, winnerteam_id, hometeam_score, awayteam_score 
        FROM game 
        WHERE category_id = ? AND winnerteam_id IS NOT NULL
    ");
    $gamesStmt->execute([$category_id]);
    $finished_games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

    $head_to_head = [];
    foreach ($finished_games as $game) {
        $home_id = $game['hometeam_id']; $away_id = $game['awayteam_id'];
        $head_to_head[$home_id][$away_id] = $game['winnerteam_id'];
        $head_to_head[$away_id][$home_id] = $game['winnerteam_id'];

        if (isset($standings[$home_id]) && isset($standings[$away_id])) {
            $standings[$home_id]['mp']++; $standings[$away_id]['mp']++;
            $standings[$home_id]['ps'] += $game['hometeam_score'];
            $standings[$home_id]['pa'] += $game['awayteam_score'];
            $standings[$away_id]['ps'] += $game['awayteam_score'];
            $standings[$away_id]['pa'] += $game['hometeam_score'];
            if ($game['winnerteam_id'] == $home_id) { $standings[$home_id]['w']++; $standings[$away_id]['l']++; } 
            else { $standings[$away_id]['w']++; $standings[$home_id]['l']++; }
        }
    }

    // STEP 2: Group standings by CLUSTER NAME and calculate point difference
    $grouped_standings = [];
    foreach ($standings as $team_stats) {
        $team_stats['pd'] = $team_stats['ps'] - $team_stats['pa'];
        $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
    }

    // Sort teams within each group
    foreach ($grouped_standings as &$group) {
        usort($group, function($a, $b) use ($head_to_head) {
            if ($b['w'] !== $a['w']) { return $b['w'] <=> $a['w']; }
            $team_a_id = $a['team_id']; $team_b_id = $b['team_id'];
            if (isset($head_to_head[$team_a_id][$team_b_id])) {
                $winner_id = $head_to_head[$team_a_id][$team_b_id];
                if ($winner_id == $team_a_id) return -1;
                if ($winner_id == $team_b_id) return 1;
            }
            return $b['pd'] <=> $a['pd'];
        });
    }
    unset($group);
    ?>

    <?php if (!empty($grouped_standings)): ?>
        <?php foreach ($grouped_standings as $group_name => $teams_in_group): ?>
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
        <p class="info-message">No teams were found for this category to generate standings.</p>
    <?php endif; ?>

    <?php
    // --- ACTION BUTTONS SECTION ---
    $totalGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ?");
    $totalGamesStmt->execute([$category_id]);
    $total_games = $totalGamesStmt->fetchColumn();

    $finishedGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $finishedGamesStmt->execute([$category_id]);
    $finished_games_count = $finishedGamesStmt->fetchColumn();
    $hasFinishedGames = $finished_games_count > 0;
    $allGamesAreFinished = ($total_games > 0 && $total_games === $finished_games_count);
    ?>

    <div class="form-actions" style="text-align: left; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
        <?php if ($allGamesAreFinished): ?>
            <form action="create_playoffs.php" method="POST" onsubmit="return confirm('This will create a new Single Elimination category with the advancing teams. Are you sure you want to proceed?');">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" class="btn btn-primary">Proceed to Playoffs</button>
            </form>
        <?php elseif ($groups_are_locked && !$hasFinishedGames): ?>
            <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('WARNING: Unlocking the groups will delete the entire generated schedule and any match data. Are you sure you want to proceed?');">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <input type="hidden" name="lock_status" value="0">
                <button type="submit" class="btn btn-danger">Unlock Groups & Clear Schedule</button>
            </form>
        <?php endif; ?>
    </div>

    <?php endif; /* End of schedule generated check */ ?>
<?php endif; /* End of all slots filled check */ ?>


<link rel="stylesheet" type="text/css" href="/css/round_robin_standings.css">


<script>
document.addEventListener('DOMContentLoaded', function () {
    const draggableTeams = document.querySelectorAll('.team-name');
    const dropSlots = document.querySelectorAll('.team-slot');
    const swapForm = document.getElementById('swap-form');
    let draggedTeamId = null;

    if (document.querySelector('.group-slots:not(.groups-locked)')) {
        draggableTeams.forEach(team => {
            team.addEventListener('dragstart', (e) => {
                draggedTeamId = e.target.getAttribute('data-team-id');
                e.target.classList.add('dragging');
            });
            team.addEventListener('dragend', (e) => {
                e.target.classList.remove('dragging');
            });
        });

        dropSlots.forEach(slot => {
            slot.addEventListener('dragover', (e) => { e.preventDefault(); slot.classList.add('over'); });
            slot.addEventListener('dragleave', () => { slot.classList.remove('over'); });
            slot.addEventListener('drop', (e) => {
                e.preventDefault();
                slot.classList.remove('over');
                const targetTeamId = slot.getAttribute('data-team-id');
                if (draggedTeamId && targetTeamId && draggedTeamId !== targetTeamId) {
                    document.getElementById('form-team1-id').value = draggedTeamId;
                    document.getElementById('form-team2-id').value = targetTeamId;
                    swapForm.submit();
                }
            });
        });
    }
});
</script>

