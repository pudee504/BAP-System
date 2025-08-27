<?php
// This file is included in 'category_tabs_standings.php'.
// It has two modes: SETUP (before schedule generation) and LIVE (after).

// --- HELPER FUNCTIONS ---
if (!function_exists('getSeedOrder')) {
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
    function is_power_of_two($n) { return ($n > 0) && (($n & ($n - 1)) == 0); }
}

if (!$scheduleGenerated):
// --- SETUP MODE ---
?>
    <?php
    // --- 1. INITIALIZE & FETCH DATA ---
    $checkPosStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
    $checkPosStmt->execute([$category_id]);
    if ($checkPosStmt->fetchColumn() == 0) {
        $teams_stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY team_name ASC");
        $teams_stmt->execute([$category_id]);
        $initial_teams = $teams_stmt->fetchAll(PDO::FETCH_COLUMN);
        $insertPosStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, ?)");
        $pos = 1;
        foreach ($initial_teams as $team_id) { $insertPosStmt->execute([$category_id, $pos, $pos, $team_id]); $pos++; }
    }
    $teams_query = $pdo->prepare("SELECT bp.position, bp.seed, bp.team_id, t.team_name FROM bracket_positions bp JOIN team t ON bp.team_id = t.id WHERE bp.category_id = ? ORDER BY bp.position ASC");
    $teams_query->execute([$category_id]);
    $results = $teams_query->fetchAll(PDO::FETCH_ASSOC);
    $teams_by_position = [];
    foreach ($results as $row) { $teams_by_position[$row['position']] = ['id' => $row['team_id'], 'name' => $row['team_name'], 'pos' => $row['position'], 'seed' => $row['seed']]; }

    // --- 2. CALCULATE BRACKET STRUCTURE ---
    $num_teams = count($teams_by_position);
    $main_bracket_size = $num_teams > 1 ? 2 ** floor(log($num_teams - 1, 2)) : 0;
    if ($num_teams > 2 && $num_teams == $main_bracket_size) { $main_bracket_size = $num_teams; }
    if ($num_teams == 2) { $main_bracket_size = 2; }
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
        $teams_in_prelims = array_slice($teams_by_position, $num_byes, null, true);
        $prelim_top_half = array_slice($teams_in_prelims, 0, $num_prelim_matches, true);
        $prelim_bottom_half = array_reverse(array_slice($teams_in_prelims, $num_prelim_matches, null, true), true);
        $top_keys = array_keys($prelim_top_half);
        $bottom_keys = array_keys($prelim_bottom_half);
        for ($i = 0; $i < $num_prelim_matches; $i++) {
            $home_team_pos = $top_keys[$i];
            $bracket_structure['prelim'][$home_team_pos] = ['home' => $teams_by_position[$home_team_pos], 'away' => $teams_by_position[$bottom_keys[$i]], 'match_number' => $match_counter];
            $prelim_winners_map[$home_team_pos] = ['name' => "Winner of Match " . $match_counter, 'is_placeholder' => true];
            $match_counter++;
        }
    }

    // --- 4. BUILD MAIN BRACKET USING STANDARD SEEDING ---
    $main_round_participants = [];
    foreach ($main_round_seeds as $seed_slot) {
        if ($seed_slot <= $num_byes) { $main_round_participants[] = $teams_by_position[$seed_slot]; } 
        else { $main_round_participants[] = $prelim_winners_map[$seed_slot]; }
    }
    
    // --- 5. GENERATE ROUNDS FOR DISPLAY ---
    $total_main_rounds = $main_bracket_size > 1 ? log($main_bracket_size, 2) : 0;
    $current_round_participants = $main_round_participants;
    for ($round_num = 1; $round_num <= $total_main_rounds; $round_num++) {
        $bracket_structure[$round_num] = [];
        for ($i = 0; $i < count($current_round_participants); $i += 2) {
            $bracket_structure[$round_num][] = ['home' => $current_round_participants[$i], 'away' => $current_round_participants[$i + 1] ?? ['name' => 'BYE', 'is_placeholder' => true], 'match_number' => $match_counter++];
        }
        $next_round_participants = [];
        foreach ($bracket_structure[$round_num] as $match_in_current_round) {
            $next_round_participants[] = ['name' => 'Winner of Match ' . $match_in_current_round['match_number'], 'is_placeholder' => true];
        }
        $current_round_participants = $next_round_participants;
    }
    
    if (!function_exists('getRoundName')) {
        function getRoundName($num_teams) {
            if ($num_teams == 2) return 'Finals'; if ($num_teams == 4) return 'Semifinals'; if ($num_teams == 8) return 'Quarterfinals';
            return "Round of {$num_teams}";
        }
    }
    ?>
    <p>Drag and drop any team in its starting position to swap matchups.</p>
    
    <div class="bracket-container">
        <?php // --- PRELIMINARY ROUND LOGIC (SETUP MODE) --- ?>
        <?php if (isset($bracket_structure['prelim']) && !empty($bracket_structure['prelim'])): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title">Preliminary Round</h4>
                <?php
                for ($i = 0; $i < $matches_in_first_main_round; $i++):
                    $slot_seed_home = $main_round_seeds[$i * 2];
                    $slot_seed_away = $main_round_seeds[$i * 2 + 1];

                    $home_is_prelim = ($slot_seed_home > $num_byes);
                    $away_is_prelim = ($slot_seed_away > $num_byes);

                    if ($home_is_prelim && $away_is_prelim) {
                        $match1 = $bracket_structure['prelim'][$slot_seed_home];
                        $match2 = $bracket_structure['prelim'][$slot_seed_away];
                        echo '<div class="bracket-match-group">';
                        
                        echo '<div class="bracket-match">';
                        echo '<div class="match-number">Match ' . $match1['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match1['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match1['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match1['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match1['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match1['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match1['away']['name']) . '</span></div>';
                        echo '</div></div>';

                        echo '<div class="bracket-match">';
                        echo '<div class="match-number">Match ' . $match2['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match2['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match2['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match2['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match2['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match2['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match2['away']['name']) . '</span></div>';
                        echo '</div></div>';

                        echo '</div>';
                    }
                    elseif ($home_is_prelim || $away_is_prelim) {
                        $correct_prelim_seed = $home_is_prelim ? $slot_seed_home : $slot_seed_away;
                        $match = $bracket_structure['prelim'][$correct_prelim_seed];
                        echo '<div class="bracket-match">';
                        echo '<div class="match-number">Match ' . $match['match_number'] . '</div>';
                        echo '<div class="bracket-teams">';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match['home']['pos'] . '"><span class="seed">(' . htmlspecialchars($match['home']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['home']['name']) . '</span></div>';
                        echo '<div class="bracket-team draggable" draggable="true" data-position-id="' . $match['away']['pos'] . '"><span class="seed">(' . htmlspecialchars($match['away']['seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['away']['name']) . '</span></div>';
                        echo '</div></div>';
                    }
                    else {
                        echo '<div class="bracket-spacer"></div>';
                    }
                endfor;
                ?>
            </div>
        <?php endif; ?>

        <?php // --- MAIN ROUNDS (SETUP MODE) --- ?>
        <?php for ($r = 1; $r <= $total_main_rounds; $r++): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title"><?= getRoundName($main_bracket_size / (2 ** ($r - 1))) ?></h4>
                <?php foreach ($bracket_structure[$r] as $match_index => $match): ?>
                    <?php
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
                                <?php if (!isset($match['home']['is_placeholder'])): ?>
                                    <span class="seed">(<?= htmlspecialchars($match['home']['seed']) ?>)</span>
                                <?php endif; ?>
                                <span class="team-name"><?= htmlspecialchars($match['home']['name'] ?? 'TBD') ?></span>
                            </div>
                            <div class="bracket-team <?= !isset($match['away']['is_placeholder']) ? 'draggable' : 'placeholder' ?>" draggable="<?= !isset($match['away']['is_placeholder']) ? 'true' : 'false' ?>" data-position-id="<?= $match['away']['pos'] ?? '' ?>">
                                <?php if (!isset($match['away']['is_placeholder'])): ?>
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

    $matchNumberMap = [];
    foreach (array_values($all_games) as $index => $game) { $matchNumberMap[$game['id']] = $index + 1; }
    
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
            $prelim_games[$game['hometeam_pos']] = $game;
        } else {
            if (!isset($main_round_games[$game['round_name']])) {
                $main_round_games[$game['round_name']] = [];
            }
            $main_round_games[$game['round_name']][] = $game;
        }
    }
    ?>
    <div class="bracket-wrapper">
        <div class="bracket-container">
            <?php // --- PRELIMINARY ROUND (Live Mode) --- ?>
            <?php if (!empty($prelim_games)): ?>
                <div class="bracket-round">
                    <h4 class="bracket-round-title">Preliminary Round</h4>
                    <?php
                    $render_match = function($match, $matchNumberMap) {
                        echo '<div class="bracket-match">';
                        echo '<div class="match-number">Match ' . ($matchNumberMap[$match['id']] ?? '') . '</div>';
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

            <?php // --- MAIN ROUNDS (Live Mode with Placeholders) --- ?>
            <?php 
            $round_num = 1; 
            foreach ($main_round_games as $round_name => $round_matches): 
            ?>
                <div class="bracket-round">
                    <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                    <?php foreach ($round_matches as $match_index => $match): ?>
                        <?php
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
                                    <?php if ($match['hometeam_name']): ?>
                                        <span class="seed">(<?= htmlspecialchars($match['hometeam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['hometeam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['hometeam_score'] : '' ?></span>
                                    <?php else: 
                                        $placeholder = 'TBD';
                                        if (isset($feederMap[$match['id']]['home'])) {
                                            $placeholder = 'Winner of Match ' . $feederMap[$match['id']]['home'];
                                        }
                                    ?>
                                        <span class="team-name placeholder"><?= $placeholder ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bracket-team <?= ($match['winnerteam_id'] && $match['winnerteam_id'] == $match['awayteam_id']) ? 'winner' : '' ?>">
                                    <?php if ($match['awayteam_name']): ?>
                                        <span class="seed">(<?= htmlspecialchars($match['awayteam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['awayteam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['awayteam_score'] : '' ?></span>
                                    <?php else:
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
        <?php // ?>
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

<div style="margin-top: 20px;">
    <?php if (!$bracketLocked): ?>
        <form action="lock_bracket.php" method="POST" onsubmit="return confirm('Are you sure you want to lock this bracket? Matchups cannot be changed after locking.')">
            <input type="hidden" name="category_id" value="<?= $category_id ?>">
            <button type="submit">Lock Bracket & Proceed to Schedule</button>
        </form>
    <?php else: ?>
        <?php if ($hasFinalGames): ?>
            <button type="button" disabled>Unlock Bracket</button>
            <p style="color: #6c757d; font-size: 0.9em; margin-top: 5px;">Cannot unlock bracket because games have been played.</p>
        <?php else: ?>
            <form action="unlock_bracket.php" method="POST" onsubmit="return confirm('Unlocking will allow you to change matchups. Are you sure?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" style="background-color: #ffc107; color: #212529;">Unlock Bracket</button>
            </form>
        <?php endif; ?>
        <?php if (!$scheduleGenerated): ?>
            <p style="color:green; margin-top: 10px;"><strong>Bracket is locked. You can now generate the schedule in the 'Schedule' tab.</strong></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

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
            if (!dropTarget || !dropTarget.dataset.positionId || dropTarget === draggedElement) {
                if (draggedElement) draggedElement.style.opacity = '1';
                return;
            }
            const sourcePosId = draggedElement.dataset.positionId;
            const targetPosId = dropTarget.dataset.positionId;
            if (!sourcePosId || !targetPosId) { if (draggedElement) draggedElement.style.opacity = '1'; return; }
            fetch('includes/swap_bracket_position.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category_id: <?= $category_id ?>, position1: sourcePosId, position2: targetPosId })
            }).then(response => {
                if (!response.ok) { return response.text().then(text => { throw new Error(`Server Error: ${response.status}\n${text}`); }); }
                return response.json();
            }).then(data => {
                if (data.status === 'success') { location.reload(); } 
                else { alert('API Error: ' + data.message); if (draggedElement) draggedElement.style.opacity = '1'; }
            }).catch(error => {
                console.error("Swap Request Failed:", error);
                alert("A critical error occurred. Please check the developer console.");
                if (draggedElement) draggedElement.style.opacity = '1';
            });
        });
        elem.addEventListener('dragend', (e) => {
            if (draggedElement) { draggedElement.style.opacity = '1'; }
            draggedElement = null;
        });
    });
});
</script>
<?php endif; ?>

<style>
/* --- Main Layout --- */
.bracket-wrapper { display: flex; flex-direction: column; align-items: flex-start; }
.bracket-container {
    display: flex;
    flex-direction: row;
    overflow-x: auto;
    background-color: #2c2c2c;
    padding: 20px;
    border-radius: 8px;
    color: #fff;
    width: 100%;
}

/* --- Round Styling --- */
.bracket-round {
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    margin-right: 50px;
    min-width: 220px;
}
.bracket-round-title { text-align: center; color: #aaa; margin-bottom: 25px; font-weight: 600; }

/* --- Match & Team Styling --- */
.bracket-match {
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    flex-grow: 1; 
    padding-top: 25px;
}
.bracket-teams {
    background-color: #444;
    border-radius: 4px;
    border: 1px solid #666;
    position: relative;
}
.bracket-team {
    padding: 12px;
    border-bottom: 1px solid #666;
    font-size: 0.9em;
    min-height: 42px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.bracket-team:last-child { border-bottom: none; }
.bracket-team.draggable { cursor: grab; }
.bracket-team.placeholder { background-color: #383838; color: #888; }
.team-name.placeholder { font-style: italic; }
.bracket-team.winner { background-color: #3a5943; }
.seed { font-weight: bold; color: #999; margin-right: 10px; }
.team-name { flex-grow: 1; }
.score { font-weight: bold; color: #fff; margin-left: 10px; }

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

/* --- Match Number Badge --- */
.match-number {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    text-align: center;
    color: #ccc;
    font-size: 12px;
}
</style>