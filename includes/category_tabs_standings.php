<?php
// The main page (category_details.php) has already fetched the format info.
$format = $category['format_name'] ?? null;
$advance_per_group = $category['advance_per_group'] ?? 0;

if (!function_exists('numberToLetter')) {
    function numberToLetter($num) {
        return chr(64 + $num);
    }
}
?>

<div class="tab-content <?= $active_tab === 'standings' ? 'active' : '' ?>" id="standings">
    <?php if (!$scheduleGenerated): ?>
        <p>The schedule has not been generated yet. Please generate the schedule in the "Schedule" tab to see the standings.</p>

    <?php elseif ($format == 'Round Robin'): ?>
        <link rel="stylesheet" href="includes/standings_renderer.css">
        <?php
        // --- LIVE STANDINGS CALCULATION WITH HEAD-TO-HEAD TIE-BREAKER ---

        // 1. Get all teams in their assigned groups.
        $teamsStmt = $pdo->prepare("
            SELECT t.id, t.team_name, c.cluster_name
            FROM team t
            JOIN cluster c ON t.cluster_id = c.id
            WHERE t.category_id = ?
            ORDER BY c.cluster_name ASC, t.team_name ASC
        ");
        $teamsStmt->execute([$category_id]);
        $all_teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Initialize a standings array for every team with zero values.
        $standings = [];
        foreach ($all_teams as $team) {
            $standings[$team['id']] = [
                'team_id' => $team['id'], 'team_name' => $team['team_name'],
                'cluster_name' => $team['cluster_name'], 'matches_played' => 0,
                'wins' => 0, 'losses' => 0, 'point_scored' => 0, 'points_allowed' => 0,
                'point_difference' => 0
            ];
        }

        // 3. Get all games WITH A WINNER and calculate the stats.
        $gamesStmt = $pdo->prepare(
            "SELECT hometeam_id, awayteam_id, winnerteam_id, hometeam_score, awayteam_score
             FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL"
        );
        $gamesStmt->execute([$category_id]);
        $finished_games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Store head-to-head results for tie-breaking
        $head_to_head = [];
        foreach ($finished_games as $game) {
            $home_id = $game['hometeam_id']; $away_id = $game['awayteam_id'];
            
            // Store the winner of this specific matchup
            $head_to_head[$home_id][$away_id] = $game['winnerteam_id'];
            $head_to_head[$away_id][$home_id] = $game['winnerteam_id'];

            if (isset($standings[$home_id]) && isset($standings[$away_id])) {
                $standings[$home_id]['matches_played']++; $standings[$away_id]['matches_played']++;
                $standings[$home_id]['point_scored'] += $game['hometeam_score'];
                $standings[$home_id]['points_allowed'] += $game['awayteam_score'];
                $standings[$away_id]['point_scored'] += $game['awayteam_score'];
                $standings[$away_id]['points_allowed'] += $game['hometeam_score'];
                if ($game['winnerteam_id'] == $home_id) {
                    $standings[$home_id]['wins']++; $standings[$away_id]['losses']++;
                } else {
                    $standings[$away_id]['wins']++; $standings[$home_id]['losses']++;
                }
            }
        }

        // 4. Group the calculated standings by cluster.
        $grouped_standings = [];
        foreach ($standings as $team_stats) {
            $team_stats['point_difference'] = $team_stats['point_scored'] - $team_stats['points_allowed'];
            $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
        }

        // 5. Sort each group with the new, advanced tie-breaker logic.
        foreach ($grouped_standings as &$group) {
            usort($group, function($a, $b) use ($head_to_head) {
                // Primary sort: by wins (descending)
                if ($b['wins'] != $a['wins']) {
                    return $b['wins'] <=> $a['wins'];
                }

                // --- TIE-BREAKER LOGIC ---
                $team_a_id = $a['team_id'];
                $team_b_id = $b['team_id'];

                // Tie-Breaker 1: Head-to-head result
                if (isset($head_to_head[$team_a_id][$team_b_id])) {
                    $winner_id = $head_to_head[$team_a_id][$team_b_id];
                    if ($winner_id == $team_a_id) {
                        return -1; // A wins, so A comes first
                    }
                    if ($winner_id == $team_b_id) {
                        return 1; // B wins, so B comes first
                    }
                }

                // Tie-Breaker 2: Point Difference (used for 3-way ties or if no head-to-head game was played)
                return $b['point_difference'] <=> $a['point_difference'];
            });
        }
        unset($group);

        ?>

        <?php if (empty($grouped_standings)): ?>
             <p>No teams have been assigned to groups yet.</p>
        <?php else: ?>
            <?php foreach ($grouped_standings as $group_name => $teams): ?>
                <h3 class="group-header">Group <?= htmlspecialchars(numberToLetter($group_name)) ?></h3>
                <table class="standings-table">
                    <thead>
                        <tr>
                            <th class="team-name-col">Team</th>
                            <th>MP</th> <th>W</th> <th>L</th>
                            <th>PS</th> <th>PA</th> <th>PD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $index => $team):
                            $advancing_class = ($index < (int)$advance_per_group) ? 'advancing-team' : '';
                        ?>
                            <tr class="<?= $advancing_class ?>">
                                <td class="team-name-col">
                                    <a href="team_details.php?team_id=<?= $team['team_id'] ?>">
                                        <?= htmlspecialchars($team['team_name']) ?>
                                    </a>
                                </td>
                                <td><?= $team['matches_played'] ?></td>
                                <td><?= $team['wins'] ?></td>
                                <td><?= $team['losses'] ?></td>
                                <td><?= $team['point_scored'] ?></td>
                                <td><?= $team['points_allowed'] ?></td>
                                <td><?= $team['point_difference'] > 0 ? '+' : '' ?><?= $team['point_difference'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: // This handles both Single and Double Elimination (Bracket formats) ?>
        <link rel="stylesheet" href="includes/bracket_renderer.css">
        <?php
        $gamesStmt = $pdo->prepare("
            SELECT g.id AS game_id, g.round_name, g.hometeam_id, g.awayteam_id, g.winnerteam_id,
                   ht.team_name AS hometeam_name, awt.team_name AS awayteam_name
            FROM game g
            LEFT JOIN team ht ON g.hometeam_id = ht.id
            LEFT JOIN team awt ON g.awayteam_id = awt.id
            WHERE g.category_id = ? ORDER BY g.id ASC
        ");
        $gamesStmt->execute([$category_id]);
        $all_games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($all_games)) {
            echo "<p>No schedule found for this category.</p>";
        } else {
            $rounds = [];
            foreach ($all_games as $game) { $rounds[$game['round_name']][] = $game; }
            if (!function_exists('render_match')) {
                function render_match($game) {
                    $home_result = '-'; $away_result = '-';
                    if (!empty($game['winnerteam_id'])) {
                        if ($game['winnerteam_id'] == $game['hometeam_id']) { $home_result = 'W'; $away_result = 'L'; }
                        elseif ($game['winnerteam_id'] == $game['awayteam_id']) { $home_result = 'L'; $away_result = 'W'; }
                    } ?>
                    <div class="bracket-match"><div class="bracket-teams">
                        <div class="bracket-team <?= $game['winnerteam_id'] == $game['hometeam_id'] ? 'winner' : '' ?>"><span class="team-name <?= !$game['hometeam_name'] ? 'placeholder' : '' ?>"><?= htmlspecialchars($game['hometeam_name'] ?? 'TBD') ?></span><span class="team-score"><?= $home_result ?></span></div>
                        <div class="bracket-team <?= $game['winnerteam_id'] == $game['awayteam_id'] ? 'winner' : '' ?>"><span class="team-name <?= !$game['awayteam_name'] ? 'placeholder' : '' ?>"><?= htmlspecialchars($game['awayteam_name'] ?? 'TBD') ?></span><span class="team-score"><?= $away_result ?></span></div>
                    </div></div>
                <?php }
            }

            if ($format == 'Double Elimination') {
                $upper_rounds = array_filter($rounds, function($key) { return strpos($key, 'Upper') !== false || strpos($key, 'Grand Final') !== false; }, ARRAY_FILTER_USE_KEY);
                $lower_rounds = array_filter($rounds, function($key) { return strpos($key, 'Lower') !== false; }, ARRAY_FILTER_USE_KEY);
                echo '<h3>Upper Bracket</h3><div class="bracket-container">';
                foreach ($upper_rounds as $round_name => $games) {
                    echo '<div class="bracket-round"><h4 class="bracket-round-title">' . htmlspecialchars($round_name) . '</h4>';
                    foreach ($games as $game) { render_match($game); }
                    echo '</div>';
                }
                echo '</div>';
                echo '<h3 style="margin-top: 40px;">Lower Bracket</h3><div class="bracket-container">';
                foreach ($lower_rounds as $round_name => $games) {
                    echo '<div class="bracket-round"><h4 class="bracket-round-title">' . htmlspecialchars($round_name) . '</h4>';
                    foreach ($games as $game) { render_match($game); }
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<h3>Tournament Bracket</h3><div class="bracket-container">';
                foreach ($rounds as $round_name => $games) {
                    echo '<div class="bracket-round"><h4 class="bracket-round-title">' . htmlspecialchars($round_name) . '</h4>';
                    foreach ($games as $game) { render_match($game); }
                    echo '</div>';
                }
                echo '</div>';
            }
        }
        ?>
    <?php endif; ?>
</div>