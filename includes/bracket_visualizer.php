<?php
// This file is included in 'category_tabs_standings.php'.
// It has two modes: SETUP (before schedule generation) and LIVE (after).

// --- HELPER FUNCTION DEFINED AT THE TOP TO PREVENT ERRORS ---
if (!function_exists('createBalancedPairings')) {
    function createBalancedPairings($participants) {
        $count = count($participants);
        if ($count < 2) {
            return $participants;
        }
        $split_point = (int) ceil($count / 2);
        $left = array_slice($participants, 0, $split_point);
        $right = array_reverse(array_slice($participants, $split_point));
        $seeded_list = [];
        for ($i = 0; $i < count($right); $i++) {
            $seeded_list[] = $left[$i];
            $seeded_list[] = $right[$i];
        }
        if (count($left) > count($right)) {
            $seeded_list[] = end($left);
        }
        return $seeded_list;
    }
}

if (!$scheduleGenerated):
// --- SETUP MODE ---
?>
    <?php
    
    // --- 1. INITIALIZE BRACKET POSITIONS ---
    $checkPosStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
    $checkPosStmt->execute([$category_id]);
    if ($checkPosStmt->fetchColumn() == 0) {
        $teams_stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY team_name ASC");
        $teams_stmt->execute([$category_id]);
        $initial_teams = $teams_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $insertPosStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, ?)");
        $pos = 1;
        foreach ($initial_teams as $team_id) {
            $insertPosStmt->execute([$category_id, $pos, $pos, $team_id]);
            $pos++;
        }
    }

    // --- 2. BUILD THE SETUP BRACKET STRUCTURE ---
    // Fetch seed along with other team data
    $teams_query = $pdo->prepare("SELECT bp.position, bp.seed, bp.team_id, t.team_name FROM bracket_positions bp JOIN team t ON bp.team_id = t.id WHERE bp.category_id = ? ORDER BY bp.position ASC");
    $teams_query->execute([$category_id]);
    $results = $teams_query->fetchAll(PDO::FETCH_ASSOC);
    $teams_by_position = [];
    foreach ($results as $row) {
        $teams_by_position[$row['position']] = ['id' => $row['team_id'], 'name' => $row['team_name'], 'pos' => $row['position'], 'seed' => $row['seed']];
    }
    $num_teams = count($teams_by_position);
    
    $main_bracket_size = $num_teams > 1 ? 2 ** floor(log($num_teams - 1, 2)) : 0;
    if ($num_teams > 2 && $num_teams == $main_bracket_size) { $main_bracket_size = $num_teams; }
    if ($num_teams == 2) { $main_bracket_size = 2; }

    $num_prelim_matches = $num_teams - $main_bracket_size;
    $num_byes = $main_bracket_size - $num_prelim_matches;

    $bracket_structure = [];
    $all_teams_list = array_values($teams_by_position);
    $teams_with_byes = array_slice($all_teams_list, 0, $num_byes);
    $teams_in_prelims = array_slice($all_teams_list, $num_byes);
    $match_counter = 1;
    
    $prelim_winners_placeholders = [];
    if ($num_prelim_matches > 0) {
        $bracket_structure['prelim'] = [];
        $prelim_top_half = array_slice($teams_in_prelims, 0, $num_prelim_matches);
        $prelim_bottom_half = array_reverse(array_slice($teams_in_prelims, $num_prelim_matches));
        for ($i = 0; $i < $num_prelim_matches; $i++) {
            $bracket_structure['prelim'][] = [
                'home' => $prelim_top_half[$i],
                'away' => $prelim_bottom_half[$i],
                'match_number' => $match_counter
            ];
            $prelim_winners_placeholders[] = ['name' => "Winner of Match " . $match_counter, 'is_placeholder' => true];
            $match_counter++;
        }
    }

    // --- 3. HYBRID SEEDING LOGIC ---
    if ($num_byes > $num_prelim_matches) {
        $slots = array_fill(0, $main_bracket_size, null);
        $byes_to_place = $teams_with_byes;
        $winners_to_place = $prelim_winners_placeholders;
        if (!empty($byes_to_place)) $slots[0] = array_shift($byes_to_place);
        if (!empty($winners_to_place)) $slots[1] = array_shift($winners_to_place);
        if ($main_bracket_size > 2) {
            if (!empty($byes_to_place)) $slots[$main_bracket_size / 2] = array_shift($byes_to_place);
            if (!empty($winners_to_place)) $slots[$main_bracket_size / 2 + 1] = array_shift($winners_to_place);
        }
        $remaining_byes_seeded = createBalancedPairings($byes_to_place);
        for ($i = 0; $i < $main_bracket_size; $i++) {
            if (is_null($slots[$i]) && !empty($remaining_byes_seeded)) {
                $slots[$i] = array_shift($remaining_byes_seeded);
            }
        }
        $main_round_participants = $slots;
    } else {
        $main_round_participants = [];
        for ($i = 0; $i < $num_byes; $i++) {
            $main_round_participants[] = $teams_with_byes[$i];
            if (isset($prelim_winners_placeholders[$i])) {
                $main_round_participants[] = $prelim_winners_placeholders[$i];
            }
        }
        $remaining_winners = array_slice($prelim_winners_placeholders, $num_byes);
        $main_round_participants = array_merge($main_round_participants, $remaining_winners);
    }
    
    // --- 4. MAIN BRACKET GENERATION ---
    $total_main_rounds = $main_bracket_size > 1 ? log($main_bracket_size, 2) : 0;
    $current_round_participants = $main_round_participants;
    for ($round_num = 1; $round_num <= $total_main_rounds; $round_num++) {
        $bracket_structure[$round_num] = [];
        for ($i = 0; $i < count($current_round_participants); $i += 2) {
            $bracket_structure[$round_num][] = [
                'home' => $current_round_participants[$i],
                'away' => $current_round_participants[$i + 1] ?? ['name' => 'TBD', 'is_placeholder' => true],
                'match_number' => $match_counter++
            ];
        }
        $next_round_size = count($bracket_structure[$round_num]);
        $current_round_participants = array_fill(0, $next_round_size, ['name' => 'TBD', 'is_placeholder' => true]);
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
        <?php if (isset($bracket_structure['prelim'])): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title">Preliminary Round</h4>
                <?php foreach ($bracket_structure['prelim'] as $match): ?>
                    <div class="bracket-match">
                        <div class="match-number">Match <?= $match['match_number'] ?></div>
                        <div class="bracket-teams">
                            <div class="bracket-team draggable" draggable="true" data-position-id="<?= $match['home']['pos'] ?>">
                                <span class="seed">(<?= htmlspecialchars($match['home']['seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($match['home']['name']) ?></span>
                            </div>
                            <div class="bracket-team draggable" draggable="true" data-position-id="<?= $match['away']['pos'] ?>">
                                <span class="seed">(<?= htmlspecialchars($match['away']['seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($match['away']['name']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php for ($r = 1; $r <= $total_main_rounds; $r++): ?>
            <div class="bracket-round">
                <h4 class="bracket-round-title"><?= getRoundName($main_bracket_size / (2 ** ($r - 1))) ?></h4>
                <?php foreach ($bracket_structure[$r] as $match): ?>
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
    $live_bracket = [];
    $third_place_match = null;

    // Fetch scores and seeds for the live bracket
    $all_games_query = $pdo->prepare("
        SELECT 
            g.id, g.round, g.round_name, g.hometeam_id, g.awayteam_id, g.winnerteam_id,
            g.hometeam_score, g.awayteam_score, g.game_status,
            ht.team_name as hometeam_name, awt.team_name as awayteam_name,
            bph.seed as hometeam_seed, bpa.seed as awayteam_seed
        FROM game g
        LEFT JOIN team ht ON g.hometeam_id = ht.id
        LEFT JOIN team awt ON g.awayteam_id = awt.id
        LEFT JOIN bracket_positions bph ON g.hometeam_id = bph.team_id AND bph.category_id = g.category_id
        LEFT JOIN bracket_positions bpa ON g.awayteam_id = bpa.team_id AND bpa.category_id = g.category_id
        WHERE g.category_id = ?
        ORDER BY g.round ASC, g.id ASC
    ");
    $all_games_query->execute([$category_id]);
    $all_games = $all_games_query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_games as $game) {
        if ($game['round_name'] === '3rd Place Match') {
            $third_place_match = $game;
            continue;
        }
        $round_key = $game['round_name'];
        if (!isset($live_bracket[$round_key])) {
            $live_bracket[$round_key] = ['name' => $game['round_name'], 'matches' => []];
        }
        $live_bracket[$round_key]['matches'][] = $game;
    }
    ?>
    <div class="bracket-wrapper">
        <div class="bracket-container">
            <?php foreach ($live_bracket as $round): ?>
                <div class="bracket-round">
                    <h4 class="bracket-round-title"><?= htmlspecialchars($round['name']) ?></h4>
                    <?php foreach ($round['matches'] as $match): ?>
                        <div class="bracket-match">
                             <div class="match-number">Match <?= array_search($match['id'], array_column($all_games, 'id')) + 1 ?></div>
                            <div class="bracket-teams">
                                <div class="bracket-team <?= ($match['winnerteam_id'] && $match['winnerteam_id'] == $match['hometeam_id']) ? 'winner' : '' ?>">
                                    <?php if ($match['hometeam_name']): ?>
                                        <span class="seed">(<?= htmlspecialchars($match['hometeam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['hometeam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['hometeam_score'] : '' ?></span>
                                    <?php else: ?>
                                        <span class="team-name">TBD</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bracket-team <?= ($match['winnerteam_id'] && $match['winnerteam_id'] == $match['awayteam_id']) ? 'winner' : '' ?>">
                                    <?php if ($match['awayteam_name']): ?>
                                        <span class="seed">(<?= htmlspecialchars($match['awayteam_seed']) ?>)</span>
                                        <span class="team-name"><?= htmlspecialchars($match['awayteam_name']) ?></span>
                                        <span class="score"><?= ($match['game_status'] == 'Final') ? $match['awayteam_score'] : '' ?></span>
                                    <?php else: ?>
                                        <span class="team-name">TBD</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($third_place_match): ?>
        <div class="third-place-container">
            <div class="bracket-round">
                 <h4 class="bracket-round-title">3rd Place Match</h4>
                 <div class="bracket-match">
                   <div class="match-number">Match <?= array_search($third_place_match['id'], array_column($all_games, 'id')) + 1 ?></div>
                    <div class="bracket-teams">
                        <div class="bracket-team <?= ($third_place_match['winnerteam_id'] && $third_place_match['winnerteam_id'] == $third_place_match['hometeam_id']) ? 'winner' : '' ?>">
                           <?php if ($third_place_match['hometeam_name']): ?>
                                <span class="seed">(<?= htmlspecialchars($third_place_match['hometeam_seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($third_place_match['hometeam_name']) ?></span>
                                <span class="score"><?= ($third_place_match['game_status'] == 'Final') ? $third_place_match['hometeam_score'] : '' ?></span>
                            <?php else: ?>
                                <span class="team-name">TBD</span>
                            <?php endif; ?>
                        </div>
                        <div class="bracket-team <?= ($third_place_match['winnerteam_id'] && $third_place_match['winnerteam_id'] == $third_place_match['awayteam_id']) ? 'winner' : '' ?>">
                           <?php if ($third_place_match['awayteam_name']): ?>
                                <span class="seed">(<?= htmlspecialchars($third_place_match['awayteam_seed']) ?>)</span>
                                <span class="team-name"><?= htmlspecialchars($third_place_match['awayteam_name']) ?></span>
                                <span class="score"><?= ($third_place_match['game_status'] == 'Final') ? $third_place_match['awayteam_score'] : '' ?></span>
                            <?php else: ?>
                                <span class="team-name">TBD</span>
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
/* --- Added styles for seed and score --- */
.bracket-team {
    display: flex;
    align-items: center;
    justify-content: space-between; /* Helps separate name from score */
}
.seed {
    font-weight: bold;
    color: #999;
    margin-right: 8px;
    flex-shrink: 0;
}
.team-name {
    flex-grow: 1; /* Allows team name to take up available space */
}
.score {
    font-weight: bold;
    color: #fff;
    margin-left: 10px;
    flex-shrink: 0;
}
.winner .score {
    color: #a1ffb6;
}

/* --- Original, stable CSS --- */
.bracket-wrapper { display: flex; flex-direction: column; align-items: flex-start; }
.bracket-container { display: flex; flex-direction: row; overflow-x: auto; background-color: #2c2c2c; padding: 20px; border-radius: 8px; color: #fff; width: 100%;}
.third-place-container { background-color: #2c2c2c; padding: 20px; border-radius: 8px; color: #fff; margin-top: 25px; }
.third-place-container .bracket-match::after, .third-place-container .bracket-match::before { display: none; } /* Hide connectors */
.bracket-round { display: flex; flex-direction: column; justify-content: space-around; flex-shrink: 0; margin-right: 50px; min-width: 200px; }
.bracket-round-title { text-align: center; color: #aaa; margin-bottom: 20px; font-weight: 600; }
.bracket-match { display: flex; flex-direction: column; justify-content: center; position: relative; margin-bottom: 40px; }
.bracket-match:last-child { margin-bottom: 0; }
.bracket-teams { background-color: #444; border-radius: 4px; border: 1px solid #666; }
.bracket-team { padding: 10px; border-bottom: 1px solid #666; font-size: 0.9em; min-height: 38px; box-sizing: border-box; display:flex; align-items:center; transition: background-color 0.2s; }
.bracket-team:last-child { border-bottom: none; }
.bracket-team.draggable { cursor: grab; }
.bracket-team.draggable:active { cursor: grabbing; }
.bracket-team.placeholder { background-color: #383838; color: #888; }
.bracket-team.winner { background-color: #3a5943; }
.bracket-team.winner .team-name { font-weight: bold; color: #a1ffb6; }
.bracket-team .team-name { display: block; pointer-events: none; }
.bracket-match::after { content: ''; position: absolute; right: -25px; top: 50%; width: 25px; height: 2px; background-color: #666; }
.bracket-match:nth-child(odd)::before { content: ''; position: absolute; right: -25px; top: 50%; width: 2px; height: calc(100% + 40px); background-color: #666; }
.bracket-match:nth-child(odd):last-child::before { display: none; }
.bracket-round:last-child .bracket-match::after,
.bracket-round:last-child .bracket-match::before { display: none; }
.bracket-round:first-child .bracket-teams { min-height: 77px; }
.match-number { position: absolute; top: -15px; left: 50%; transform: translateX(-50%); color: #ccc; font-size: 12px; background: #2c2c2c; padding: 0 4px; border-radius: 3px; }
</style>