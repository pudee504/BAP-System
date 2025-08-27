<div class="tab-content <?= $active_tab === 'standings' ? 'active' : '' ?>" id="standings">
    <h2>Bracket Setup</h2>

    <?php 
    // This check determines which view to render based on the tournament format.
    // '$is_bracket_format' and '$all_slots_filled' come from 'includes/category_info.php'
    if ($is_bracket_format): 
        // For Single/Double Elimination, we show the bracket setup interface.
        if (!$all_slots_filled): 
    ?>
            <p>Please add all <?= $category['num_teams'] ?> teams in the 'Teams' tab before setting up the bracket.</p>
        <?php else: ?>
            <?php 
            // === MODIFICATION START ===
            // Conditionally load the correct visualizer based on the tournament format.
            if ($category['tournament_format'] === 'Double Elimination') {
                require 'includes/double_elim_visualizer.php'; 
            } else {
                // Default to single elimination
                require 'includes/bracket_visualizer.php'; 
            }
            // === MODIFICATION END ===
            ?>
        <?php endif; ?>
    <?php else: // This block handles non-bracket formats, specifically Round Robin. ?>
        <h2>Standings</h2>
        <?php if (!$scheduleGenerated): ?>
            <p>The schedule has not been generated yet. Please generate the schedule in the "Schedule" tab to see the standings.</p>
        <?php else: ?>
            <!-- 
                This is the full, detailed logic for calculating and displaying Round Robin standings.
                It remains unchanged from your original implementation.
            -->
            <link rel="stylesheet" href="includes/standings_renderer.css">
            <?php
            if (!function_exists('numberToLetter')) {
                function numberToLetter($num) { return chr(64 + $num); }
            }
            $teamsStmt = $pdo->prepare("SELECT t.id, t.team_name, c.cluster_name FROM team t JOIN cluster c ON t.cluster_id = c.id WHERE t.category_id = ? ORDER BY c.cluster_name ASC, t.team_name ASC");
            $teamsStmt->execute([$category_id]);
            $all_teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
            $standings = [];
            foreach ($all_teams as $team) {
                $standings[$team['id']] = ['team_id' => $team['id'], 'team_name' => $team['team_name'], 'cluster_name' => $team['cluster_name'], 'matches_played' => 0, 'wins' => 0, 'losses' => 0, 'point_scored' => 0, 'points_allowed' => 0, 'point_difference' => 0];
            }
            $gamesStmt = $pdo->prepare("SELECT hometeam_id, awayteam_id, winnerteam_id, hometeam_score, awayteam_score FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
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
            $grouped_standings = [];
            foreach ($standings as $team_stats) {
                $team_stats['point_difference'] = $team_stats['point_scored'] - $team_stats['points_allowed'];
                $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
            }
            foreach ($grouped_standings as &$group) {
                usort($group, function($a, $b) use ($head_to_head) {
                    if ($b['wins'] != $a['wins']) { return $b['wins'] <=> $a['wins']; }
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
                <?php foreach ($grouped_standings as $group_name => $teams_in_group): ?>
                    <h3 class="group-header">Group <?= htmlspecialchars(numberToLetter($group_name)) ?></h3>
                    <table class="standings-table">
                        <thead><tr><th class="team-name-col">Team</th><th>MP</th><th>W</th><th>L</th><th>PS</th><th>PA</th><th>PD</th></tr></thead>
                        <tbody>
                            <?php foreach ($teams_in_group as $index => $team):
                                $advancing_class = ($index < (int)$category['advance_per_group']) ? 'advancing-team' : ''; ?>
                                <tr class="<?= $advancing_class ?>">
                                    <td class="team-name-col"><a href="team_details.php?team_id=<?= $team['id'] ?>"><?= htmlspecialchars($team['team_name']) ?></a></td>
                                    <td><?= $team['matches_played'] ?></td><td><?= $team['wins'] ?></td><td><?= $team['losses'] ?></td>
                                    <td><?= $team['point_scored'] ?></td><td><?= $team['points_allowed'] ?></td>
                                    <td><?= $team['point_difference'] > 0 ? '+' : '' ?><?= $team['point_difference'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
