<?php
// This file is included in 'category_tabs_standings.php'.

// --- HELPER FUNCTIONS ---
require_once 'includes/double_elim_logic.php'; // Use the shared logic file

// =================================================================================================
// --- SETUP MODE ---
// =================================================================================================
if (!$scheduleGenerated):
?>
    <?php
    // This block initializes bracket positions if they don't exist.
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
    
    // Fetch teams by their bracket position for display.
    $teams_query = $pdo->prepare("SELECT bp.position, bp.seed, bp.team_id, t.team_name FROM bracket_positions bp JOIN team t ON bp.team_id = t.id WHERE bp.category_id = ? ORDER BY bp.position ASC");
    $teams_query->execute([$category_id]);
    $results = $teams_query->fetchAll(PDO::FETCH_ASSOC);
    
    $teams_by_position = [];
    foreach ($results as $row) { 
        $teams_by_position[$row['position']] = ['id' => $row['team_id'], 'name' => $row['team_name'], 'pos' => $row['position'], 'seed' => $row['seed']]; 
    }
    $num_teams = count($teams_by_position);

    if ($num_teams < 3) {
        echo "<div class='bracket-notice error'>Double elimination requires at least 3 teams.</div>";
        return;
    }
    
    // Generate the bracket structure in memory for preview.
    $bracket_size = 2 ** ceil(log($num_teams, 2));
    $bracket_data = generate_double_elimination_matches($teams_by_position);
    if (!$bracket_data) { echo "Failed to generate bracket data."; return; }

    $winners_bracket = $bracket_data['winners'];
    $losers_bracket = $bracket_data['losers'];
    $grand_final = $bracket_data['grand_final'];
    ?>
    
    <p class="bracket-notice">Drag and drop any team to swap its initial position.</p>
    <div class="tournament-wrapper">
        <div class="bracket-area">
            <div class="bracket-header">Winners Bracket</div>
            <div class="bracket-body">
                <?php foreach ($winners_bracket as $r => $round_matches): if(empty($round_matches)) continue; ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= getRoundName($bracket_size, 'winner', $r) ?></h4>
                        <?php foreach ($round_matches as $match_index => $match): ?>
                            <?php if ($match_index > 0) {
                                $num_spacers = (2 ** ($r - 1)) - 1;
                                for ($s = 0; $s < $num_spacers; $s++) echo '<div class="bracket-spacer"></div>';
                            } ?>
                            <div class="bracket-match">
                                <div class="match-number"><?= htmlspecialchars($match['match_number']) ?></div>
                                <div class="bracket-teams">
                                    <div class="bracket-team <?= !isset($match['home']['is_placeholder']) ? 'draggable' : 'placeholder' ?>" draggable="<?= !isset($match['home']['is_placeholder']) ? 'true' : 'false' ?>" data-position-id="<?= $match['home']['pos'] ?? '' ?>">
                                        <?php if (!isset($match['home']['is_placeholder'])): ?><span class="seed">(<?= htmlspecialchars($match['home']['seed'] ?? '') ?>)</span><?php endif; ?>
                                        <span class="team-name"><?= htmlspecialchars($match['home']['name'] ?? 'BYE') ?></span>
                                    </div>
                                    <div class="bracket-team <?= !isset($match['away']['is_placeholder']) ? 'draggable' : 'placeholder' ?>" draggable="<?= !isset($match['away']['is_placeholder']) ? 'true' : 'false' ?>" data-position-id="<?= $match['away']['pos'] ?? '' ?>">
                                        <?php if (!isset($match['away']['is_placeholder'])): ?><span class="seed">(<?= htmlspecialchars($match['away']['seed'] ?? '') ?>)</span><?php endif; ?>
                                        <span class="team-name"><?= htmlspecialchars($match['away']['name'] ?? 'BYE') ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bracket-area">
            <div class="bracket-header">Losers Bracket</div>
            <div class="bracket-body">
                <?php foreach ($losers_bracket as $lr => $round_matches): if(empty($round_matches)) continue; ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= getRoundName($bracket_size, 'loser', $lr) ?></h4>
                        <?php foreach ($round_matches as $match_index => $match): ?>
                            <?php if ($match_index > 0) echo '<div class="bracket-spacer-simple"></div>'; ?>
                            <div class="bracket-match">
                                <div class="match-number"><?= htmlspecialchars($match['match_number']) ?></div>
                                <div class="bracket-teams">
                                    <div class="bracket-team placeholder"><span class="team-name"><?= htmlspecialchars($match['home']['name'] ?? 'TBD') ?></span></div>
                                    <div class="bracket-team placeholder"><span class="team-name"><?= htmlspecialchars($match['away']['name'] ?? 'TBD') ?></span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bracket-area">
            <div class="bracket-header">Grand Final</div>
            <div class="bracket-body">
                <?php foreach($grand_final as $match): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($match['round_name'] ?? 'Grand Final') ?></h4>
                        <div class="bracket-match">
                            <div class="match-number"><?= htmlspecialchars($match['match_number']) ?></div>
                            <div class="bracket-teams">
                                <div class="bracket-team placeholder"><span class="team-name"><?= htmlspecialchars($match['home']['name'] ?? 'TBD') ?></span></div>
                                <div class="bracket-team placeholder"><span class="team-name"><?= htmlspecialchars($match['away']['name'] ?? 'TBD') ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php
else:
// =================================================================================================
// --- LIVE MODE ---
// =================================================================================================
?>
    <?php
    // Fetch all games from the database for the live bracket.
    $all_games_query = $pdo->prepare("
        SELECT g.id, g.round, g.round_name, g.bracket_type, g.hometeam_id, g.awayteam_id, g.winnerteam_id, g.hometeam_score, g.awayteam_score, g.game_status, g.winner_advances_to_game_id, g.winner_advances_to_slot, g.loser_advances_to_game_id, g.loser_advances_to_slot, ht.team_name as hometeam_name, awt.team_name as awayteam_name, bph.seed as hometeam_seed, bpa.seed as awayteam_seed
        FROM game g 
        LEFT JOIN team ht ON g.hometeam_id = ht.id LEFT JOIN team awt ON g.awayteam_id = awt.id 
        LEFT JOIN bracket_positions bph ON g.hometeam_id = bph.team_id AND bph.category_id = g.category_id 
        LEFT JOIN bracket_positions bpa ON g.awayteam_id = bpa.team_id AND bpa.category_id = g.category_id 
        WHERE g.category_id = ? ORDER BY CASE g.bracket_type WHEN 'winner' THEN 1 WHEN 'loser' THEN 2 WHEN 'grand_final' THEN 3 END, g.round ASC, g.id ASC
    ");
    $all_games_query->execute([$category_id]);
    $all_games_flat = $all_games_query->fetchAll(PDO::FETCH_ASSOC);

    // Unified "Match X" numbering for ALL games, including Grand Final.
    $matchNumberMap = [];
    $match_num = 1; // Single counter for all matches.
    foreach ($all_games_flat as $game) {
        $matchNumberMap[$game['id']] = 'Match ' . $match_num++;
    }

    // Build a map to determine placeholder text (e.g., "Winner of Match 1").
    $feederMap = [];
    foreach ($all_games_flat as $game) {
        $source_match_label = $matchNumberMap[$game['id']] ?? 'Match';
        if ($game['winner_advances_to_game_id']) {
            $feederMap[$game['winner_advances_to_game_id']][$game['winner_advances_to_slot']] = ['type' => 'Winner of', 'label' => $source_match_label];
        }
        if ($game['loser_advances_to_game_id']) {
            $feederMap[$game['loser_advances_to_game_id']][$game['loser_advances_to_slot']] = ['type' => 'Loser of', 'label' => $source_match_label];
        }
    }
    
    // Group games by bracket and round for rendering.
    $winners_games = [];
    $losers_games = [];
    $grand_final_games = [];
    foreach($all_games_flat as $game) {
        switch ($game['bracket_type']) {
            case 'winner':
                $winners_games[$game['round_name']][] = $game;
                break;
            case 'loser':
                $losers_games[$game['round_name']][] = $game;
                break;
            case 'grand_final':
                $grand_final_games[$game['round']][] = $game;
                break;
        }
    }
    ksort($grand_final_games);

    // Reusable function to render a single match in live mode.
    if (!function_exists('render_live_match')) {
        function render_live_match($match, $matchNumberMap, $feederMap) {
            echo '<div class="bracket-match">';
            echo '<div class="match-number">' . ($matchNumberMap[$match['id']] ?? 'Match') . '</div>';
            echo '<div class="bracket-teams">';
            // Home Team
            echo '<div class="bracket-team ' . (($match['winnerteam_id'] && $match['winnerteam_id'] == $match['hometeam_id']) ? 'winner' : '') . '">';
            if ($match['hometeam_name']) {
                echo '<span class="seed">(' . htmlspecialchars($match['hometeam_seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['hometeam_name']) . '</span><span class="score">' . (($match['game_status'] == 'Final') ? $match['hometeam_score'] : '') . '</span>';
            } else {
                $placeholder = 'TBD';
                if (isset($feederMap[$match['id']]['home'])) { $feed = $feederMap[$match['id']]['home']; $placeholder = $feed['type'] . ' ' . $feed['label']; }
                echo '<span class="team-name placeholder">' . $placeholder . '</span>';
            }
            echo '</div>';
            // Away Team
            echo '<div class="bracket-team ' . (($match['winnerteam_id'] && $match['winnerteam_id'] == $match['awayteam_id']) ? 'winner' : '') . '">';
            if ($match['awayteam_name']) {
                echo '<span class="seed">(' . htmlspecialchars($match['awayteam_seed']) . ')</span><span class="team-name">' . htmlspecialchars($match['awayteam_name']) . '</span><span class="score">' . (($match['game_status'] == 'Final') ? $match['awayteam_score'] : '') . '</span>';
            } else {
                $placeholder = 'TBD';
                if (isset($feederMap[$match['id']]['away'])) { $feed = $feederMap[$match['id']]['away']; $placeholder = $feed['type'] . ' ' . $feed['label']; }
                echo '<span class="team-name placeholder">' . $placeholder . '</span>';
            }
            echo '</div></div></div>';
        }
    }
    ?>
    <div class="tournament-wrapper">
         <div class="bracket-area">
            <div class="bracket-header">Winners Bracket</div>
            <div class="bracket-body">
                <?php 
                $round_num = 1;
                foreach ($winners_games as $round_name => $round_matches): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                        <?php foreach ($round_matches as $match_index => $match): ?>
                            <?php if ($match_index > 0) {
                                $spacers = (2 ** ($round_num - 1)) - 1;
                                for ($s=0; $s<$spacers; $s++) echo '<div class="bracket-spacer"></div>';
                            } ?>
                            <?php render_live_match($match, $matchNumberMap, $feederMap); ?>
                        <?php endforeach; ?>
                    </div>
                <?php $round_num++; endforeach; ?>
            </div>
        </div>
        <div class="bracket-area">
            <div class="bracket-header">Losers Bracket</div>
            <div class="bracket-body">
                <?php foreach ($losers_games as $round_name => $round_matches): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                        <?php foreach ($round_matches as $match_index => $match): ?>
                             <?php if ($match_index > 0) echo '<div class="bracket-spacer-simple"></div>'; ?>
                             <?php render_live_match($match, $matchNumberMap, $feederMap); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="bracket-area">
            <div class="bracket-header">Grand Final</div>
            <div class="bracket-body">
                <?php if (!empty($grand_final_games)): ?>
                    <?php foreach ($grand_final_games as $round_num => $round_matches): ?>
                        <div class="bracket-round">
                            <?php foreach ($round_matches as $match): ?>
                                <h4 class="bracket-round-title"><?= htmlspecialchars($match['round_name']) ?></h4>
                                <?php render_live_match($match, $matchNumberMap, $feederMap); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php
endif;
?>

<div style="margin-top: 20px;">
    <?php if (!$bracketLocked): ?>
        <form action="lock_bracket_double_elim.php" method="POST" onsubmit="return confirm('Are you sure you want to lock this bracket? Matchups cannot be changed after locking.')">
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
            <p class="bracket-notice success"><strong>Bracket is locked. You can now generate the schedule in the 'Schedule' tab.</strong></p>
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
                if (draggedElement) draggedElement.style.opacity = '1'; return;
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
.tournament-wrapper { width: 100%; font-family: sans-serif; }
.bracket-notice { margin-bottom: 15px; }
.bracket-notice.error { color: #dc3545; }
.bracket-notice.success { color: #28a745; }
.bracket-area { background-color: #2c2c2c; border-radius: 8px; margin-bottom: 25px; color: #fff; overflow-x: auto; }
.bracket-header { background-color: #383838; padding: 10px 15px; font-size: 1.5em; font-weight: bold; border-bottom: 2px solid #444; border-top-left-radius: 8px; border-top-right-radius: 8px; }
.bracket-body { display: flex; flex-direction: row; padding: 30px 20px; }
.bracket-round { display: flex; flex-direction: column; justify-content: space-around; flex-shrink: 0; margin-right: 60px; min-width: 240px; }
.bracket-round-title { text-align: center; color: #aaa; margin-bottom: 25px; font-weight: 600; }
.bracket-match { display: flex; flex-direction: column; justify-content: center; position: relative; flex-grow: 1; padding-top: 25px; }
.bracket-teams { background-color: #383838; border-radius: 4px; border: 1px solid #666; position: relative; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
.bracket-team { padding: 12px; border-bottom: 1px solid #555; font-size: 0.9em; min-height: 42px; box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; transition: background-color 0.2s ease; }
.bracket-team:last-child { border-bottom: none; }
.bracket-team.draggable { cursor: grab; }
.bracket-team.draggable:hover { background-color: #4f4f4f; }
.bracket-team.placeholder { background-color: #303030; color: #888; }
.team-name.placeholder { font-style: italic; }
.bracket-team.winner { font-weight: bold; background-color: #3a5943; }
.seed { font-weight: bold; color: #999; margin-right: 10px; }
.team-name { flex-grow: 1; }
.score { font-weight: bold; color: #fff; margin-left: 10px; }
.match-number { position: absolute; top: 5px; left: 0; width: 100%; text-align: center; color: #ccc; font-size: 12px; }
.bracket-spacer { flex-grow: 1; visibility: hidden; }
.bracket-spacer-simple { height: 40px; flex-shrink: 0; }
</style>