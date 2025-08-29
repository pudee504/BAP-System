<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

// Validate game ID
$game_id = $_GET['game_id'] ?? '';
if (!$game_id) {
    error_log("No game_id provided in start_game.php");
    die("Invalid game ID.");
}

// Fetch game settings
$stmt = $pdo->prepare("SELECT * FROM game_settings WHERE game_id = ?");
$stmt->execute([$game_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$max_team_fouls = $settings['max_team_fouls_per_qtr'] ?? 4;
$timeouts_per_half = $settings['timeouts_per_half'] ?? 2;

// Initialize game timer
$stmt = $pdo->prepare("SELECT * FROM game_timer WHERE game_id = ?");
$stmt->execute([$game_id]);
$timer = $stmt->fetch(PDO::FETCH_ASSOC);

// MODIFIED: Time is now handled in milliseconds for precision
$_SESSION['game_timers'][$game_id] = $timer ? [
    'game_clock' => (int)$timer['game_clock'], // Assuming this is already stored in ms from previous sessions
    'shot_clock' => (int)$timer['shot_clock'], // Assuming this is already stored in ms from previous sessions
    'quarter_id' => (int)$timer['quarter_id'],
    'running' => (bool)$timer['running']
] : [
    'game_clock' => 600 * 1000, // 600 seconds -> 600000 ms
    'shot_clock' => 24 * 1000, // 24 seconds -> 24000 ms
    'quarter_id' => 1,
    'running' => false
];


$quarter_id = $_SESSION['game_timers'][$game_id]['quarter_id'];
$game_clock = $_SESSION['game_timers'][$game_id]['game_clock'];
$shot_clock = $_SESSION['game_timers'][$game_id]['shot_clock'];
$running = $_SESSION['game_timers'][$game_id]['running'];

$quarter_duration_map = [
    1 => $settings['q1_duration'] ?? 600,
    2 => $settings['q2_duration'] ?? 600,
    3 => $settings['q3_duration'] ?? 600,
    4 => $settings['q4_duration'] ?? 600
];
$current_duration = $quarter_duration_map[$quarter_id] ?? 300;

// Fetch game details
$stmt = $pdo->prepare("
    SELECT g.*, t1.team_name AS home_team_name, t2.team_name AS away_team_name
    FROM game g
    LEFT JOIN team t1 ON g.hometeam_id = t1.id
    LEFT JOIN team t2 ON g.awayteam_id = t2.id
    WHERE g.id = ?
");
$stmt->execute([$game_id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    error_log("Game ID $game_id not found in start_game.php");
    die("Game not found.");
}
if (!array_key_exists('hometeam_id', $game) || !$game['hometeam_id'] || !array_key_exists('awayteam_id', $game) || !$game['awayteam_id']) {
    error_log("Invalid game teams in start_game.php: " . var_export($game, true));
    die("Invalid game: teams not assigned.");
}

// Populate player_game
$stmt = $pdo->prepare("
    INSERT IGNORE INTO player_game (player_id, game_id, team_id, jersey_number, is_playing, display_order)
    SELECT pt.player_id, ?, pt.team_id, NULL, 0, pt.player_id
    FROM player_team pt
    WHERE pt.team_id IN (?, ?)
");
$stmt->execute([$game_id, $game['hometeam_id'], $game['awayteam_id']]);

// Fetch players and stats
$player_query = "
    SELECT 
        p.id, 
        p.first_name, 
        p.last_name, 
        pg.jersey_number, 
        pg.is_playing, 
        pg.display_order,
        COALESCE(SUM(CASE WHEN s.statistic_name = '1PM' THEN gs.value ELSE 0 END), 0) AS '1PM',
        COALESCE(SUM(CASE WHEN s.statistic_name = '2PM' THEN gs.value ELSE 0 END), 0) AS '2PM',
        COALESCE(SUM(CASE WHEN s.statistic_name = '3PM' THEN gs.value ELSE 0 END), 0) AS '3PM',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'FOUL' THEN gs.value ELSE 0 END), 0) AS 'FOUL',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'REB' THEN gs.value ELSE 0 END), 0) AS 'REB',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'AST' THEN gs.value ELSE 0 END), 0) AS 'AST',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'BLK' THEN gs.value ELSE 0 END), 0) AS 'BLK',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'STL' THEN gs.value ELSE 0 END), 0) AS 'STL',
        COALESCE(SUM(CASE WHEN s.statistic_name = 'TO' THEN gs.value ELSE 0 END), 0) AS 'TO'
    FROM player p
    JOIN player_team pt ON p.id = pt.player_id
    LEFT JOIN player_game pg ON p.id = pg.player_id AND pg.game_id = ? AND pg.team_id = ?
    LEFT JOIN game_statistic gs ON pg.player_id = gs.player_id AND pg.game_id = gs.game_id AND pg.team_id = gs.team_id
    LEFT JOIN statistic s ON gs.statistic_id = s.id
    WHERE pt.team_id = ?
    GROUP BY p.id, p.first_name, p.last_name, pg.jersey_number, pg.is_playing, pg.display_order
    ORDER BY pg.display_order ASC
";
$stmt = $pdo->prepare($player_query);
$stmt->execute([$game_id, $game['hometeam_id'], $game['hometeam_id']]);
$home_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->execute([$game_id, $game['awayteam_id'], $game['awayteam_id']]);
$away_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update game status if not finalized
$pdo->prepare("UPDATE game SET game_status = 'Active' WHERE id = ? AND game_status != 'Completed'")->execute([$game_id]);


// Add this after you fetch player data
$log_stmt = $pdo->prepare("SELECT * FROM game_log WHERE game_id = ? ORDER BY id ASC");
$log_stmt->execute([$game_id]);
$game_logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function getCurrentHalf($quarter) { return $quarter <= 2 ? 1 : ($quarter <= 4 ? 2 : 3); }
function getInitialTimeouts($half, $overtimeCount = 0) {
    if ($half === 1) return 2;
    if ($half === 2) return 3;
    return 1;
}
function loadTimeouts($pdo, $game_id, $team_id, $half, $overtimeCount) {
    $initial = getInitialTimeouts($half, $overtimeCount);
    $stmt = $pdo->prepare("
        INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remaining_timeouts = VALUES(remaining_timeouts)
    ");
    $stmt->execute([$game_id, $team_id, $half, $initial]);
    return $initial;
}
function safeLoadTimeouts($pdo, $game_id, $team_id, $half) {
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
    $stmt->execute([$game_id, $team_id, $half]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['remaining_timeouts'] : null;
}
function loadTeamFouls(PDO $pdo, $game_id, $team_id, $quarter) {
    $stmt = $pdo->prepare("SELECT fouls FROM game_team_fouls WHERE game_id = ? AND team_id = ? AND quarter = ?");
    $stmt->execute([$game_id, $team_id, $quarter]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['fouls'] : 0;
}

// Initialize timeouts and fouls
$current_half = getCurrentHalf($quarter_id);
$overtime_count = max(0, $quarter_id - 4);

$timeoutsA = safeLoadTimeouts($pdo, $game_id, $game['hometeam_id'], $current_half);
if ($timeoutsA === null) {
    $timeoutsA = getInitialTimeouts($current_half, $overtime_count);
    $insert = $pdo->prepare("INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts) VALUES (?, ?, ?, ?)");
    $insert->execute([$game_id, $game['hometeam_id'], $current_half, $timeoutsA]);
}

$timeoutsB = safeLoadTimeouts($pdo, $game_id, $game['awayteam_id'], $current_half);
if ($timeoutsB === null) {
    $timeoutsB = getInitialTimeouts($current_half, $overtime_count);
    $insert = $pdo->prepare("INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts) VALUES (?, ?, ?, ?)");
    $insert->execute([$game_id, $game['awayteam_id'], $current_half, $timeoutsB]);
}

$foulsA = loadTeamFouls($pdo, $game_id, $game['hometeam_id'], $quarter_id);
$foulsB = loadTeamFouls($pdo, $game_id, $game['awayteam_id'], $quarter_id);
$winnerteam_id = $game['winnerteam_id'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basketball Game Box Score - Game #<?php echo htmlspecialchars($game_id); ?></title>
    <link rel="stylesheet" href="/league_management_system/game.css">
</head>
<body>
    <div class="score-display">
        <div class="team-name-box left" id="home-box">
            <span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['hometeam_id']) ? 'visibility:visible;' : 'visibility:hidden;'; ?>">
                (Winner)
            </span>
            <span class="team-name"><?php echo htmlspecialchars($game['home_team_name']); ?></span>
        </div>
        <span class="score" id="scoreA"><?php echo $game['hometeam_score']; ?></span>
        <span class="separator">—</span>
        <span class="score" id="scoreB"><?php echo $game['awayteam_score']; ?></span>
        <div class="team-name-box right" id="away-box">
            <span class="team-name"><?php echo htmlspecialchars($game['away_team_name']); ?></span>
            <span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['awayteam_id']) ? 'visibility:visible;' : 'visibility:hidden;'; ?>">
                (Winner)
            </span>
        </div>
    </div>

    <div class="timer-panel">
        <div class="quarter" id="quarterLabel">1st Quarter</div>
        <div class="timers">
            <div class="clock-control">
                <div class="adjust-buttons">
                    <button onclick="adjustGameClockMinute(1)">+</button>
                    <button onclick="adjustGameClockMinute(-1)">−</button>
                </div>
                <div class="game-clock" id="gameClock">10:00</div>
                <div class="adjust-buttons">
                    <button onclick="adjustGameClock(1)">+</button>
                    <button onclick="adjustGameClock(-1)">−</button>
                </div>
            </div>
            <div class="clock-control">
                <div class="shot-clock" id="shotClock">24</div>
                <div class="adjust-buttons">
                    <button onclick="adjustShotClock(1)">+</button>
                    <button onclick="adjustShotClock(-1)">−</button>
                </div>
            </div>
        </div>
        <div style="margin-top: 10px; text-align: center;">
            <button id="toggleClockBtn" onclick="toggleClocks()">Start</button>
        </div>
    </div>

    <div style="margin-top: 10px; text-align: center;">
        <button onclick="offensiveRebound()">Same Possession</button>
        <button onclick="resetShotClock(false)">Change Possession</button>
        <button id="nextQuarterBtn" onclick="nextQuarter()" disabled>Next Quarter</button>
        <button id="finalizeGameBtn" onclick="finalizeGame()" style="display:none;">Finalize Game</button>
    </div>

    <div class="teams-container">
        <div class="team-box" id="teamA">
            <div class="score-line">
                <span>Timeouts: 
                    <button id="timeoutsA" class="timeout-click" data-team="A" <?php if ($timeoutsA <= 0) echo 'disabled'; ?>>
                        <?php echo $timeoutsA; ?>
                    </button>
                </span>
                <span>Team Fouls: <span id="foulsA"><?php echo $foulsA; ?></span> <strong id="bonus-teamA" style="color: red; display: none;">Bonus</strong></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">In</th>
                        <th style="width: 10px;">#</th>
                        <th style="width: 160px;">Name</th>
                        <th>1PM</th><th>2PM</th><th>3PM</th><th>FOUL</th>
                        <th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th>
                    </tr>
                </thead>
                <tbody id="teamA-players"></tbody>
            </table>
        </div>
        <div class="team-box" id="teamB">
            <div class="score-line">
                <span>Timeouts: 
                    <button id="timeoutsB" class="timeout-click" data-team="B" <?php if ($timeoutsB <= 0) echo 'disabled'; ?>>
                        <?php echo $timeoutsB; ?>
                    </button>
                </span>
                <span>Team Fouls: <span id="foulsB"><?php echo $foulsB; ?></span> <strong id="bonus-teamB" style="color: red; display: none;">Bonus</strong></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">In</th>
                        <th style="width: 50px;">#</th>
                        <th>Name</th>
                        <th>1PM</th><th>2PM</th><th>3PM</th><th>FOUL</th>
                        <th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th>
                    </tr>
                </thead>
                <tbody id="teamB-players"></tbody>
            </table>
        </div>
    </div>

    <div style="margin-top: 20px; text-align: center;">
        <button onclick="showOverridePanel()">Override Result</button>
    </div>

    <div id="overridePanel" style="display: none; text-align: center; margin-top: 10px;">
        <label>Select Winner:
            <select id="winnerSelect">
                <option value="">-- Select --</option>
                <option value="A"><?php echo htmlspecialchars($game['home_team_name']); ?></option>
                <option value="B"><?php echo htmlspecialchars($game['away_team_name']); ?></option>
                <option value="none">None</option>
            </select>
        </label>
        <button onclick="saveWinner()">Save Winner</button>
    </div>

    <div class="game-log-container">
    <h2>Game Log</h2>
    <ul id="gameLogList">
        </ul>
</div>

    <script>
        const gameData = {
            gameId: <?php echo json_encode($game_id); ?>,
            teamA: { id: <?php echo json_encode($game['hometeam_id']); ?>, name: <?php echo json_encode($game['home_team_name']); ?>, players: <?php echo json_encode($home_players); ?> },
            teamB: { id: <?php echo json_encode($game['awayteam_id']); ?>, name: <?php echo json_encode($game['away_team_name']); ?>, players: <?php echo json_encode($away_players); ?> },
            winnerTeamId: <?php echo json_encode($game['winnerteam_id'] ?? null); ?>,
            gameStatus: <?php echo json_encode($game['game_status'] ?? 'Active'); ?>,
            logs: <?php echo json_encode($game_logs); ?>
        };

        const playerStats = {
            teamA: gameData.teamA.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order ?? 999999, stats: { '1PM': Number(p['1PM']) || 0, '2PM': Number(p['2PM']) || 0, '3PM': Number(p['3PM']) || 0, 'FOUL': Number(p['FOUL']) || 0, 'REB': Number(p['REB']) || 0, 'AST': Number(p['AST']) || 0, 'BLK': Number(p['BLK']) || 0, 'STL': Number(p['STL']) || 0, 'TO': Number(p['TO']) || 0 } })),
            teamB: gameData.teamB.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order ?? 999999, stats: { '1PM': Number(p['1PM']) || 0, '2PM': Number(p['2PM']) || 0, '3PM': Number(p['3PM']) || 0, 'FOUL': Number(p['FOUL']) || 0, 'REB': Number(p['REB']) || 0, 'AST': Number(p['AST']) || 0, 'BLK': Number(p['BLK']) || 0, 'STL': Number(p['STL']) || 0, 'TO': Number(p['TO']) || 0 } }))
        };

        const inGameCounts = {
            teamA: playerStats.teamA.filter(p => p.isPlaying).length,
            teamB: playerStats.teamB.filter(p => p.isPlaying).length
        };

        let teamFouls = {
            teamA: <?php echo json_encode($foulsA); ?>,
            teamB: <?php echo json_encode($foulsB); ?>
        };

        // MODIFIED: Variables now hold milliseconds
        let gameClock = <?php echo $game_clock; ?>;
        let shotClock = <?php echo $shot_clock; ?>;
        let quarter = <?php echo $quarter_id; ?>;
        let clocksRunning = <?php echo json_encode($running); ?>;
        let gameClockInterval = null;
        let shotClockInterval = null;

        function calculatePoints(stats) { return stats['1PM'] * 1 + stats['2PM'] * 2 + stats['3PM'] * 3; }

        function updateRunningScore(teamId) {
            const players = playerStats[teamId];
            const total = players.reduce((sum, p) => sum + calculatePoints(p.stats), 0);
            document.getElementById(teamId === 'teamA' ? 'scoreA' : 'scoreB').textContent = total;
            const scoreA = parseInt(document.getElementById('scoreA').textContent) || 0;
            const scoreB = parseInt(document.getElementById('scoreB').textContent) || 0;
            fetch('update_game_scores.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, hometeam_score: scoreA, awayteam_score: scoreB })
            }).then(res => res.json()).then(data => { if (!data.success) { console.error('Failed to update scores:', data.error); } }).catch(error => console.error('Error updating scores:', error));
            updateClocksUI();
        }

        function renderTeam(teamId) {
            const tbody = document.getElementById(`${teamId}-players`);
            tbody.innerHTML = '';
            playerStats[teamId].forEach((player, idx) => {
                const tr = document.createElement('tr');
                const stats = player.stats;
                const totalPts = calculatePoints(stats);
                const isFouledOut = stats['FOUL'] >= 5;
                tr.innerHTML = `
                    <td><input type="checkbox" class="in-game-checkbox" ${player.isPlaying ? 'checked' : ''} onchange="togglePlayer('${teamId}', ${idx}, this.checked)" ${gameData.gameStatus === 'Final' ? 'disabled' : ''}></td>
                    <td><input type="text" class="jersey-input" value="${player.jersey}" onchange="updateJersey('${teamId}', ${idx}, this.value)" ${gameData.gameStatus === 'Final' ? 'disabled' : ''}></td>
                    <td class="name-cell">${player.name}</td>
                    ${['1PM','2PM','3PM','FOUL','REB','AST','BLK','STL','TO'].map(stat => `
                        <td class="stat-cell" onmouseover="showButtons(this)" onmouseleave="hideButtons(this)">
                            <span style="${stat === 'FOUL' && isFouledOut ? 'color: red;' : ''}">${stats[stat]}</span>
                            <span class="stat-controls" style="display: none;">
                                <button onclick="updateStat('${teamId}', ${idx}, '${stat}', 1)" ${stat === 'FOUL' && isFouledOut || gameData.gameStatus === 'Final' ? 'disabled' : ''}>+</button>
                                <button onclick="updateStat('${teamId}', ${idx}, '${stat}', -1)" ${stat === 'FOUL' && isFouledOut || gameData.gameStatus === 'Final' ? 'disabled' : ''}>-</button>
                            </span>
                        </td>`).join('')}
                    <td id="pts-${teamId}-${idx}">${totalPts}</td>
                `;
                tbody.appendChild(tr);
            });
            updateRunningScore(teamId);
        }

        function updatePlayerOrder(teamId) {
            const players = playerStats[teamId];
            players.forEach((player, idx) => { player.displayOrder = idx; });
            fetch('update_player_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, order: players.map(p => p.id) })
            }).then(response => response.json()).then(data => { if (!data.success) { console.error(`Failed to update order for ${teamId}:`, data.error); } }).catch(error => console.error(`Error updating order for ${teamId}:`, error));
        }

        function togglePlayer(teamId, playerIdx, isChecked) {
            if (gameData.gameStatus === 'Final') return;
            const players = playerStats[teamId];
            const player = players[playerIdx];
            const inGamePlayers = players.filter(p => p.isPlaying);
            const totalInGame = inGamePlayers.length;
            if (isChecked && totalInGame >= 5) {
                document.querySelector(`#${teamId}-players tr:nth-child(${playerIdx + 1}) .in-game-checkbox`).checked = false;
                alert(`Only 5 players can be in the game for ${gameData[teamId].name}.`);
                return;
            }
            player.isPlaying = isChecked ? 1 : 0;
            const reordered = [ ...players.filter(p => p.isPlaying), ...players.filter(p => !p.isPlaying) ];
            playerStats[teamId] = reordered;
            fetch('update_player_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, player_id: player.id, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, is_playing: player.isPlaying })
            }).then(response => response.json()).then(data => { if (!data.success) { console.error(`Failed to update status for ${player.name}:`, data.error); } }).catch(error => console.error(`Error updating status for ${player.name}:`, error));
            updatePlayerOrder(teamId);
            renderTeam(teamId);
        }

        function updateJersey(teamId, playerIdx, jerseyNumber) {
            if (gameData.gameStatus === 'Final') return;
            const player = playerStats[teamId][playerIdx];
            player.jersey = jerseyNumber;
            fetch('update_jersey_number.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, player_id: player.id, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, jersey_number: jerseyNumber })
            }).then(res => res.json()).then(data => { if (!data.success) { console.error(`Failed to update jersey for ${player.name}:`, data.error); } }).catch(err => console.error(`Error updating jersey for ${player.name}:`, err));
        }

        function updateBonusUI(teamId) {
            const fouls = teamFouls[teamId];
            const bonusEl = document.getElementById(`bonus-${teamId}`);
            bonusEl.style.display = fouls >= 5 ? 'inline' : 'none';
        }

        function updateStat(teamId, playerIdx, stat, delta) {
            if (gameData.gameStatus === 'Final') return;
            const player = playerStats[teamId][playerIdx];
            const action = delta > 0 ? 'Add' : 'Remove';
            if (!window.confirm(`${action} ${stat} ${delta > 0 ? 'to' : 'from'} ${player.name}?`)) return;
            if (stat === 'FOUL' && delta > 0 && player.stats['FOUL'] >= 5) {
                alert(`${player.name} has already fouled out (5 fouls).`);
                return;
            }
            player.stats[stat] = Math.max(0, player.stats[stat] + delta);
            if (stat === 'FOUL') {
                teamFouls[teamId] = Math.max(0, teamFouls[teamId] + delta);
                document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                updateBonusUI(teamId);
                fetch('update_team_fouls.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, quarter: quarter, fouls: teamFouls[teamId] })
                }).then(res => res.json()).then(data => { if (!data.success) { console.error(`Failed to update team fouls for ${teamId}`, data.error); } }).catch(console.error);
            }
            fetch('update_stat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, player_id: player.id, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, statistic_name: stat, value: delta })
            }).then(response => response.json()).then(data => {
                if (!data.success) { console.error(`Failed to update stat for ${player.name}:`, data.error);
         }   else {
            if (delta > 0) {
                logStatChange(player, teamId, stat);
            }
         }
                renderTeam(teamId);
            }).catch(error => console.error(`Error updating stat for ${player.name}:`, error));
        }

        function showButtons(cell) {
            if (gameData.gameStatus === 'Final') return;
            const btns = cell.querySelector('.stat-controls');
            if (btns) btns.style.display = 'inline-flex';
        }

        function hideButtons(cell) {
            const btns = cell.querySelector('.stat-controls');
            if (btns) btns.style.display = 'none';
        }

        // --- NEW: Formatting functions for milliseconds ---
        function formatGameTime(ms) {
            if (ms <= 0) return "00:00.0";
            const totalSeconds = Math.floor(ms / 1000);
            const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            if (totalSeconds < 60) {
                const tenths = Math.floor((ms % 1000) / 100).toString();
                return `${minutes}:${seconds}.${tenths}`;
            }
            return `${minutes}:${seconds}`;
        }

        function formatShotTime(ms) {
            if (ms <= 0) return "0.0";
            const seconds = Math.floor(ms / 1000);
            const tenths = Math.floor((ms % 1000) / 100);
            return `${seconds}.${tenths}`;
        }

        // MODIFIED: Uses the new formatting functions
        function updateClocksUI() {
            document.getElementById('gameClock').textContent = formatGameTime(gameClock);
            document.getElementById('shotClock').textContent = formatShotTime(shotClock);
            document.getElementById('quarterLabel').textContent = quarter <= 4 ? 
                ['1st', '2nd', '3rd', '4th'][quarter - 1] + ' Quarter' : `Overtime ${quarter - 4}`;
            const nextBtn = document.getElementById('nextQuarterBtn');
            const finalizeBtn = document.getElementById('finalizeGameBtn');
            if (!nextBtn || !finalizeBtn) {
                console.error('Buttons not found:', { nextBtn, finalizeBtn });
                return;
            }
            if (gameData.gameStatus === 'Final' || gameData.winnerTeamId) {
                nextBtn.disabled = true;
                finalizeBtn.style.display = 'none';
                return;
            }
            if (gameClock <= 0) {
                const scoreA = parseInt(document.getElementById('scoreA').textContent) || 0;
                const scoreB = parseInt(document.getElementById('scoreB').textContent) || 0;
                if (quarter < 4) {
                    nextBtn.disabled = false;
                    finalizeBtn.style.display = 'none';
                } else {
                    nextBtn.disabled = scoreA === scoreB ? false : true;
                    finalizeBtn.style.display = scoreA === scoreB ? 'none' : 'inline-block';
                }
            } else {
                nextBtn.disabled = true;
                finalizeBtn.style.display = 'none';
            }
        }

        function saveTimerState() {
            fetch('save_timer_state.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, game_clock: gameClock, shot_clock: shotClock, quarter_id: quarter, running: clocksRunning })
            }).then(res => res.json()).then(data => { if (!data.success) { console.error('Failed to save timer state:', data.error); } }).catch(error => console.error('Error saving timer state:', error));
        }

        // MODIFIED: Your original setInterval engine, but adapted for milliseconds
        function startClocks() {
            if (gameData.gameStatus === 'Final' || clocksRunning) return;
            const interval = 100; // Update every 100ms for tenths of a second

            if (!gameClockInterval) {
                gameClockInterval = setInterval(() => {
                    if (gameClock > 0) { gameClock -= interval; } else { gameClock = 0; }
                    updateClocksUI();
                    if (gameClock % 1000 === 0) saveTimerState();
                }, interval);
            }
            if (!shotClockInterval) {
                shotClockInterval = setInterval(() => {
                    if (shotClock > 0) { shotClock -= interval; } else { shotClock = 0; }
                    updateClocksUI();
                }, interval);
            }
            clocksRunning = true;
            document.getElementById('toggleClockBtn').textContent = 'Pause';
            saveTimerState();
        }

        function pauseClocks() {
            clearInterval(gameClockInterval);
            clearInterval(shotClockInterval);
            gameClockInterval = null;
            shotClockInterval = null;
            clocksRunning = false;
            document.getElementById('toggleClockBtn').textContent = 'Start';
            saveTimerState();
        }

        function toggleClocks() {
            if (clocksRunning) {
                pauseClocks();
            } else {
                startClocks();
            }
        }

        // MODIFIED: Clock adjustment functions now work with milliseconds
        function resetShotClock(isOffensiveRebound = false) {
            if (gameData.gameStatus === 'Final') return;
            const maxShot = (isOffensiveRebound ? 14 : 24) * 1000;
            shotClock = Math.min(gameClock, maxShot);
            updateClocksUI();
            saveTimerState();
        }

        function offensiveRebound() {
            resetShotClock(true);
        }

        function nextQuarter() {
            if (gameData.gameStatus === 'Final') return;
            quarter++;
            gameClock = (quarter <= 4 ? 600 : 300) * 1000;
            resetShotClock(false);
            updateClocksUI();
            saveTimerState();
            teamFouls.teamA = 0;
            teamFouls.teamB = 0;
            document.getElementById('foulsA').textContent = 0;
            document.getElementById('foulsB').textContent = 0;
            document.getElementById('bonus-teamA').style.display = 'none';
            document.getElementById('bonus-teamB').style.display = 'none';
            ['teamA', 'teamB'].forEach(teamId => {
                fetch('update_team_fouls.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameData.gameId, team_id: gameData[teamId].id, quarter: quarter, fouls: 0 })
                }).then(res => res.json()).then(data => { if (!data.success) { console.error(`Failed to update team fouls for ${teamId}:`, data.error); } }).catch(error => console.error(`Error updating team fouls for ${teamId}:`, error));
            });
            const half = quarter <= 2 ? 1 : (quarter <= 4 ? 2 : 3);
            const overtime = Math.max(0, quarter - 4);
            ['A', 'B'].forEach(teamKey => {
                const teamId = teamKey === 'A' ? gameData.teamA.id : gameData.teamB.id;
                const btn = document.getElementById(`timeouts${teamKey}`);
                fetch('load_timeouts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId, half: half, overtime: overtime })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        btn.textContent = data.remaining;
                        btn.disabled = data.remaining <= 0;
                    }
                }).catch(error => console.error(`Error loading timeouts for team ${teamKey}:`, error));
            });
        }

        function adjustGameClock(deltaInSeconds) {
            if (clocksRunning || gameData.gameStatus === 'Final') return;
            const maxTime = (quarter <= 4 ? 600 : 300) * 1000;
            gameClock = Math.max(0, Math.min(gameClock + (deltaInSeconds * 1000), maxTime));
            if (shotClock > gameClock) {
                shotClock = gameClock;
            }
            updateClocksUI();
            saveTimerState();
        }

        function adjustGameClockMinute(deltaInMinutes) {
            adjustGameClock(deltaInMinutes * 60);
        }

        function adjustShotClock(deltaInSeconds) {
            if (clocksRunning || gameData.gameStatus === 'Final') return;
            const maxShot = 24 * 1000;
            shotClock = Math.max(0, Math.min(shotClock + (deltaInSeconds * 1000), Math.min(maxShot, gameClock)));
            updateClocksUI();
            saveTimerState();
        }

        function finalizeGame() {
            if (gameData.gameStatus === 'Final') return;
            const scoreA = parseInt(document.getElementById('scoreA').textContent) || 0;
            const scoreB = parseInt(document.getElementById('scoreB').textContent) || 0;
            if (scoreA === scoreB) {
                alert("Cannot finalize: scores are tied.");
                return;
            }
            const winnerTeam = scoreA > scoreB ? gameData.teamA : gameData.teamB;
            if (!confirm(`Confirm ${winnerTeam.name} as winner?`)) return;
            fetch('save_winner.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ game_id: gameData.gameId, winnerteam_id: winnerTeam.id })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { throw new Error('Failed to save winner: ' + data.error); }
                gameData.winnerTeamId = winnerTeam.id;
                return fetch('finalize_game.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameData.gameId })
                });
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { throw new Error('Failed to finalize game: ' + data.error); }
                gameData.gameStatus = 'Final';
                alert("Game finalized! Winner: " + winnerTeam.name);
                displayWinner(winnerTeam.id);
                updateClocksUI();
            })
            .catch(err => {
                console.error("Error finalizing game:", err);
                alert("An error occurred while finalizing the game.");
            });
        }

        function showOverridePanel() {
            if (gameData.gameStatus === 'Final') return;
            document.getElementById('overridePanel').style.display = 'block';
        }

        function saveWinner() {
            if (gameData.gameStatus === 'Final') return;
            const selected = document.getElementById('winnerSelect').value;
            if (!selected) {
                alert("Please select a winner.");
                return;
            }
            if (selected === 'none') {
                if (!confirm("Are you sure you want to unset the winner?")) return;
            }
            const winnerTeam = selected === 'A' ? gameData.teamA :
                                selected === 'B' ? gameData.teamB : null;
            fetch('save_winner.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    game_id: gameData.gameId,
                    winnerteam_id: winnerTeam ? winnerTeam.id : null
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(selected === 'none' ? "Winner unset!" : "Winner saved!");
                    gameData.winnerTeamId = winnerTeam ? winnerTeam.id : null;
                    displayWinner(winnerTeam ? winnerTeam.id : null);
                    updateClocksUI();
                } else {
                    alert("Failed to save winner: " + (data.error || 'Unknown error.'));
                }
            })
            .catch(err => {
                console.error("Error overriding winner:", err);
                alert("An error occurred.");
            });
        }

        function displayWinner(winnerId) {
            document.querySelectorAll('.winner-label').forEach(el => el.style.visibility = 'hidden');
            if (winnerId) {
                if (winnerId == gameData.teamA.id) {
                    document.querySelector('#home-box .winner-label').style.visibility = 'visible';
                } else if (winnerId == gameData.teamB.id) {
                    document.querySelector('#away-box .winner-label').style.visibility = 'visible';
                }
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            updateClocksUI();
            if (clocksRunning && gameData.gameStatus !== 'Final') {
                startClocks();
            }
            renderTeam('teamA');
            renderTeam('teamB');
            updateBonusUI('teamA');
            updateBonusUI('teamB');
            renderInitialLog(); 
            if (gameData.winnerTeamId) {
                displayWinner(gameData.winnerTeamId);
            }
            document.querySelectorAll('.timeout-click').forEach(el => {
                el.style.cursor = 'pointer';
                el.addEventListener('click', async () => {
                    if (gameData.gameStatus === 'Final') return;
                    const teamKey = el.dataset.team;
                    const teamId = teamKey === 'A' ? gameData.teamA.id : gameData.teamB.id;
                    if (!confirm(`Use a timeout for ${gameData[teamKey === 'A' ? 'teamA' : 'teamB'].name}?`)) return;
                    const response = await fetch('use_timeout.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            game_id: gameData.gameId,
                            team_id: teamId,
                            half: quarter <= 2 ? 1 : (quarter <= 4 ? 2 : 3)
                        })
                    });
                    const result = await response.json();
                    if (result.success) {
                        logTimeoutAction(teamKey);
                        el.textContent = result.remaining;
                        if (result.remaining <= 0) {
                            el.disabled = true;
                        }
                    }
                });
            });
        });

async function undoAction(logId) {
    if (!confirm("Are you sure you want to undo this action?")) return;

    try {
        const response = await fetch('undo_specific_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ log_id: logId })
        });
        const result = await response.json();

        if (result.success) {
            // Re-render the single log item with its new "undone" state
            renderLogEntry(result.log_entry);
            // Reloading is the simplest way to ensure all stats and scores are accurate.
            alert("Action undone. Page will now refresh to update scores.");
            location.reload(); 
        } else {
            alert("Failed to undo action: " + result.error);
        }
    } catch (error) {
        console.error('Undo error:', error);
        alert("An error occurred while undoing the action.");
    }
}

async function redoAction(logId) {
    if (!confirm("Are you sure you want to redo this action?")) return;

    try {
        const response = await fetch('redo_specific_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ log_id: logId })
        });
        const result = await response.json();

        if (result.success) {
            // Re-render the single log item with its new "active" state
            renderLogEntry(result.log_entry);
            // Reloading is the simplest way to ensure all stats and scores are accurate.
            alert("Action redone. Page will now refresh to update scores.");
            location.reload();
        } else {
            alert("Failed to redo action: " + result.error);
        }
    } catch (error) {
        console.error('Redo error:', error);
        alert("An error occurred while redoing the action.");
    }
}

// Add these new functions
function renderLogEntry(log) {
    const logList = document.getElementById('gameLogList');
    // Find existing list item or create a new one
    let li = document.getElementById(`log-${log.id}`);
    if (!li) {
        li = document.createElement('li');
        li.id = `log-${log.id}`;
        logList.insertBefore(li, logList.firstChild);
    }

    const time = formatGameTime(log.game_clock_ms);

    // Clear previous content
    li.innerHTML = ''; 

    const textSpan = document.createElement('span');
    textSpan.textContent = `[Q${log.quarter} ${time}] ${log.action_details}`;

    let button;
    if (log.is_undone == 1) {
        // If undone, show a "Redo" button and style the text
        li.style.textDecoration = 'line-through';
        li.style.color = '#888';
        button = document.createElement('button');
        button.textContent = 'Redo';
        button.onclick = () => redoAction(log.id);
    } else {
        // Otherwise, show an "Undo" button
        li.style.textDecoration = 'none';
        li.style.color = 'inherit';
        button = document.createElement('button');
        button.textContent = 'Undo';
        button.onclick = () => undoAction(log.id);
    }

    li.appendChild(textSpan);
    li.appendChild(button);
}

function renderInitialLog() {
    // Clear any existing log items
    document.getElementById('gameLogList').innerHTML = ''; 
    // Render logs fetched from PHP (newest first is handled by the loop)
    gameData.logs.forEach(log => renderLogEntry(log));
}

function logStatChange(player, teamId, stat) {
    // This map creates more descriptive log messages
    const statDescriptions = {
        '1PM': 'made a 1-Point Shot',
        '2PM': 'made a 2-Point Shot',
        '3PM': 'made a 3-Point Shot',
        'FOUL': 'committed a Foul',
        'REB': 'got a Rebound',
        'AST': 'recorded an Assist',
        'BLK': 'recorded a Block',
        'STL': 'recorded a Steal',
        'TO': 'committed a Turnover'
    };
    
    const description = statDescriptions[stat] || `recorded a ${stat}`;
    const team = teamId === 'teamA' ? gameData.teamA : gameData.teamB;
    const details = `${player.name} (${team.name}) ${description}.`;

    const logData = {
        game_id: gameData.gameId,
        player_id: player.id,
        team_id: team.id,
        quarter: quarter,
        game_clock: gameClock, // gameClock is in ms
        action_type: stat,
        action_details: details
    };

    fetch('log_game_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(logData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // THE FIX for NaN:NaN is here. We ensure the time property is named correctly.
            const newLog = { 
                id: data.log_id, 
                quarter: logData.quarter,
                game_clock_ms: logData.game_clock, // Use the correct property name
                action_details: logData.action_details
            };
            renderLogEntry(newLog);
        }
    });
}

// Add this new function to your script
function logTimeoutAction(teamKey) {
    const team = teamKey === 'A' ? gameData.teamA : gameData.teamB;
    const details = `${team.name} called a Timeout.`;

    const logData = {
        game_id: gameData.gameId,
        player_id: null, // No specific player for a team timeout
        team_id: team.id,
        quarter: quarter,
        game_clock: gameClock,
        action_type: 'TIMEOUT',
        action_details: details
    };

    fetch('log_game_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(logData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const newLog = { 
                id: data.log_id, 
                quarter: logData.quarter,
                game_clock_ms: logData.game_clock,
                action_details: logData.action_details,
                is_undone: 0 // New actions are never undone
            };
            renderLogEntry(newLog);
        }
    });
}

    </script>
</body>
</html>