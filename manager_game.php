<?php
session_start();
header("Content-Security-Policy: img-src 'self' data:");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// REQUIRED LIBRARIES
require_once 'db.php';
require_once 'vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// --- GAME SETUP ---
$game_id = $_GET['game_id'] ?? '';
if (!$game_id) {
    die("Invalid game ID.");
}

// Fetch game details
$stmt = $pdo->prepare("
    SELECT g.*, t1.team_name AS home_team_name, t2.team_name AS away_team_name, c.id as category_id
    FROM game g
    LEFT JOIN team t1 ON g.hometeam_id = t1.id
    LEFT JOIN team t2 ON g.awayteam_id = t2.id
    LEFT JOIN category c ON g.category_id = c.id
    WHERE g.id = ?
");
$stmt->execute([$game_id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    die("Game not found.");
}

// --- CRITICAL SAFEGUARD ---
$teams_are_set = !empty($game['hometeam_id']) && !empty($game['awayteam_id']);

// Helper functions can be defined globally
function loadTeamFouls(PDO $pdo, $game_id, $team_id, $quarter) {
    $stmt = $pdo->prepare("SELECT fouls FROM game_team_fouls WHERE game_id = ? AND team_id = ? AND quarter = ?");
    $stmt->execute([$game_id, $team_id, $quarter]);
    return (int)($stmt->fetchColumn() ?? 0);
}

// MODIFIED FUNCTION: Determines default timeouts based on the game period.
function loadTimeouts(PDO $pdo, $game_id, $team_id, $period) {
    // The 'half' column in the DB is used to store our new period identifier.
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
    $stmt->execute([$game_id, $team_id, $period]);
    $result = $stmt->fetchColumn();

    if ($result !== false) {
        return (int)$result;
    }

    // If no record exists, return the default number of timeouts for the period.
    if ($period == 1) {
        return 2; // 2 timeouts for the First Half
    } elseif ($period == 2) {
        return 3; // 3 timeouts for the Second Half
    } else {
        return 1; // 1 timeout for each Overtime period
    }
}


// Only prepare ALL game data if teams are set
if ($teams_are_set) {
    // This ensures a record exists for each player in this specific game.
    $prep_stmt = $pdo->prepare("
        INSERT IGNORE INTO player_game (player_id, game_id, team_id, display_order)
        SELECT pt.player_id, ?, pt.team_id, pt.player_id
        FROM player_team pt
        WHERE pt.team_id IN (?, ?)
    ");
    $prep_stmt->execute([$game_id, $game['hometeam_id'], $game['awayteam_id']]);

    // --- STATS SETUP (FOULS & TIMEOUTS) ---
    $timer_stmt = $pdo->prepare("SELECT quarter_id FROM game_timer WHERE game_id = ?");
    $timer_stmt->execute([$game_id]);
    $timer = $timer_stmt->fetch(PDO::FETCH_ASSOC);
    $current_quarter = $timer['quarter_id'] ?? 1;

    // MODIFIED LOGIC: Determine the correct period for timeouts.
    if ($current_quarter <= 2) {
        $timeout_period = 1; // First Half
    } elseif ($current_quarter <= 4) {
        $timeout_period = 2; // Second Half
    } else {
        $timeout_period = $current_quarter; // Each overtime is its own distinct period (5, 6, etc.)
    }

    $foulsA = loadTeamFouls($pdo, $game_id, $game['hometeam_id'], $current_quarter);
    $foulsB = loadTeamFouls($pdo, $game_id, $game['awayteam_id'], $current_quarter);
    // MODIFIED: Pass the new timeout_period to the function.
    $timeoutsA = loadTimeouts($pdo, $game_id, $game['hometeam_id'], $timeout_period);
    $timeoutsB = loadTimeouts($pdo, $game_id, $game['awayteam_id'], $timeout_period);

    // --- FETCH PLAYERS ---
    $player_query = "
        SELECT 
            p.id, p.first_name, p.last_name, pg.jersey_number, pg.is_playing, pg.display_order,
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
        ORDER BY pg.is_playing DESC, pg.display_order ASC";
    $stmt = $pdo->prepare($player_query);
    $stmt->execute([$game_id, $game['hometeam_id'], $game['hometeam_id']]);
    $home_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->execute([$game_id, $game['awayteam_id'], $game['awayteam_id']]);
    $away_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- FETCH LOGS (ASCENDING order to be prepended by JS) ---
    $log_stmt = $pdo->prepare("SELECT * FROM game_log WHERE game_id = ? ORDER BY id ASC");
    $log_stmt->execute([$game_id]);
    $game_logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- QR CODE GENERATION ---
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    
    // ** THE DYNAMIC FIX **
    // This line automatically discovers the laptop's IP address on the local network.
    // You no longer need to manually change this.
    $host = gethostbyname(gethostname());

    $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $control_url = "{$protocol}{$host}{$uri}/timer_control.php?game_id={$game_id}";
    $qrCode = QrCode::create($control_url);
    $writer = new PngWriter();
    $qrCodeDataUri = $writer->write($qrCode)->getDataUri();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basketball Stat Manager - Game #<?php echo htmlspecialchars($game_id); ?></title>
    <link rel="stylesheet" href="/league_management_system/game.css">
    <style>
        .container-message{text-align:center;padding:50px;font-family:sans-serif}.container-message h1{color:#333}.container-message p{color:#666;font-size:1.2em}.container-message a{color:#007bff;text-decoration:none;font-weight:700}.control-panel{display:flex;justify-content:center;align-items:center;padding:10px;background-color:#f4f4f4;border-radius:8px;margin:15px 0;text-align:center}.qr-code-container{margin-right:30px}.qr-code-container h4{margin:0 0 5px}.qr-code-container img{width:120px;height:120px}.game-actions button{font-size:1.1em;padding:10px 15px}.winner-label{color:green;font-weight:700;margin-right:5px; visibility:hidden;}
    </style>
</head>
<body>
    <?php if ($teams_are_set): ?>
        <div class="score-display">
            <div class="team-name-box left" id="home-box"><span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['hometeam_id']) ? 'visibility:visible;' : ''; ?>">(Winner)</span><a href="team_details.php?team_id=<?php echo $game['hometeam_id']; ?>" class="team-name-link"><span class="team-name"><?php echo htmlspecialchars($game['home_team_name']); ?></span></a></div>
            <span class="score" id="scoreA"><?php echo $game['hometeam_score']; ?></span><span class="separator">—</span><span class="score" id="scoreB"><?php echo $game['awayteam_score']; ?></span>
            <div class="team-name-box right" id="away-box"><a href="team_details.php?team_id=<?php echo $game['awayteam_id']; ?>" class="team-name-link"><span class="team-name"><?php echo htmlspecialchars($game['away_team_name']); ?></span></a><span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['awayteam_id']) ? 'visibility:visible;' : ''; ?>">(Winner)</span></div>
        </div>
        <div class="control-panel">
            <div class="qr-code-container"><h4>Scan for Timer Control</h4><img src="<?php echo $qrCodeDataUri; ?>" alt="QR Code for Timer Control"></div>
            <div class="game-actions"><button onclick="showOverridePanel()">Override Result</button></div>
        </div>
        <div class="teams-container">
            <div class="team-box" id="teamA">
                <div class="score-line"><span>Timeouts: <button id="timeoutsA" class="timeout-click" data-team="A" <?php if ($timeoutsA <= 0) echo 'disabled'; ?>><?php echo $timeoutsA; ?></button></span><span>Team Fouls: <span id="foulsA"><?php echo $foulsA; ?></span> <strong id="bonus-teamA" style="color: red; display: none;">Bonus</strong></span></div>
                <table><thead><tr><th style="width: 30px;">In</th><th style="width: 10px;">#</th><th style="width: 160px;">Name</th><th>1PM</th><th>2PM</th><th>3PM</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead><tbody id="teamA-players"></tbody></table>
            </div>
            <div class="team-box" id="teamB">
                <div class="score-line"><span>Timeouts: <button id="timeoutsB" class="timeout-click" data-team="B" <?php if ($timeoutsB <= 0) echo 'disabled'; ?>><?php echo $timeoutsB; ?></button></span><span>Team Fouls: <span id="foulsB"><?php echo $foulsB; ?></span> <strong id="bonus-teamB" style="color: red; display: none;">Bonus</strong></span></div>
                <table><thead><tr><th style="width: 30px;">In</th><th style="width: 50px;">#</th><th>Name</th><th>1PM</th><th>2PM</th><th>3PM</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead><tbody id="teamB-players"></tbody></table>
            </div>
        </div>
        <div id="overridePanel" style="display: none; text-align: center; margin-top: 10px;">
            <label>Select Winner:<select id="winnerSelect"><option value="">-- Select --</option><option value="A"><?php echo htmlspecialchars($game['home_team_name']); ?></option><option value="B"><?php echo htmlspecialchars($game['away_team_name']); ?></option><option value="none">None</option></select></label><button onclick="saveWinner()">Save Winner</button>
        </div>
        <div class="game-log-container"><h2>Game Log</h2><ul id="gameLogList"></ul></div>
        
        <script>
            const gameData = {
                gameId: <?php echo json_encode($game_id); ?>,
                teamA: { id: <?php echo json_encode($game['hometeam_id']); ?>, name: <?php echo json_encode($game['home_team_name']); ?>, players: <?php echo json_encode($home_players); ?> },
                teamB: { id: <?php echo json_encode($game['awayteam_id']); ?>, name: <?php echo json_encode($game['away_team_name']); ?>, players: <?php echo json_encode($away_players); ?> },
                gameStatus: <?php echo json_encode($game['game_status'] ?? 'Active'); ?>,
                logs: <?php echo json_encode($game_logs); ?>
            };

            const playerStats = {
                teamA: gameData.teamA.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order, stats: { '1PM': Number(p['1PM'])||0, '2PM': Number(p['2PM'])||0, '3PM': Number(p['3PM'])||0, 'FOUL': Number(p['FOUL'])||0, 'REB': Number(p['REB'])||0, 'AST': Number(p['AST'])||0, 'BLK': Number(p['BLK'])||0, 'STL': Number(p['STL'])||0, 'TO': Number(p['TO'])||0 } })),
                teamB: gameData.teamB.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order, stats: { '1PM': Number(p['1PM'])||0, '2PM': Number(p['2PM'])||0, '3PM': Number(p['3PM'])||0, 'FOUL': Number(p['FOUL'])||0, 'REB': Number(p['REB'])||0, 'AST': Number(p['AST'])||0, 'BLK': Number(p['BLK'])||0, 'STL': Number(p['STL'])||0, 'TO': Number(p['TO'])||0 } }))
            };
            
            let teamFouls = {
                teamA: <?php echo json_encode($foulsA); ?>,
                teamB: <?php echo json_encode($foulsB); ?>
            };

            // --- STATE VARIABLES ---
            let gameClockMs = 0;
            let currentQuarter = <?php echo json_encode($current_quarter); ?>;
            let pollingInterval = null; // **FIX**: Variable to hold our timer

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
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        console.error('Failed to save score to DB:', data.error);
                    }
                })
                .catch(error => console.error('Error saving score:', error));
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
                                    
                                    <button onclick="updateStat('${teamId}', ${idx}, '${stat}', 1)" ${gameData.gameStatus === 'Final' || (stat === 'FOUL' && isFouledOut) ? 'disabled' : ''}>+</button>
                                    <button onclick="updateStat('${teamId}', ${idx}, '${stat}', -1)" ${gameData.gameStatus === 'Final' ? 'disabled' : ''}>-</button>
                                </span>
                            </td>`).join('')}
                        <td id="pts-${teamId}-${idx}">${totalPts}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            function updatePlayerOrder(teamId) {
                const players = playerStats[teamId];
                players.forEach((player, idx) => { player.displayOrder = idx; });
                fetch('update_player_order.php',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({game_id:gameData.gameId,team_id:"teamA"===teamId?gameData.teamA.id:gameData.teamB.id,order:players.map(p=>p.id)})})
            }

            function togglePlayer(teamId, playerIdx, isChecked) {
                if(gameData.gameStatus === 'Final') return;
                const players = playerStats[teamId];
                const player = players[playerIdx];
                if(isChecked && players.filter(p => p.isPlaying).length >= 5) {
                    document.querySelector(`#${teamId}-players tr:nth-child(${playerIdx + 1}) .in-game-checkbox`).checked = false;
                    alert(`Only 5 players can be in the game for ${gameData[teamId].name}.`);
                    return;
                }
                player.isPlaying = isChecked ? 1 : 0;
                playerStats[teamId] = [ ...players.filter(p => p.isPlaying), ...players.filter(p => !p.isPlaying) ];
                fetch("update_player_status.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({game_id:gameData.gameId,player_id:player.id,team_id:teamId==="teamA"?gameData.teamA.id:gameData.teamB.id,is_playing:player.isPlaying})});
                updatePlayerOrder(teamId);
                renderTeam(teamId);
            }

            function updateJersey(teamId, playerIdx, jerseyNumber) {
                if(gameData.gameStatus === 'Final') return;
                const player = playerStats[teamId][playerIdx];
                player.jersey = jerseyNumber;
                fetch("update_jersey_number.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({game_id:gameData.gameId,player_id:player.id,team_id:teamId==="teamA"?gameData.teamA.id:gameData.teamB.id,jersey_number:jerseyNumber})});
            }
            
            function updateBonusUI(teamId) {
                document.getElementById(`bonus-${teamId}`).style.display = teamFouls[teamId] >= 4 ? "inline" : "none";
            }
            
            function updateStat(teamId, playerIdx, stat, delta) {
                if(gameData.gameStatus === 'Final') return;
                const player = playerStats[teamId][playerIdx];
                
                // MODIFICATION START: Add a check to prevent adding fouls beyond 5
                if (stat === 'FOUL' && delta > 0 && player.stats[stat] >= 5) {
                    alert(`${player.name} has already fouled out and cannot receive more fouls.`);
                    return; // Stop the function here
                }
                // MODIFICATION END
                
                if (!window.confirm(`${delta > 0 ? "Add" : "Remove"} ${stat} ${delta > 0 ? "to" : "from"} ${player.name}?`)) return;
                
                player.stats[stat] = Math.max(0, player.stats[stat] + delta);
                
                if (stat === 'FOUL') {
                    teamFouls[teamId] = Math.max(0, teamFouls[teamId] + delta);
                    document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                    updateBonusUI(teamId);
                    fetch("update_team_fouls.php", {
                        method: "POST", headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, quarter: currentQuarter, fouls: teamFouls[teamId] })
                    });
                }
                
                fetch("update_stat.php", {
                    method: "POST", headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ game_id: gameData.gameId, player_id: player.id, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, statistic_name: stat, value: delta })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        if (delta > 0) {
                            logStatChange(player, teamId, stat);
                        }
                        renderTeam(teamId);
                        updateRunningScore(teamId);
                    } else {
                        console.error("Failed to update stat:", data.error);
                        player.stats[stat] = Math.max(0, player.stats[stat] - delta); // Revert
                        renderTeam(teamId);
                        updateRunningScore(teamId);
                    }
                });
            }

            function showButtons(cell) { if(gameData.gameStatus !== 'Final') cell.querySelector(".stat-controls").style.display = "inline-flex"; }
            function hideButtons(cell) { cell.querySelector(".stat-controls").style.display = "none"; }
            function showOverridePanel() { if(gameData.gameStatus !== 'Final') document.getElementById("overridePanel").style.display = "block"; }

            function saveWinner() {
                if (gameData.gameStatus === 'Final') return;
                const selected = document.getElementById('winnerSelect').value;
                if (!selected) { alert("Please select a winner."); return; }
                
                const winnerTeam = selected === 'A' ? gameData.teamA : (selected === 'B' ? gameData.teamB : null);
                
                fetch('save_winner.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameData.gameId, winnerteam_id: winnerTeam ? winnerTeam.id : null })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        alert("Winner saved successfully!");
                        document.getElementById('overridePanel').style.display = 'none';
                        const homeLabel = document.querySelector('#home-box .winner-label');
                        const awayLabel = document.querySelector('#away-box .winner-label');
                        homeLabel.style.visibility = (selected === 'A') ? 'visible' : 'hidden';
                        awayLabel.style.visibility = (selected === 'B') ? 'visible' : 'hidden';
                    } else {
                        alert("Failed to save winner: " + (data.error || "Unknown error."));
                    }
                });
            }

            function formatGameTime(ms) {
                if (ms === null || ms < 0) return "00:00.0";
                const totalSeconds = Math.floor(ms / 1000);
                const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
                const seconds = (totalSeconds % 60).toString().padStart(2, '0');
                const tenths = Math.floor((ms % 1000) / 100).toString();
                return `${minutes}:${seconds}.${tenths}`;
            }

            function renderLogEntry(log) {
                const logList = document.getElementById('gameLogList');
                let li = document.getElementById(`log-${log.id}`);
                if (!li) {
                    li = document.createElement('li');
                    li.id = `log-${log.id}`;
                    logList.insertBefore(li, logList.firstChild);
                }
                const time = formatGameTime(log.game_clock_ms);
                li.innerHTML = ''; 
                const textSpan = document.createElement('span');
                textSpan.textContent = `[Q${log.quarter} ${time}] ${log.action_details}`;

                let button;
                if (log.is_undone == 1) {
                    li.style.textDecoration = 'line-through';
                    li.style.color = '#888';
                    button = document.createElement('button');
                    button.textContent = 'Redo';
                    button.onclick = () => redoAction(log.id);
                } else {
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
                document.getElementById('gameLogList').innerHTML = ''; 
                gameData.logs.forEach(log => renderLogEntry(log));
            }

            function logStatChange(player, teamId, stat) {
                const statDescriptions = { '1PM': '1-Point Shot', '2PM': '2-Point Shot', '3PM': '3-Point Shot', 'FOUL': 'Foul', 'REB': 'Rebound', 'AST': 'Assist', 'BLK': 'Block', 'STL': 'Steal', 'TO': 'Turnover' };
                const description = statDescriptions[stat] || `recorded a ${stat}`;
                const team = teamId === 'teamA' ? gameData.teamA : gameData.teamB;
                const details = `${player.name} (${team.name}) ${description}.`;
                const logData = {
                    game_id: gameData.gameId,
                    player_id: player.id,
                    team_id: team.id,
                    quarter: currentQuarter,
                    game_clock: gameClockMs,
                    action_type: stat,
                    action_details: details
                };
                
                fetch('log_game_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(logData) })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.log_id) {
                        const newLog = { 
                            id: data.log_id, 
                            quarter: logData.quarter,
                            game_clock_ms: logData.game_clock,
                            action_details: logData.action_details,
                            is_undone: 0
                        };
                        renderLogEntry(newLog);
                    } else {
                        console.error("Failed to log action:", data.error);
                    }
                })
                .catch(error => console.error("Error logging action:", error));
            }

            function logTimeoutAction(teamKey) {
                const team = teamKey === 'A' ? gameData.teamA : gameData.teamB;
                const details = `${team.name} called a Timeout.`;
                const logData = {
                    game_id: gameData.gameId,
                    player_id: null,
                    team_id: team.id,
                    quarter: currentQuarter,
                    game_clock: gameClockMs,
                    action_type: 'TIMEOUT',
                    action_details: details
                };
                
                fetch('log_game_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(logData) })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.log_id) {
                        const newLog = { 
                            id: data.log_id, 
                            quarter: logData.quarter,
                            game_clock_ms: logData.game_clock,
                            action_details: logData.action_details,
                            is_undone: 0
                        };
                        renderLogEntry(newLog);
                    } else {
                        console.error("Failed to log timeout:", data.error);
                    }
                })
                .catch(error => console.error("Error logging timeout:", error));
            }

            async function undoAction(logId) {
                if (!confirm("Are you sure? This will update stats and scores.")) return;
                const response = await fetch('undo_specific_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ log_id: logId }) });
                const result = await response.json();
                if (result.success) {
                    alert("Action undone. Page will now refresh.");
                    location.reload();
                } else {
                    alert("Failed to undo action: " + result.error);
                }
            }

            async function redoAction(logId) {
                if (!confirm("Are you sure?")) return;
                const response = await fetch('redo_specific_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ log_id: logId }) });
                const result = await response.json();
                if (result.success) {
                    alert("Action redone. Page will now refresh.");
                    location.reload();
                } else {
                    alert("Failed to redo action: " + result.error);
                }
            }

            async function fetchAndApplyState() {
                try {
                    const response = await fetch(`get_timer_state.php?game_id=${gameData.gameId}`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const state = await response.json();
                    if (!state) return;

                    if (state.running) {
                        const elapsedMs = state.current_server_time - state.last_updated_at;
                        gameClockMs = Math.max(0, state.game_clock - elapsedMs);
                    } else {
                        gameClockMs = state.game_clock;
                    }

                    if (state.quarter_id !== currentQuarter) {
                        // **FIX**: Stop the timer *before* showing the alert
                        clearInterval(pollingInterval); 
                        alert('Quarter has changed. Refreshing the page to sync.');
                        location.reload();
                        return; // Stop further execution
                    }
                    
                    document.getElementById('scoreA').textContent = state.hometeam_score;
                    document.getElementById('scoreB').textContent = state.awayteam_score;
                    
                } catch (error) {
                    console.error('State polling error:', error);
                }
            }

            window.addEventListener("DOMContentLoaded", () => {
                renderTeam("teamA");
                renderTeam("teamB");
                updateBonusUI("teamA");
                updateBonusUI("teamB");
                renderInitialLog();

                updateRunningScore("teamA");
                updateRunningScore("teamB");

                if (gameData.gameStatus === 'Final') {
                    document.querySelectorAll('input, button').forEach(el => {
                        if (!el.textContent.includes("Undo") && !el.textContent.includes("Redo")) {
                            el.disabled = true;
                        }
                    });
                }

                document.querySelectorAll(".timeout-click").forEach(el => {
                    el.addEventListener("click", async () => {
                        if (gameData.gameStatus === 'Final') return;
                        const teamKey = el.dataset.team;
                        const teamId = teamKey === 'A' ? gameData.teamA.id : gameData.teamB.id;
                        
                        let timeoutPeriod;
                        if (currentQuarter <= 2) {
                            timeoutPeriod = 1;
                        } else if (currentQuarter <= 4) {
                            timeoutPeriod = 2;
                        } else {
                            timeoutPeriod = currentQuarter;
                        }

                        if (confirm(`Use a timeout for ${gameData[teamKey === 'A' ? 'teamA' : 'teamB'].name}?`)) {
                            const response = await fetch("use_timeout.php", {
                                method: "POST", headers: { "Content-Type": "application/json" },
                                body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId, half: timeoutPeriod })
                            });
                            const result = await response.json();
                            if (result.success) {
                                logTimeoutAction(teamKey);
                                el.textContent = result.remaining;
                                if (result.remaining <= 0) {
                                    el.disabled = true;
                                }
                            } else {
                                alert("Failed to use timeout: " + (result.error || "Unknown error"));
                            }
                        }
                    });
                });

                fetchAndApplyState();
                // **FIX**: Store the interval in our variable so we can clear it later
                pollingInterval = setInterval(fetchAndApplyState, 1000); 
            });
        </script>

    <?php else: ?>
        <div class="container-message">
            <h1>Game Not Ready</h1>
            <p>This game cannot be managed because either one team or both teams have not been assigned yet.</p>
            <?php if (!empty($game['category_id'])): ?>
                <p><a href="category_details.php?category_id=<?php echo htmlspecialchars($game['category_id']); ?>&tab=schedule">Return to Schedule</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>