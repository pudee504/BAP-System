<?php
// This is the complete and corrected file for both the Groupings Preview and the final Standings.
?>

<?php
// --- PRIMARY CHECK: Have all team slots been filled? ---
if (!$all_slots_filled):
?>
    <h2>Groupings Preview</h2>
    <p style="color: red; font-weight: bold;">
        Please add all <?= $category['num_teams'] ?> teams in the 'Teams' tab before arranging the groups.
    </p>

<?php
// If all slots ARE filled, then we show either the preview or the final standings.
else:
?>

    <?php 
    // --- SECONDARY CHECK: Is the schedule generated? ---
    // VIEW 1: PRE-SCHEDULE (Show Team Groupings with Challonge-style swap)
    if (!$scheduleGenerated): 
    ?>
    <h2>Groupings Preview</h2>
    
    <?php
    // ** THE FIX IS HERE **
    // We moved this block of code from the bottom to the top of the file,
    // so the $groups_are_locked variable exists before we need to use it.
    $lockStmt = $pdo->prepare("SELECT groups_locked FROM category WHERE id = ?");
    $lockStmt->execute([$category_id]);
    $groups_are_locked = $lockStmt->fetchColumn();
    ?>

    <?php if (!$groups_are_locked): ?>
        <p>Drag a team and drop it onto another team's number to swap their positions.</p>
    <?php else: ?>
        <p style="color: #28a745; font-weight: bold;">Groups are locked. You can now generate the schedule or unlock the groups to make changes.</p>
    <?php endif; ?>

    <?php
    // Display any feedback messages from the server after a swap
    if (isset($_SESSION['swap_message'])) {
        $message = $_SESSION['swap_message'];
        $is_error = strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'invalid') !== false;
        $color = $is_error ? '#a94442' : '#3c763d';
        $bgColor = $is_error ? '#f2dede' : '#dff0d8';
        echo "<div style='padding: 15px; margin-bottom: 20px; border: 1px solid {$color}; border-radius: 4px; color: {$color}; background-color: {$bgColor};'>{$message}</div>";
        unset($_SESSION['swap_message']);
    }

    // Fetch and organize teams
    $groupingStmt = $pdo->prepare("SELECT t.id as team_id, t.team_name, c.id as cluster_id, c.cluster_name FROM team t JOIN cluster c ON t.cluster_id = c.id WHERE t.category_id = ? ORDER BY c.cluster_name ASC, t.team_name ASC");
    $groupingStmt->execute([$category_id]);
    $team_groupings_raw = $groupingStmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped_teams = [];
    foreach ($team_groupings_raw as $team) {
        $grouped_teams[$team['cluster_id']]['cluster_name'] = $team['cluster_name'];
        $grouped_teams[$team['cluster_id']]['teams'][] = $team;
    }

    if (!function_exists('numberToLetter')) {
        function numberToLetter($num) { return chr(64 + $num); }
    }
    ?>

    <form action="swap_teams.php" method="POST" id="swap-form" style="display: none;">
        <input type="hidden" name="category_id" value="<?= $category_id ?>">
        <input type="hidden" name="team1_id" id="form-team1-id">
        <input type="hidden" name="team2_id" id="form-team2-id">
    </form>

    <div class="groupings-container">
        <?php foreach ($grouped_teams as $cluster_id => $group_data): ?>
            <div class="group-box">
                <h3>Group <?= htmlspecialchars(numberToLetter($group_data['cluster_name'])) ?></h3>
                <ol class="group-slots <?php if ($groups_are_locked) echo 'groups-locked'; ?>" style="padding-left: 20px;">
                    <?php foreach ($group_data['teams'] as $team): ?>
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

    <style>
        .team-slot { padding: 5px; border: 1px dashed #ccc; margin-bottom: 5px; }
        .team-name { cursor: grab; display: block; padding: 8px; background-color: #f9f9f9; border: 1px solid #ddd; }
        .team-slot.over { background-color: #e0f7ff; border-color: #007bff; }
        .team-name.dragging { opacity: 0.5; }
        .groups-locked .team-name {
            cursor: not-allowed;
            pointer-events: none;
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const draggableTeams = document.querySelectorAll('.team-name');
        const dropSlots = document.querySelectorAll('.team-slot');
        const swapForm = document.getElementById('swap-form');
        let draggedTeamId = null;

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
    });
    </script>

    <hr>
    
    <?php if (!$groups_are_locked): ?>
        <form action="toggle_group_lock.php" method="POST" onsubmit="return confirm('Are you sure you want to lock these groups?');">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <input type="hidden" name="lock_status" value="1">
            <button type="submit" class="button-primary">Lock Groups</button>
        </form>
    <?php else: ?>
        <p style="color:green; font-weight: bold;">Groups are locked.</p>
        <?php
        $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
        $gamesPlayedStmt->execute([$category_id]);
        $hasFinishedGames = $gamesPlayedStmt->fetchColumn() > 0;
        ?>
        <?php if (!$hasFinishedGames): ?>
            <form action="toggle_group_lock.php" method="POST" style="display: inline-block; margin-right: 10px;">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <input type="hidden" name="lock_status" value="0">
                <button type="submit" class="button-secondary">Unlock Groups</button>
            </form>
        <?php endif; ?>
        <form action="round_robin.php" method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to generate the schedule? This cannot be undone.');">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <button type="submit" class="button-primary">Generate Schedule</button>
        </form>
    <?php endif; ?>

    <?php 
    // --- VIEW 2: POST-SCHEDULE (Show Standings Table - NOW FIXED) ---
    else: 
    ?>
    <h2>Standings</h2>
    
    <?php
    // This function must be available here as well.
    if (!function_exists('numberToLetter')) {
        function numberToLetter($num) { return chr(64 + $num); }
    }

    // STEP 1: Fetch teams with their CLUSTER ID and CLUSTER NAME.
    $teamsStmt = $pdo->prepare("
        SELECT t.id, t.team_name, c.id as cluster_id, c.cluster_name 
        FROM team t 
        JOIN cluster c ON t.cluster_id = c.id 
        WHERE t.category_id = ? 
        ORDER BY c.cluster_name ASC, t.team_name ASC
    ");
    $teamsStmt->execute([$category_id]);
    $all_teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize standings array for every team found.
    $standings = [];
    foreach ($all_teams as $team) {
        $standings[$team['id']] = [
            'team_id' => $team['id'], 'team_name' => $team['team_name'], 
            'cluster_id' => $team['cluster_id'], 'cluster_name' => $team['cluster_name'], 
            'matches_played' => 0, 'wins' => 0, 'losses' => 0, 
            'point_scored' => 0, 'points_allowed' => 0, 'point_difference' => 0
        ];
    }

    // Fetch and process finished games to update stats.
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
            $standings[$home_id]['matches_played']++; $standings[$away_id]['matches_played']++;
            $standings[$home_id]['point_scored'] += $game['hometeam_score'];
            $standings[$home_id]['points_allowed'] += $game['awayteam_score'];
            $standings[$away_id]['point_scored'] += $game['awayteam_score'];
            $standings[$away_id]['points_allowed'] += $game['hometeam_score'];
            if ($game['winnerteam_id'] == $home_id) { $standings[$home_id]['wins']++; $standings[$away_id]['losses']++; } 
            else { $standings[$away_id]['wins']++; $standings[$home_id]['losses']++; }
        }
    }

    // STEP 2: Group the standings by the reliable CLUSTER ID.
    $grouped_standings = [];
    foreach ($standings as $team_stats) {
        $team_stats['point_difference'] = $team_stats['point_scored'] - $team_stats['points_allowed'];
        $grouped_standings[$team_stats['cluster_id']][] = $team_stats;
    }

    // Sort teams within each group based on wins, head-to-head, and point difference.
    foreach ($grouped_standings as &$group) {
        usort($group, function($a, $b) use ($head_to_head) {
            if ($b['wins'] !== $a['wins']) { return $b['wins'] <=> $a['wins']; }
            $team_a_id = $a['team_id']; $team_b_id = $b['team_id'];
            if (isset($head_to_head[$team_a_id][$team_b_id])) {
                $winner_id = $head_to_head[$team_a_id][$team_b_id];
                if ($winner_id == $team_a_id) return -1;
                if ($winner_id == $team_b_id) return 1;
            }
            return $b['point_difference'] <=> $a['point_difference'];
        });
    }
    unset($group);
    ?>

    <?php if (!empty($grouped_standings)): ?>
        <?php foreach ($grouped_standings as $group_id => $teams_in_group): ?>
            <?php
                // Get the group name from the first team in the group for the heading.
                $group_name = $teams_in_group[0]['cluster_name'];
            ?>
            <h3 class="group-header">Group <?= htmlspecialchars(numberToLetter($group_name)) ?></h3>
            <table class="standings-table">
                <thead><tr><th class="team-name-col">Team</th><th>MP</th><th>W</th><th>L</th><th>PS</th><th>PA</th><th>PD</th></tr></thead>
                <tbody>
                    <?php foreach ($teams_in_group as $index => $team):
                        $advancing_class = ($index < (int)$category['advance_per_group']) ? 'advancing-team' : ''; ?>
                        <tr class="<?= $advancing_class ?>">
                            <td class="team-name-col"><?= htmlspecialchars($team['team_name']) ?></td>
                            <td><?= $team['matches_played'] ?></td><td><?= $team['wins'] ?></td><td><?= $team['losses'] ?></td>
                            <td><?= $team['point_scored'] ?></td><td><?= $team['points_allowed'] ?></td>
                            <td><?= $team['point_difference'] > 0 ? '+' : '' ?><?= $team['point_difference'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No teams were found for this category to generate standings.</p>
    <?php endif; ?>

    <?php endif; /* End of schedule generated check */ ?>
<?php endif; /* End of all slots filled check */ ?>