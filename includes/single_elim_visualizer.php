<?php
// FILENAME: includes/single_elim_visualizer.php
// DESCRIPTION: Renders the tournament bracket. Has two modes:
// 1. SETUP (if $scheduleGenerated is false): Calculates and displays a draggable bracket for seeding.
// 2. LIVE (if $scheduleGenerated is true): Fetches and displays the live bracket with scores and winners.

// --- HELPER FUNCTIONS ---
if (!function_exists('getSeedOrder')) {
    // Generates the standard seeding order for a power-of-two bracket size.
    function getSeedOrder($num_participants) {
        if ($num_participants <= 1) return [1];
        if (!is_power_of_two($num_participants)) return [];
        $seeds = [1, 2];
        for ($round = 1; $round < log($num_participants, 2); $round++) {
            $new_seeds = [];
            $round_max_seed = 2 ** ($round + 1) + 1;
            foreach ($seeds as $seed) {
                $new_seeds[] = $seed;
                $new_seeds[] = $round_max_seed - $seed;
            }
            $seeds = $new_seeds;
        }
        return $seeds;
    }
}
if (!function_exists('is_power_of_two')) {
    // Checks if a number is a power of two.
    function is_power_of_two($n) { return ($n > 0) && (($n & ($n - 1)) == 0); }
}

// Check if the schedule has been generated to switch between SETUP and LIVE modes.
if (!$scheduleGenerated):
// --- SETUP MODE ---
?>
    <?php
    // --- 1. INITIALIZE & FETCH DATA ---
    // Check if bracket positions are already set for this category.
    $checkPosStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
    $checkPosStmt->execute([$category_id]);
    
    // If no positions exist, create them based on an alphabetical sort of teams.
    if ($checkPosStmt->fetchColumn() == 0) {
        $teams_stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY team_name ASC");
        $teams_stmt->execute([$category_id]);
        $initial_teams = $teams_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $insertPosStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, ?)");
        $pos = 1;
        foreach ($initial_teams as $team_id) { $insertPosStmt->execute([$category_id, $pos, $pos, $team_id]); $pos++; }
    }
    
    // Fetch all teams for this category, ordered by their current position.
    $teams_query = $pdo->prepare("SELECT bp.position, bp.seed, bp.team_id, t.team_name FROM bracket_positions bp JOIN team t ON bp.team_id = t.id WHERE bp.category_id = ? ORDER BY bp.position ASC");
    $teams_query->execute([$category_id]);
    $results = $teams_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Map teams by their position for easy lookup.
    $teams_by_position = [];
    foreach ($results as $row) { $teams_by_position[$row['position']] = ['id' => $row['team_id'], 'name' => $row['team_name'], 'pos' => $row['position'], 'seed' => $row['seed']]; }

    // --- 2. CALCULATE BRACKET STRUCTURE ---
    $num_teams = count($teams_by_position);
    // Find the nearest power-of-two size for the main bracket.
    $main_bracket_size = $num_teams > 1 ? 2 ** floor(log($num_teams - 1, 2)) : 0;
    if ($num_teams > 2 && $num_teams == $main_bracket_size) { $main_bracket_size = $num_teams; } // Handle exact power-of-two
    if ($num_teams == 2) { $main_bracket_size = 2; }
    
    // Determine preliminary matches and byes.
    $num_prelim_matches = $num_teams - $main_bracket_size;
    $num_byes = $main_bracket_size - $num_prelim_matches;
    
    $bracket_structure = [];
    $match_counter = 1;
    $main_round_seeds = getSeedOrder($main_bracket_size);
    $matches_in_first_main_round = $main_bracket_size / 2;

    // --- 3. CREATE PRELIMINARY ROUND & WINNER PLACEHOLDERS ---
    $prelim_winners_map = [];
    if ($num_prelim_matches > 0) {
        $bracket_structure['prelim'] = [];
        // Get teams participating in preliminary rounds (those not receiving a bye).
        $teams_in_prelims = array_slice($teams_by_position, $num_byes, null, true);
        
        // Split prelim teams for high-vs-low seeding.
        $prelim_top_half = array_slice($teams_in_prelims, 0, $num_prelim_matches, true);
        $prelim_bottom_half = array_reverse(array_slice($teams_in_prelims, $num_prelim_matches, null, true), true);
        
        $top_keys = array_keys($prelim_top_half);
        $bottom_keys = array_keys($prelim_bottom_half);
        
        // Create prelim matches and placeholders for the next round.
        for ($i = 0; $i < $num_prelim_matches; $i++) {
            $home_team_pos = $top_keys[$i];
            $bracket_structure['prelim'][$home_team_pos] = ['home' => $teams_by_position[$home_team_pos], 'away' => $teams_by_position[$bottom_keys[$i]], 'match_number' => $match_counter];
            $prelim_winners_map[$home_team_pos] = ['name' => "Winner of Match " . $match_counter, 'is_placeholder' => true];
            $match_counter++;
        }
    }

    // --- 4. BUILD MAIN BRACKET USING STANDARD SEEDING ---
    // Create the list of participants for the first main round.
    $main_round_participants = [];
    foreach ($main_round_seeds as $seed_slot) {
        if ($seed_slot <= $num_byes) {
            // Add teams with byes directly.
            $main_round_participants[] = $teams_by_position[$seed_slot];
        } else {
            // Add placeholders for winners of prelim matches.
            $main_round_participants[] = $prelim_winners_map[$seed_slot];
        }
    }
    
    // --- 5. GENERATE ROUNDS FOR DISPLAY ---
    $total_main_rounds = $main_bracket_size > 1 ? log($main_bracket_size, 2) : 0;
    $current_round_participants = $main_round_participants;
    
    // Loop through each round from Round 1 to the Finals.
    for ($round_num = 1; $round_num <= $total_main_rounds; $round_num++) {
        $bracket_structure[$round_num] = [];
        // Pair up participants for matches in this round.
        for ($i = 0; $i < count($current_round_participants); $i += 2) {
            $bracket_structure[$round_num][] = ['home' => $current_round_participants[$i], 'away' => $current_round_participants[$i + 1] ?? ['name' => 'BYE', 'is_placeholder' => true], 'match_number' => $match_counter++];
        }
        
        // Create placeholders for the *next* round.
        $next_round_participants = [];
        foreach ($bracket_structure[$round_num] as $match_in_current_round) {
            $next_round_participants[] = ['name' => 'Winner of Match ' . $match_in_current_round['match_number'], 'is_placeholder' => true];
        }
        $current_round_participants = $next_round_participants;
    }
    
    // Helper function to get round names.
    if (!function_exists('getRoundName')) {
        function getRoundName($num_teams) {
            if ($num_teams == 2) return 'Finals'; if ($num_teams == 4) return 'Semifinals'; if ($num_teams == 8) return 'Quarterfinals';
            return "Round of {$num_teams}";
        }
    }
    ?>
    <p class="info-message" style="text-align: left; padding: 0 0 1.5rem 0;">Drag and drop any team in its starting position to swap matchups.</p>
    
    <div class="bracket-container">
        <?php // --- RENDER PRELIMINARY ROUND (SETUP MODE) --- ?>
        <?php if (isset($bracket_structure['prelim']) && !empty($bracket_structure['prelim'])): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title">Preliminary Round</h4>
                <?php
                // This loop aligns prelim matches with the first main round's structure.
                for ($i = 0; $i < $matches_in_first_main_round; $i++):
                    $slot_seed_home = $main_round_seeds[$i * 2];
                    $slot_seed_away = $main_round_seeds[$i * 2 + 1];

                    $home_is_prelim = ($slot_seed_home > $num_byes);
                    $away_is_prelim = ($slot_seed_away > $num_byes);

                    if ($home_is_prelim && $away_is_prelim) {
                        // Case: Two prelim matches feed into one main round match.
                        $match1 = $bracket_structure['prelim'][$slot_seed_home];
                        $match2 = $bracket_structure['prelim'][$slot_seed_away];
                        echo '<div class="bracket-match-group">';
                        
                        echo '<div class="bracket-match"><div class="match-number">Match ' . $match1['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match1['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match1['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match1['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match1['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match1['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match1['away']['name']) . '</span></div>';
                        echo '</div></div>';

                        echo '<div class="bracket-match"><div class="match-number">Match ' . $match2['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match2['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match2['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match2['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match2['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match2['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match2['away']['name']) . '</span></div>';
                        echo '</div></div>';

                        echo '</div>';
                    }
                    elseif ($home_is_prelim || $away_is_prelim) {
                        // Case: One prelim match and one bye feed into a main round match.
                        $correct_prelim_seed = $home_is_prelim ? $slot_seed_home : $slot_seed_away;
                        $match = $bracket_structure['prelim'][$correct_prelim_seed];
                        echo '<div class="bracket-match"><div class="match-number">Match ' . $match['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['away']['name']) . '</span></div>';
                        echo '</div></div>';
                    }
                    else {
                        // Case: Two byes feed into a main round match. Render a spacer.
                        echo '<div class="bracket-spacer"></div>';
                    }
                endfor;
                ?>
            </div>
        <?php endif; ?>

        <?php // --- RENDER MAIN ROUNDS (SETUP MODE) --- ?>
        <?php for ($r = 1; $r <= $total_main_rounds; $r++): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title"><?= getRoundName($main_bracket_size / (2 ** ($r - 1))) ?></h4>
                <?php foreach ($bracket_structure[$r] as $match_index => $match): ?>
                    <?php
                    // Add spacers above matches to align them vertically with previous rounds.
                    if ($match_index > 0) {
                        $num_spacers = (2 ** ($r - 1)) - 1;
                        for ($s = 0; $s < $num_spacers; $s++) {
                            echo '<div class="bracket-spacer"></div>';
                        }
                    }
                    ?>
                    <div class="bracket-match">
                        <div class="match-number">Match <?= $match['match_number'] ?></div>
                        <div class="bracket-teams">
                            <div class="bracket-team <?= !isset($match['home']['is_placeholder']) ? 'draggable' : 'placeholder' ?>" draggable="<?= !isset($match['home']['is_placeholder']) ? 'true' : 'false' ?>" data-position-id="<?= $match['home']['pos'] ?? '' ?>">
                                <?php if (!isset($match['home']['is_placeholder'])): // Is a real team ?>
                                    <span class="seed">(<?= htmlspecialchars($match['home']['seed']) ?>)</span>
                                <?php endif; ?>
                                <span class="team-name"><?= htmlspecialchars($match['home']['name'] ?? 'TBD') ?></span>
                            </div>
                            <div class="bracket-team <?= !isset($match['away']['is_placeholder']) ? 'draggable' : 'placeholder' ?>" draggable="<?= !isset($match['away']['is_placeholder']) ? 'true' : 'false' ?>" data-position-id="<?= $match['away']['pos'] ?? '' ?>">
                                <?php if (!isset($match['away']['is_placeholder'])): // Is a real team ?>
                                    <span class="seed">(<?= htmlspecialchars($match['away']['seed']) ?>)</span>
                                <?php endif; ?>
                                <span class="team-name"><?= htmlspecialchars($match['away']['name'] ?? 'TBD') ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>
<?php
else:
// --- LIVE MODE ---
?>
    <?php
    // --- 1. FETCH ALL GAME DATA ---
    $third_place_match = null;
    $all_games_query = $pdo->prepare("SELECT g.id, g.round, g.round_name, g.hometeam_id, g.awayteam_id, g.winnerteam_id, g.hometeam_score, g.awayteam_score, g.game_status, g.winner_advances_to_game_id, g.winner_advances_to_slot, ht.team_name as hometeam_name, awt.team_name as awayteam_name, bph.seed as hometeam_seed, bpa.seed as awayteam_seed, bph.position as hometeam_pos FROM game g LEFT JOIN team ht ON g.hometeam_id = ht.id LEFT JOIN team awt ON g.awayteam_id = awt.id LEFT JOIN bracket_positions bph ON g.hometeam_id = bph.team_id AND bph.category_id = g.category_id LEFT JOIN bracket_positions bpa ON g.awayteam_id = bpa.team_id AND bpa.category_id = g.category_id WHERE g.category_id = ? ORDER BY g.round ASC, g.id ASC");
    $all_games_query->execute([$category_id]);
    $all_games = $all_games_query->fetchAll(PDO::FETCH_ASSOC);

    // Create a map of game_id to a sequential match number (1, 2, 3...).
    $matchNumberMap = [];
    foreach (array_values($all_games) as $index => $game) { $matchNumberMap[$game['id']] = $index + 1; }
    
    // Create a map to know which match feeds into which slot (e.g., Game 5, 'home' slot is fed by Match 1).
    $feederMap = [];
    foreach ($all_games as $game) {
        if ($game['winner_advances_to_game_id']) {
            $target_game_id = $game['winner_advances_to_game_id'];
            $target_slot = $game['winner_advances_to_slot'];
            $source_match_number = $matchNumberMap[$game['id']];
            $feederMap[$target_game_id][$target_slot] = $source_match_number;
        }
    }

    // --- 2. RECALCULATE BRACKET STRUCTURE FOR ALIGNMENT ---
    // This is needed to correctly align the preliminary round display.
    $teams_query = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
    $teams_query->execute([$category_id]);
    $num_teams = $teams_query->fetchColumn();

    $main_bracket_size = $num_teams > 1 ? 2 ** floor(log($num_teams - 1, 2)) : 0;
    if ($num_teams > 2 && $num_teams == $main_bracket_size) { $main_bracket_size = $num_teams; }
    if ($num_teams == 2) { $main_bracket_size = 2; }
    $num_byes = $main_bracket_size - ($num_teams - $main_bracket_size);
    $main_round_seeds = getSeedOrder($main_bracket_size);
    $matches_in_first_main_round = $main_bracket_size / 2;

    // --- 3. ORGANIZE GAMES FOR DISPLAY ---
    $prelim_games = [];
    $main_round_games = [];
    foreach($all_games as $game) {
        if ($game['round_name'] === '3rd Place Match') {
            $third_place_match = $game;
        } elseif ($game['round_name'] === 'Preliminary Round') {
            // Use team's original position as key for prelim alignment.
            $prelim_games[$game['hometeam_pos']] = $game;
        } else {
            // Group main round games by round name.
            if (!isset($main_round_games[$game['round_name']])) {
                $main_round_games[$game['round_name']] = [];
            }
            $main_round_games[$game['round_name']][] = $game;
        }
    }
    ?>
    <div class="bracket-wrapper">
        <div class="bracket-container">
            <?php // --- RENDER PRELIMINARY ROUND (Live Mode) --- ?>
            <?php if (!empty($prelim_games)): ?>
                <div class="bracket-round">
                    <h4 class="bracket-round-title">Preliminary Round</h4>
                    <?php
                    // Reusable function to render a single match box.
                    $render_match = function($match, $matchNumberMap) {
                        echo '<div class="bracket-match"><div class="match-number">Match ' . ($matchNumberMap[$match['id']] ?? '') . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team ' . (($match['winnerteam_id'] && $match['winnerteam_id'] == $match['hometeam_id']) ? 'winner' : '') . '">';
                        echo '<span class="seed">(' . htmlspecialchars($match['hometeam_seed']) . ')</span>';
                        echo '<span class="team-name">' . htmlspecialchars($match['hometeam_name']) . '</span>';
                        echo '<span class="score">' . (($match['game_status'] == 'Final') ? $match['hometeam_score'] : '') . '</span>';
                        echo '</div>';
                        echo '<div class="bracket-team ' . (($match['winnerteam_id'] && $match['winnerteam_id'] == $match['awayteam_id']) ? 'winner' : '') . '">';
                        echo '<span class="seed">(' . htmlspecialchars($match['awayteam_seed']) . ')</span>';
                        echo '<span class="team-name">' . htmlspecialchars($match['awayteam_name']) . '</span>';
                        echo '<span class="score">' . (($match['game_status'] == 'Final') ? $match['awayteam_score'] : '') . '</span>';
                        echo '</div>';
                        echo '</div></div>';
                    };

                    // Loop to align prelim matches with the main bracket, same logic as SETUP mode.
                    for ($i = 0; $i < $matches_in_first_main_round; $i++):
                        $slot_seed_home = $main_round_seeds[$i * 2];
                        $slot_seed_away = $main_round_seeds[$i * 2 + 1];
                        $home_is_prelim = ($slot_seed_home > $num_byes);
                        $away_is_prelim = ($slot_seed_away > $num_byes);

                        if ($home_is_prelim && $away_is_prelim) {
                            echo '<div class="bracket-match-group">';
                            if (isset($prelim_games[$slot_seed_home])) $render_match($prelim_games[$slot_seed_home], $matchNumberMap);
                            if (isset($prelim_games[$slot_seed_away])) $render_match($prelim_games[$slot_seed_away], $matchNumberMap);
                            echo '</div>';
                        } elseif ($home_is_prelim || $away_is_prelim) {
                            $correct_prelim_pos = $home_is_prelim ? $slot_seed_home : $slot_seed_away;
                            if (isset($prelim_games[$correct_prelim_pos])) {
                                $render_match($prelim_games[$correct_prelim_pos], $matchNumberMap);
                            } else {
                                echo '<div class="bracket-spacer"></div>';
                            }
                        } else {
                            echo '<div class="bracket-spacer"></div>';
                        }
                    endfor;
                    ?>
                </div>
            <?php endif; ?>

            <?php // --- RENDER MAIN ROUNDS (Live Mode) --- ?>
            <?php 
            $round_num = 1; 
            foreach ($main_round_games as $round_name => $round_matches): 
            ?>
                <div class="bracket-round">
                    <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                    <?php foreach ($round_matches as $match_index => $match): ?>
                        <?php
                        // Add spacers for vertical alignment.
                        if ($match_index > 0) {
                            $num_spacers = (2 ** $round_num) - 1;
                            for ($s = 0; $s < $num_spacers; $s++) {
                                echo '<div class="bracket-spacer"></div>';
                            }
                        }
                        ?>
                        <div class="bracket-match">
                            <div class="match-number">Match <?= $matchNumberMap[$match['id']] ?></div>
                            <div class="bracket-teams">
                                 <div class="bracket-team <?= ($match['winnerteam_id'] && $match['winnerteam_id'] == $match['hometeam_id']) ? 'winner' : '' ?>">
                                    <?php if ($match['hometeam_name']): // Team is known ?>
                                        <span class="seed">(<?= htmlspecialchars($match['hometeam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['hometeam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['hometeam_score'] : '' ?></span>
                                    <?php else: // Team is not yet known, show placeholder ?>
                                        <?php 
                                        $placeholder = 'TBD';
                                        if (isset($feederMap[$match['id']]['home'])) {
                                            $placeholder = 'Winner of Match ' . $feederMap[$match['id']]['home'];
                                        }
                                        ?>
                                        <span class="team-name placeholder"><?= $placeholder ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bracket-team <?= ($match['winnerteam_id'] && $match['winnerteam_id'] == $match['awayteam_id']) ? 'winner' : '' ?>">
                                    <?php if ($match['awayteam_name']): // Team is known ?>
                                        <span class="seed">(<?= htmlspecialchars($match['awayteam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['awayteam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['awayteam_score'] : '' ?></span>
                                    <?php else: // Team is not yet known, show placeholder ?>
                                        <?php 
                                        $placeholder = 'TBD';
                                        if (isset($feederMap[$match['id']]['away'])) {
                                            $placeholder = 'Winner of Match ' . $feederMap[$match['id']]['away'];
                                        }
                                        ?>
                                        <span class="team-name placeholder"><?= $placeholder ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php 
            $round_num++;
            endforeach; 
            ?>
        </div>
        
        <?php // --- RENDER 3RD PLACE MATCH (if it exists) --- ?>
        <?php if ($third_place_match): ?>
        <div class="third-place-container">
            <div class="bracket-round">
                <h4 class="bracket-round-title">3rd Place Match</h4>
                <div class="bracket-match">
                    <div class="match-number">Match <?= $matchNumberMap[$third_place_match['id']] ?></div>
                    <div class="bracket-teams">
                        <div class="bracket-team <?= ($third_place_match['winnerteam_id'] && $third_place_match['winnerteam_id'] == $third_place_match['hometeam_id']) ? 'winner' : '' ?>">
                            <?php if ($third_place_match['hometeam_name']): ?>
                                <span class="seed">(<?= htmlspecialchars($third_place_match['hometeam_seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($third_place_match['hometeam_name']) ?></span>
                                <span class="score"><?= ($third_place_match['game_status'] == 'Final') ? $third_place_match['hometeam_score'] : '' ?></span>
                            <?php else: ?>
                                <span class="team-name placeholder">Loser of Semifinal</span>
                            <?php endif; ?>
                        </div>
                        <div class="bracket-team <?= ($third_place_match['winnerteam_id'] && $third_place_match['winnerteam_id'] == $third_place_match['awayteam_id']) ? 'winner' : '' ?>">
                            <?php if ($third_place_match['awayteam_name']): ?>
                                <span class="seed">(<?= htmlspecialchars($third_place_match['awayteam_seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($third_place_match['awayteam_name']) ?></span>
                                <span class="score"><?= ($third_place_match['game_status'] == 'Final') ? $third_place_match['awayteam_score'] : '' ?></span>
                            <?php else: ?>
                                <span class="team-name placeholder">Loser of Semifinal</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php
endif;
?>

<?php // --- BRACKET CONTROLS (Lock/Unlock) --- ?>
<div class="bracket-controls">
    <?php if (!$bracketLocked): ?>
        <form action="lock_bracket.php" method="POST" onsubmit="return confirm('Are you sure you want to lock this bracket? Matchups cannot be changed after locking.')">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <button type="submit" class="btn btn-primary">Lock Bracket & Proceed to Schedule</button>
        </form>
    <?php else: ?>
        <?php if ($hasFinalGames): ?>
            <button type="button" class="btn" disabled>Unlock Bracket</button>
            <p>Cannot unlock bracket because games have been played.</p>
        <?php else: ?>
            <form action="unlock_bracket.php" method="POST" onsubmit="return confirm('Unlocking will allow you to change matchups. Are you sure?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" class="btn btn-secondary">Unlock Bracket</button>
            </form>
        <?php endif; ?>
        <?php if (!$scheduleGenerated): ?>
            <p class="success-message"><strong>Bracket is locked. You can now generate the schedule in the 'Schedule' tab.</strong></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php // --- JAVASCRIPT for Drag-and-Drop (SETUP MODE only) --- ?>
<?php if (!$scheduleGenerated && !$bracketLocked): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const draggables = document.querySelectorAll('.bracket-team.draggable');
    let draggedElement = null;

    draggables.forEach(elem => {
        elem.addEventListener('dragstart', (e) => {
            const target = e.target.closest('.bracket-team.draggable');
            if (!target.dataset.positionId) { e.preventDefault(); return; }
            draggedElement = target;
            e.dataTransfer.effectAllowed = 'move';
            target.style.opacity = '0.5';
        });

        elem.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });

        elem.addEventListener('drop', (e) => {
            e.preventDefault();
            const dropTarget = e.target.closest('.bracket-team.draggable');
            
            // Check if the drop is valid (on another draggable team).
            if (!dropTarget || !dropTarget.dataset.positionId || dropTarget === draggedElement) {
                if (draggedElement) draggedElement.style.opacity = '1';
                return;
            }
            
            const sourcePosId = draggedElement.dataset.positionId;
            const targetPosId = dropTarget.dataset.positionId;
            if (!sourcePosId || !targetPosId) { if (draggedElement) draggedElement.style.opacity = '1'; return; }

            // Send AJAX request to swap positions in the database.
            fetch('includes/swap_bracket_position.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category_id: <?= $category_id ?>, position1: sourcePosId, position2: targetPosId })
            }).then(response => {
                if (!response.ok) { return response.text().then(text => { throw new Error(`Server Error: ${response.status}\n${text}`); }); }
                return response.json();
            }).then(data => {
                if (data.status === 'success') {
                    location.reload(); // Reload the page to see the new bracket structure.
                } else {
                    alert('API Error: ' + data.message);
                    if (draggedElement) draggedElement.style.opacity = '1';
                }
            }).catch(error => {
                console.error("Swap Request Failed:", error);
                alert("A critical error occurred. Please check the developer console.");
                if (draggedElement) draggedElement.style.opacity = '1';
            });
        });

        elem.addEventListener('dragend', (e) => {
            // Clean up styles after drag ends.
            if (draggedElement) { draggedElement.style.opacity = '1'; }
            draggedElement = null;
        });
    });
});
</script>
<?php endif; ?>

<style>
/* --- Font Smoothing --- */
.bracket-container {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* --- Main Layout --- */
.bracket-wrapper { 
    display: flex; 
    flex-direction: column; 
    align-items: flex-start; 
}
.bracket-container {
    display: flex;
    flex-direction: row;
    overflow-x: auto;
    background-color: #f8f9fa;
    padding: 2rem;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    width: 100%;
}
.bracket-controls {
    margin-top: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    text-align: center;
}
.bracket-controls p {
    margin-top: 0.5rem;
}

/* --- Round Styling --- */
.bracket-round {
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    margin-right: 5rem; 
    min-width: 250px; 
    justify-content: space-around;
    position: relative; 
}
.bracket-round:last-child {
    margin-right: 0;
}
.bracket-round-title { 
    text-align: center; 
    color: #495057; 
    margin-bottom: 2rem; 
    font-weight: 700; 
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

/* --- Match Styling --- */
.bracket-match {
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    flex-grow: 1; 
    margin-bottom: 1.5rem; /* Space for match number and connectors */
}
.bracket-teams {
    background-color: var(--bg-light);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    position: relative;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.bracket-team {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    min-height: 48px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background-color 0.2s ease;
}
.bracket-team:last-child { 
    border-bottom: none; 
}
.bracket-team.draggable { 
    cursor: grab; 
}
.bracket-team.draggable:hover {
    background-color: #e9ecef;
}
.bracket-team.placeholder { 
    background-color: #f8f9fa; 
    color: #888; 
}
.team-name.placeholder { 
    font-style: italic; 
    font-size: 0.85rem;
}
.bracket-team.winner { 
    background-color: #d1fae5;
    font-weight: 600;
}
.seed { 
    font-weight: 600; 
    color: #6c757d; 
    margin-right: 0.75rem; 
}
.team-name { 
    flex-grow: 1; 
}
.score { 
    font-weight: 700;
    color: var(--bap-blue); 
    margin-left: 1rem; 
    background-color: #e9ecef;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    min-width: 28px;
    text-align: center;
}
.winner .score {
    background-color: #a7f3d0;
}
.match-number {
    text-align: center;
    color: #888;
    font-size: 0.75rem;
    margin-bottom: 0.25rem;
    height: 1rem; /* Ensures space is always reserved */
}

/* --- SPACER for ALIGNMENT --- */
.bracket-spacer {
    flex-grow: 1;
    visibility: hidden;
}

/* --- Class for Asymmetrical Brackets --- */
.bracket-match-group {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-around;
}

/* --- Third Place Match --- */
.third-place-container {
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px dashed var(--border-color);
    display: flex;
    justify-content: center;
}
</style>