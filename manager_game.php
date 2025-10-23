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

$teams_are_set = !empty($game['hometeam_id']) && !empty($game['awayteam_id']);

function loadTeamFouls(PDO $pdo, $game_id, $team_id, $quarter) {
    $stmt = $pdo->prepare("SELECT fouls FROM game_team_fouls WHERE game_id = ? AND team_id = ? AND quarter = ?");
    $stmt->execute([$game_id, $team_id, $quarter]);
    return (int)($stmt->fetchColumn() ?? 0);
}

function loadTimeouts(PDO $pdo, $game_id, $team_id, $period) {
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
    $stmt->execute([$game_id, $team_id, $period]);
    $result = $stmt->fetchColumn();

    if ($result !== false) { return (int)$result; }
    if ($period == 1) { return 2; } 
    elseif ($period == 2) { return 3; } 
    else { return 1; }
}

if ($teams_are_set) {
    $prep_stmt = $pdo->prepare("
        INSERT IGNORE INTO player_game (player_id, game_id, team_id, display_order)
        SELECT pt.player_id, ?, pt.team_id, pt.player_id
        FROM player_team pt
        WHERE pt.team_id IN (?, ?)
    ");
    $prep_stmt->execute([$game_id, $game['hometeam_id'], $game['awayteam_id']]);

    $timer_stmt = $pdo->prepare("SELECT game_clock, quarter_id FROM game_timer WHERE game_id = ?");
    $timer_stmt->execute([$game_id]);
    $timer = $timer_stmt->fetch(PDO::FETCH_ASSOC);
    $current_quarter = $timer['quarter_id'] ?? 1;
    $initial_game_clock_ms = $timer['game_clock'] ?? 600000; // Default to 10 mins if not set

    if ($current_quarter <= 2) { $timeout_period = 1; } 
    elseif ($current_quarter <= 4) { $timeout_period = 2; } 
    else { $timeout_period = $current_quarter; }

    $foulsA = loadTeamFouls($pdo, $game_id, $game['hometeam_id'], $current_quarter);
    $foulsB = loadTeamFouls($pdo, $game_id, $game['awayteam_id'], $current_quarter);
    $timeoutsA = loadTimeouts($pdo, $game_id, $game['hometeam_id'], $timeout_period);
    $timeoutsB = loadTimeouts($pdo, $game_id, $game['awayteam_id'], $timeout_period);

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
    FROM player_game pg
    JOIN player p ON p.id = pg.player_id
    LEFT JOIN game_statistic gs ON pg.player_id = gs.player_id AND pg.game_id = gs.game_id
    LEFT JOIN statistic s ON gs.statistic_id = s.id
    WHERE pg.game_id = ? AND pg.team_id = ?
    GROUP BY p.id, pg.jersey_number, pg.is_playing, pg.display_order
    ORDER BY pg.is_playing DESC, pg.display_order ASC";
        
    $stmt = $pdo->prepare($player_query);
    $stmt->execute([$game_id, $game['hometeam_id']]);
    $home_players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->execute([$game_id, $game['awayteam_id']]);
    $away_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log_stmt = $pdo->prepare("SELECT * FROM game_log WHERE game_id = ? ORDER BY id ASC");
    $log_stmt->execute([$game_id]);
    $game_logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = gethostbyname(gethostname());
    $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $control_url = "{$protocol}{$host}{$uri}/timer_control.php?game_id={$game_id}";
    $qrCode = QrCode::create($control_url);
    $writer = new PngWriter();
    $qrCodeDataUri = $writer->write($qrCode)->getDataUri();

    // 2. NEW Spectator View URL
    $spectator_url = "{$protocol}{$host}{$uri}/spectator_view.php?game_id={$game_id}";
    $qrCodeSpectator = QrCode::create($spectator_url);
    $qrCodeSpectatorUri = $writer->write($qrCodeSpectator)->getDataUri();
}

// **MODIFIED**: Determine Quarter Display Text (no change needed here)
$quarter_text = "Q{$current_quarter}";
if ($game_id && $current_quarter > 4) {
    $overtime_num = $current_quarter - 4;
    $quarter_text = "OT{$overtime_num}";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Game #<?php echo htmlspecialchars($game_id); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="game.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<div id="foulPopover">
    <button class="btn btn-sm btn-primary" onclick="handleFoulSelection('Normal')">Normal Foul</button>
    <button class="btn btn-sm btn-secondary" onclick="handleFoulSelection('Offensive')">Offensive Foul</button>
    <button class="btn btn-sm btn-cancel" onclick="cancelFoulSelection()">Cancel</button>
</div>


<div class="game-manager-container"> 
    <?php if ($teams_are_set): ?>
        <div class="score-display">
            <div class="team-info home">
                <span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['hometeam_id']) ? 'visibility:visible;' : ''; ?>">(Winner)</span>
                <a href="team_details.php?team_id=<?php echo $game['hometeam_id']; ?>" class="team-name-link">
                    <span class="team-name"><?php echo htmlspecialchars($game['home_team_name']); ?></span>
                </a>
            </div>
            
            <div class="scores">
                <span class="score" id="scoreA"><?php echo $game['hometeam_score']; ?></span>
                <span class="separator">—</span>
                <span class="score" id="scoreB"><?php echo $game['awayteam_score']; ?></span>
            </div>

            <div class="team-info away">
                <a href="team_details.php?team_id=<?php echo $game['awayteam_id']; ?>" class="team-name-link">
                    <span class="team-name"><?php echo htmlspecialchars($game['away_team_name']); ?></span>
                </a>
                <span class="winner-label" style="<?php echo ($game['winnerteam_id'] == $game['awayteam_id']) ? 'visibility:visible;' : ''; ?>">(Winner)</span>
            </div>
            </div>

        <div class="quarter-indicator" id="quarterIndicator">
            <span id="quarterText"><?php echo htmlspecialchars($quarter_text); ?></span>
            <span class="indicator-separator">-</span>
            <span class="indicator-clock" id="indicatorClock">--:--.-</span>
        </div>


        <div class="teams-container">
            <div class="team-box">
                <div class="team-stats-header">
                    <span>Timeouts: <button id="timeoutsA" class="btn btn-sm timeout-click" data-team="A" <?php if ($timeoutsA <= 0) echo 'disabled'; ?>><?php echo $timeoutsA; ?></button></span>
                    <span>Team Fouls: <span id="foulsA"><?php echo $foulsA; ?></span> <strong id="bonus-teamA" class="bonus-indicator" style="display: none;">Bonus</strong></span>
                </div>
                <div class="table-wrapper">
                    <table class="category-table"><thead><tr><th>In</th><th>#</th><th>Name</th><th>1PT</th><th>2PT</th><th>3PT</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead><tbody id="teamA-players"></tbody></table>
                </div>
            </div>
            <div class="team-box">
                <div class="team-stats-header">
                    <span>Timeouts: <button id="timeoutsB" class="btn btn-sm timeout-click" data-team="B" <?php if ($timeoutsB <= 0) echo 'disabled'; ?>><?php echo $timeoutsB; ?></button></span>
                    <span>Team Fouls: <span id="foulsB"><?php echo $foulsB; ?></span> <strong id="bonus-teamB" class="bonus-indicator" style="display: none;">Bonus</strong></span>
                </div>
                <div class="table-wrapper">
                    <table class="category-table"><thead><tr><th>In</th><th>#</th><th>Name</th><th>1PT</th><th>2PT</th><th>3PT</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead><tbody id="teamB-players"></tbody></table>
                </div>
            </div>
        </div>
        
        <div class="game-log-container">
            <div class="section-header"><h2>Game Log</h2></div>
            <ul id="gameLogList"></ul>
        </div>

        <div class="control-panel schedule-actions">
            <div class="qr-code-container">
                <h4>Scan for Timer Control</h4>
                <img src="<?php echo $qrCodeDataUri; ?>" alt="QR Code for Timer Control">
            </div>

        <div class="qr-code-container">
            <h4>Spectator View (Team Watchers)</h4>
            <img src="<?php echo $qrCodeSpectatorUri; ?>" alt="QR Code for Spectator View">
        </div>
            <div class="game-actions">
                <button class="btn" onclick="openScoreboardWindow()">Open Projector View</button>
                <button class="btn btn-secondary" onclick="showOverridePanel()">Override Result</button>
            </div>
        </div>
         <div id="overridePanel" style="display: none;" class="override-panel">
            <div class="form-group">
                <label for="winnerSelect">Select Winner:</label>
                <select id="winnerSelect">
                    <option value="">-- Select --</option>
                    <option value="A"><?php echo htmlspecialchars($game['home_team_name']); ?></option>
                    <option value="B"><?php echo htmlspecialchars($game['away_team_name']); ?></option>
                    <option value="none">None</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="saveWinner()">Save Winner</button>
        </div>
        
        <script>
            const gameData = { gameId: <?php echo json_encode($game_id); ?>, teamA: { id: <?php echo json_encode($game['hometeam_id']); ?>, name: <?php echo json_encode($game['home_team_name']); ?>, players: <?php echo json_encode($home_players); ?> }, teamB: { id: <?php echo json_encode($game['awayteam_id']); ?>, name: <?php echo json_encode($game['away_team_name']); ?>, players: <?php echo json_encode($away_players); ?> }, gameStatus: <?php echo json_encode($game['game_status'] ?? 'Active'); ?>, logs: <?php echo json_encode($game_logs); ?> };
            const playerStats = { teamA: gameData.teamA.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order, stats: { '1PM': Number(p['1PM'])||0, '2PM': Number(p['2PM'])||0, '3PM': Number(p['3PM'])||0, 'FOUL': Number(p['FOUL'])||0, 'REB': Number(p['REB'])||0, 'AST': Number(p['AST'])||0, 'BLK': Number(p['BLK'])||0, 'STL': Number(p['STL'])||0, 'TO': Number(p['TO'])||0 } })), teamB: gameData.teamB.players.map(p => ({ id: p.id, jersey: p.jersey_number ?? '--', name: `${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}.`, isPlaying: p.is_playing ?? 0, displayOrder: p.display_order, stats: { '1PM': Number(p['1PM'])||0, '2PM': Number(p['2PM'])||0, '3PM': Number(p['3PM'])||0, 'FOUL': Number(p['FOUL'])||0, 'REB': Number(p['REB'])||0, 'AST': Number(p['AST'])||0, 'BLK': Number(p['BLK'])||0, 'STL': Number(p['STL'])||0, 'TO': Number(p['TO'])||0 } })) };
            let teamFouls = { teamA: <?php echo json_encode($foulsA); ?>, teamB: <?php echo json_encode($foulsB); ?> };
            let gameClockMs = <?php echo json_encode($initial_game_clock_ms); ?>; // Use initial clock from PHP
            let currentQuarter = <?php echo json_encode($current_quarter); ?>;
            let pollingInterval = null;
            let localTimerInterval = null; // Variable for the local timer
            let isClockRunning = false; // Track if the clock is running
            

            function openScoreboardWindow() {
                const url = `scoreboard.php?game_id=${gameData.gameId}`;
                const scoreboardWindow = window.open(url, 'BAP_Scoreboard');
                if (!scoreboardWindow || scoreboardWindow.closed || typeof scoreboardWindow.closed == 'undefined') {
                    alert('Pop-up Blocked! Please allow pop-ups for this site to open the scoreboard.');
                }
            }

            function calculatePoints(stats) { return stats['1PM'] * 1 + stats['2PM'] * 2 + stats['3PM'] * 3; }
            
            function updateRunningScore(teamId) {
                const players = playerStats[teamId];
                const total = players.reduce((sum, p) => sum + calculatePoints(p.stats), 0);
                document.getElementById(teamId === 'teamA' ? 'scoreA' : 'scoreB').textContent = total;
                const scoreA = parseInt(document.getElementById('scoreA').textContent) || 0;
                const scoreB = parseInt(document.getElementById('scoreB').textContent) || 0;
                fetch('update_game_scores.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ game_id: gameData.gameId, hometeam_score: scoreA, awayteam_score: scoreB }) }).then(res => res.json()).then(data => { if (!data.success) console.error('Failed to save score to DB:', data.error); }).catch(error => console.error('Error saving score:', error));
            }

             function renderTeam(teamId) {
                const tbody = document.getElementById(`${teamId}-players`);
                tbody.innerHTML = '';
                playerStats[teamId].forEach((player, idx) => {
                    const tr = document.createElement('tr');
                    const stats = player.stats;
                    const totalPts = calculatePoints(stats);
                    const isFouledOut = stats['FOUL'] >= 5;
                    
                    const statButtonsHTML = ['1PM','2PM','3PM','FOUL','REB','AST','BLK','STL','TO'].map(stat => {
                        let onclickAction = `updateStat('${teamId}', ${idx}, '${stat}', 1)`; 
                        if (stat === 'FOUL') {
                            onclickAction = `showFoulPopover(this, '${teamId}', ${idx})`; 
                        }

                        return `
                        <td class="stat-cell" onmouseover="showButtons(this)" onmouseleave="hideButtons(this)">
                            <span class="stat-value" style="${stat === 'FOUL' && isFouledOut ? 'color: red; font-weight: bold;' : ''}">${stats[stat]}</span>
                            <div class="stat-controls">
                                <button onclick="${onclickAction}" class="btn-stat-add" ${gameData.gameStatus === 'Final' || (stat === 'FOUL' && isFouledOut) ? 'disabled' : ''}>+</button>
                            </div>
                        </td>`;
                    }).join('');

                    tr.innerHTML = `
                        <td><input type="checkbox" class="in-game-checkbox" ${player.isPlaying ? 'checked' : ''} onchange="togglePlayer('${teamId}', ${idx}, this.checked)" ${gameData.gameStatus === 'Final' ? 'disabled' : ''}></td>
                        <td><input type="text" class="jersey-input" value="${player.jersey}" oninput="updateJersey('${teamId}', ${idx}, this.value)"></td>
                        <td class="name-cell">${player.name}</td>
                        ${statButtonsHTML}
                        <td class="total-points">${totalPts}</td>
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
            
           function updateBonusUI() {
                document.getElementById('bonus-teamA').style.display = teamFouls['teamB'] >= 5 ? "inline" : "none";
                document.getElementById('bonus-teamB').style.display = teamFouls['teamA'] >= 5 ? "inline" : "none";
            }
            
            function updateStat(teamId, playerIdx, stat, delta) {
                if(gameData.gameStatus === 'Final') return;
                
                const player = playerStats[teamId][playerIdx];

                if (stat === 'FOUL' && delta > 0 && player.stats[stat] >= 5) {
                    alert(`${player.name} has already fouled out and cannot receive more fouls.`);
                    return;
                }
                if (!window.confirm(`${delta > 0 ? "Add" : "Remove"} ${stat} ${delta > 0 ? "to" : "from"} ${player.name}?`)) return;
                
                player.stats[stat] = Math.max(0, player.stats[stat] + delta);
                
                if (stat === 'FOUL' && delta < 0) { // Only update team fouls when removing
                    teamFouls[teamId] = Math.max(0, teamFouls[teamId] + delta);
                    document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                    updateBonusUI();
                    fetch("update_team_fouls.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, quarter: currentQuarter, fouls: teamFouls[teamId] }) });
                }
                
                fetch("update_stat.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ game_id: gameData.gameId, player_id: player.id, team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, statistic_name: stat, value: delta })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        if (delta > 0) logStatChange(player, teamId, stat); 
                        renderTeam(teamId);
                        if (['1PM', '2PM', '3PM'].includes(stat)) {
                            updateRunningScore(teamId);
                        }
                    } else {
                        console.error("Failed to update stat:", data.error);
                        player.stats[stat] = Math.max(0, player.stats[stat] - delta); // Revert
                        if (stat === 'FOUL' && delta < 0) {
                             teamFouls[teamId] = Math.max(0, teamFouls[teamId] - delta); 
                             document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                             updateBonusUI();
                        }
                        renderTeam(teamId);
                         if (['1PM', '2PM', '3PM'].includes(stat)) {
                            updateRunningScore(teamId);
                        }
                    }
                });
            }

            function showFoulPopover(buttonElement, teamId, playerIdx) {
                if (gameData.gameStatus === 'Final') return;

                const player = playerStats[teamId][playerIdx];
                if (player.stats['FOUL'] >= 5) {
                    alert(`${player.name} has already fouled out and cannot receive more fouls.`);
                    return;
                }

                const popover = document.getElementById('foulPopover');
                popover.dataset.teamId = teamId;
                popover.dataset.playerIdx = playerIdx;

                const rect = buttonElement.getBoundingClientRect();
                popover.style.left = (rect.left + window.scrollX - (popover.offsetWidth / 2) + (rect.width / 2)) + 'px';
                popover.style.top = (rect.top + window.scrollY - popover.offsetHeight - 5) + 'px'; 

                popover.style.display = 'block';

                setTimeout(() => {
                    document.addEventListener('click', handleClickOutsidePopover, { once: true, capture: true });
                }, 0);
            }

            function handleClickOutsidePopover(event) {
                const popover = document.getElementById('foulPopover');
                if (!popover.contains(event.target)) {
                    popover.style.display = 'none';
                } else {
                     setTimeout(() => {
                        document.addEventListener('click', handleClickOutsidePopover, { once: true, capture: true });
                    }, 0);
                }
            }

            function handleFoulSelection(foulType) {
                const popover = document.getElementById('foulPopover');
                const { teamId, playerIdx } = popover.dataset; 
                
                if (!teamId) return; 

                popover.style.display = 'none'; 
                document.removeEventListener('click', handleClickOutsidePopover, { capture: true });

                const player = playerStats[teamId][playerIdx];
                const stat = 'FOUL';

                if (!window.confirm(`Add ${foulType} Foul to ${player.name}?`)) {
                    return;
                }

                player.stats[stat] = Math.max(0, player.stats[stat] + 1);

                if (foulType === 'Normal') {
                    teamFouls[teamId] = Math.max(0, teamFouls[teamId] + 1);
                    document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                    updateBonusUI();
                    fetch("update_team_fouls.php", { 
                        method: "POST", 
                        headers: { "Content-Type": "application/json" }, 
                        body: JSON.stringify({ 
                            game_id: gameData.gameId, 
                            team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, 
                            quarter: currentQuarter, 
                            fouls: teamFouls[teamId] 
                        }) 
                    });
                }

                fetch("update_stat.php", { 
                    method: "POST", 
                    headers: { "Content-Type": "application/json" }, 
                    body: JSON.stringify({ 
                        game_id: gameData.gameId, 
                        player_id: player.id, 
                        team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id, 
                        statistic_name: stat, 
                        value: 1 
                    })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        logStatChange(player, teamId, stat, foulType); 
                        renderTeam(teamId);
                    } else {
                        console.error("Failed to update stat:", data.error);
                        player.stats[stat] = Math.max(0, player.stats[stat] - 1); 
                        if (foulType === 'Normal') {
                            teamFouls[teamId] = Math.max(0, teamFouls[teamId] - 1); 
                            document.getElementById(teamId === 'teamA' ? 'foulsA' : 'foulsB').textContent = teamFouls[teamId];
                            updateBonusUI();
                        }
                        renderTeam(teamId);
                    }
                });
            }

            function cancelFoulSelection() {
                document.getElementById('foulPopover').style.display = 'none';
                document.removeEventListener('click', handleClickOutsidePopover, { capture: true });
            }


            function showButtons(cell) { if(gameData.gameStatus !== 'Final') cell.querySelector(".stat-controls").style.display = "flex"; }
            function hideButtons(cell) { cell.querySelector(".stat-controls").style.display = "none"; }
            function showOverridePanel() { if(gameData.gameStatus !== 'Final') document.getElementById("overridePanel").style.display = "block"; }

            function saveWinner() {
                if (gameData.gameStatus === 'Final') return;
                const selected = document.getElementById('winnerSelect').value;
                if (!selected) { alert("Please select a winner."); return; }
                const winnerTeam = selected === 'A' ? gameData.teamA : (selected === 'B' ? gameData.teamB : null);
                fetch('save_winner.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ game_id: gameData.gameId, winnerteam_id: winnerTeam ? winnerTeam.id : null })
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        alert("Winner saved successfully!");
                        document.getElementById('overridePanel').style.display = 'none';
                        const homeLabel = document.querySelector('.team-info.home .winner-label');
                        const awayLabel = document.querySelector('.team-info.away .winner-label');
                        homeLabel.style.visibility = (selected === 'A') ? 'visible' : 'hidden';
                        awayLabel.style.visibility = (selected === 'B') ? 'visible' : 'hidden';
                    } else { alert("Failed to save winner: " + (data.error || "Unknown error.")); }
                });
            }

            function formatGameTime(ms) {
                if (ms === null || ms < 0) return "00:00.0";
                const totalSeconds = Math.floor(ms / 1000);
                const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
                const seconds = (totalSeconds % 60).toString().padStart(2, '0');
                const tenths = Math.floor((ms % 1000) / 100).toString();
                // Show tenths only when under 1 minute for standard display
                 return totalSeconds < 60 ? `${minutes}:${seconds}.${tenths}` : `${minutes}:${seconds}`;
                 // Always return with tenths for the indicator:
                // return `${minutes}:${seconds}.${tenths}`; 
            }

             // **NEW**: Function to update the indicator display
            function updateIndicatorDisplay() {
                 document.getElementById('quarterText').textContent = currentQuarter <= 4 ? `Q${currentQuarter}` : `OT${currentQuarter - 4}`;
                 document.getElementById('indicatorClock').textContent = formatGameTime(gameClockMs);
            }


            function renderLogEntry(log) {
                const logList = document.getElementById('gameLogList');
                let li = document.getElementById(`log-${log.id}`);
                if (!li) { li = document.createElement('li'); li.id = `log-${log.id}`; logList.insertBefore(li, logList.firstChild); }
                li.innerHTML = ''; 
                // Use the passed quarter from the log, not the global currentQuarter
                const logQuarterText = log.quarter <= 4 ? `Q${log.quarter}` : `OT${log.quarter - 4}`;
                const time = formatGameTime(log.game_clock_ms);
                const textSpan = document.createElement('span');
                // Display the correct quarter from the log entry
                textSpan.textContent = `[${logQuarterText} ${time}] ${log.action_details}`; 
                let button;
                if (log.is_undone == 1) {
                    li.classList.add('undone');
                    button = document.createElement('button');
                    button.textContent = 'Redo';
                    button.className = 'btn btn-sm btn-secondary';
                    button.onclick = () => redoAction(log.id);
                } else {
                    li.classList.remove('undone');
                    button = document.createElement('button');
                    button.textContent = 'Undo';
                    button.className = 'btn btn-sm btn-danger';
                    button.onclick = () => undoAction(log.id);
                }
                li.appendChild(textSpan);
                li.appendChild(button);
            }

            function renderInitialLog() {
                document.getElementById('gameLogList').innerHTML = ''; 
                gameData.logs.forEach(log => renderLogEntry(log));
            }

            function logStatChange(player, teamId, stat, foulType = null) {
                const statDescriptions = { '1PM': 'made a 1-Point Shot', '2PM': 'made a 2-Point Shot', '3PM': 'made a 3-Point Shot', 'FOUL': 'committed a Foul', 'REB': 'got a Rebound', 'AST': 'recorded an Assist', 'BLK': 'recorded a Block', 'STL': 'recorded a Steal', 'TO': 'committed a Turnover' };
                
                let description = statDescriptions[stat] || `recorded a ${stat}`;
                let logActionType = stat;

                if (stat === 'FOUL' && foulType === 'Offensive') {
                    description = 'committed an Offensive Foul';
                    logActionType = 'FOUL_OFFENSIVE'; 
                } else if (stat === 'FOUL' && foulType === 'Normal') {
                    description = 'committed a Normal Foul';
                    logActionType = 'FOUL'; 
                }

                const team = teamId === 'teamA' ? gameData.teamA : gameData.teamB;
                const details = `${player.name} (${team.name}) ${description}.`;
                
                const logData = { game_id: gameData.gameId, player_id: player.id, team_id: team.id, quarter: currentQuarter, game_clock: gameClockMs, action_type: logActionType, action_details: details };
                
                fetch('log_game_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(logData) }).then(res => res.json()).then(data => { if (data.success && data.log_id) { 
                    // Create the full log object to pass to renderLogEntry
                    const newLog = { 
                        id: data.log_id, 
                        quarter: logData.quarter, // Use the quarter when the action happened
                        game_clock_ms: logData.game_clock, 
                        action_details: logData.action_details, 
                        is_undone: 0 
                    }; 
                    renderLogEntry(newLog); 
                    // Add the new log to the beginning of the local logs array
                    gameData.logs.unshift(newLog);
                    } else { console.error("Failed to log action:", data.error); } }).catch(error => console.error("Error logging action:", error));
            }

            function logTimeoutAction(teamKey) {
                const team = teamKey === 'A' ? gameData.teamA : gameData.teamB;
                const details = `${team.name} called a Timeout.`;
                const logData = { game_id: gameData.gameId, player_id: null, team_id: team.id, quarter: currentQuarter, game_clock: gameClockMs, action_type: 'TIMEOUT', action_details: details };
                fetch('log_game_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(logData) }).then(res => res.json()).then(data => { if (data.success && data.log_id) { 
                    const newLog = { 
                        id: data.log_id, 
                        quarter: logData.quarter, 
                        game_clock_ms: logData.game_clock, 
                        action_details: logData.action_details, 
                        is_undone: 0 
                    }; 
                    renderLogEntry(newLog); 
                     gameData.logs.unshift(newLog);
                    } else { console.error("Failed to log timeout:", data.error); } }).catch(error => console.error("Error logging timeout:", error));
            }

            async function undoAction(logId) {
                if (!confirm("Are you sure? This will update stats and scores.")) return;
                const response = await fetch('undo_specific_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ log_id: logId }) });
                const result = await response.json();
                if (result.success) { 
                     // Find the log in the local array and mark it as undone
                    const logIndex = gameData.logs.findIndex(log => log.id == logId);
                    if (logIndex !== -1) {
                        gameData.logs[logIndex].is_undone = 1;
                    }
                    alert("Action undone. Page will now refresh."); 
                    location.reload(); // Still reload to ensure full sync
                 } else { alert("Failed to undo action: " + result.error); }
            }

            async function redoAction(logId) {
                if (!confirm("Are you sure?")) return;
                const response = await fetch('redo_specific_action.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ log_id: logId }) });
                const result = await response.json();
                if (result.success) { 
                     // Find the log in the local array and mark it as NOT undone
                    const logIndex = gameData.logs.findIndex(log => log.id == logId);
                    if (logIndex !== -1) {
                        gameData.logs[logIndex].is_undone = 0;
                    }
                    alert("Action redone. Page will now refresh."); 
                    location.reload(); // Still reload to ensure full sync
                 } else { alert("Failed to redo action: " + result.error); }
            }

             // **MODIFIED**: Fetch and Apply State
            async function fetchAndApplyState() {
                try {
                    const response = await fetch(`get_timer_state.php?game_id=${gameData.gameId}&t=${new Date().getTime()}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    
                    const state = await response.json();
                    if (!state) return;

                    isClockRunning = state.running; // Update running state first

                    // Update game clock based on whether it's running
                    if (state.running) {
                        const elapsedMs = state.current_server_time - state.last_updated_at;
                        gameClockMs = Math.max(0, state.game_clock - elapsedMs);
                    } else {
                        gameClockMs = state.game_clock; // Use exact value if paused
                    }

                    // Handle quarter changes - RELOAD is important here
                    if (state.quarter_id !== currentQuarter) {
                        clearInterval(pollingInterval); 
                        clearInterval(localTimerInterval); // Stop local timer too
                        alert('Quarter has changed. Refreshing the page to sync.');
                        location.reload();
                        return; // Stop further processing after reload command
                    }
                   
                    // Update fouls 
                    if (state.fouls) {
                        teamFouls.teamA = state.fouls.home[state.quarter_id] || 0;
                        teamFouls.teamB = state.fouls.away[state.quarter_id] || 0;
                        document.getElementById('foulsA').textContent = teamFouls.teamA;
                        document.getElementById('foulsB').textContent = teamFouls.teamB;
                        updateBonusUI(); 
                    }

                    // Update timeouts
                    let timeoutPeriod;
                    if (state.quarter_id <= 2) timeoutPeriod = 1;
                    else if (state.quarter_id <= 4) timeoutPeriod = 2;
                    else timeoutPeriod = state.quarter_id;

                    let defaultTimeoutsA = (timeoutPeriod === 1) ? 2 : (timeoutPeriod === 2 ? 3 : 1);
                    let defaultTimeoutsB = (timeoutPeriod === 1) ? 2 : (timeoutPeriod === 2 ? 3 : 1);
                    
                    if(state.timeouts) {
                         document.getElementById('timeoutsA').textContent = state.timeouts.home[timeoutPeriod] ?? defaultTimeoutsA;
                         document.getElementById('timeoutsB').textContent = state.timeouts.away[timeoutPeriod] ?? defaultTimeoutsB;
                         // Disable button if timeouts are 0 or less
                         document.getElementById('timeoutsA').disabled = (state.timeouts.home[timeoutPeriod] ?? defaultTimeoutsA) <= 0;
                         document.getElementById('timeoutsB').disabled = (state.timeouts.away[timeoutPeriod] ?? defaultTimeoutsB) <= 0;
                    }

                    // **NEW**: Update the indicator display
                    updateIndicatorDisplay();

                    // Scores are updated via updateRunningScore() on stat changes, not needed here.

                    // Restart local timer if necessary
                     runLocalTimer();

                } catch (error) { 
                    console.error('State polling error:', error); 
                    clearInterval(localTimerInterval); // Stop local timer on error
                }
            }
             // **NEW**: Function to manage the local timer interval
             function runLocalTimer() {
                 if (localTimerInterval) clearInterval(localTimerInterval); // Clear existing interval first

                 if (isClockRunning) {
                     localTimerInterval = setInterval(() => {
                         if (isClockRunning) { // Double check inside interval
                             gameClockMs = Math.max(0, gameClockMs - 100);
                             updateIndicatorDisplay(); // Update indicator clock

                             if (gameClockMs === 0) {
                                 isClockRunning = false; // Stop locally immediately
                                 clearInterval(localTimerInterval);
                                 // Fetch state again quickly to confirm server state and update UI (buttons etc.)
                                 fetchAndApplyState(); 
                             }
                         } else {
                              clearInterval(localTimerInterval); // Stop if state changed
                         }
                     }, 100);
                 }
             }

            window.addEventListener("DOMContentLoaded", () => {
                renderTeam("teamA"); renderTeam("teamB");
                updateBonusUI();
                renderInitialLog(); 
                updateRunningScore("teamA"); 
                updateRunningScore("teamB");
                updateIndicatorDisplay(); // Set initial indicator display

                if (gameData.gameStatus === 'Final') {
                    document.querySelectorAll('input, button').forEach(el => {
                        if (!el.textContent.includes("Undo") && !el.textContent.includes("Redo")) { el.disabled = true; }
                    });
                     // Disable foul popover buttons if game is final
                     document.querySelectorAll('#foulPopover button').forEach(btn => btn.disabled = true);
                }
                
                document.querySelectorAll(".timeout-click").forEach(el => {
                    el.addEventListener("click", async () => {
                        if (gameData.gameStatus === 'Final' || el.disabled) return; // Prevent clicking disabled buttons

                        const teamKey = el.dataset.team;
                        const teamId = teamKey === 'A' ? gameData.teamA.id : gameData.teamB.id;
                        let timeoutPeriod;
                        if (currentQuarter <= 2) timeoutPeriod = 1;
                        else if (currentQuarter <= 4) timeoutPeriod = 2;
                        else timeoutPeriod = currentQuarter; // OT quarters count individually for timeouts
                        
                        if (confirm(`Use a timeout for ${gameData[teamKey === 'A' ? 'teamA' : 'teamB'].name}?`)) {
                            const response = await fetch("use_timeout.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ game_id: gameData.gameId, team_id: teamId, half: timeoutPeriod }) });
                            const result = await response.json();
                            if (result.success) {
                                logTimeoutAction(teamKey);
                                el.textContent = result.remaining;
                                if (result.remaining <= 0) el.disabled = true;
                            } else { alert("Failed to use timeout: " + (result.error || "Unknown error")); }
                        }
                    });
                });
                
                fetchAndApplyState(); // Fetch initial state and start timers/polling
                pollingInterval = setInterval(fetchAndApplyState, 100); // Poll every 3 seconds
            });
        </script>

    <?php else: ?>
        <div class="container-message">
            <h1>Game Not Ready</h1>
            <p>This game cannot be managed because one or both teams have not been assigned yet.</p>
            <?php if (!empty($game['category_id'])): ?>
                <p><a href="category_details.php?category_id=<?php echo htmlspecialchars($game['category_id']); ?>&tab=schedule">Return to Schedule</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>