<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

$game_id = $_GET['game_id'] ?? '';
if (!$game_id) {
  error_log("No game_id provided in start_game.php");
  die("Invalid game ID.");
}

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
$stmt = $pdo->prepare("
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
");
$stmt->execute([$game_id, $game['hometeam_id'], $game['hometeam_id']]);
$home_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt->execute([$game_id, $game['awayteam_id'], $game['awayteam_id']]);
$away_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update game status
$pdo->prepare("UPDATE game SET game_status = 'Active' WHERE id = ?")->execute([$game_id]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Basketball Game Box Score - Game #<?php echo htmlspecialchars($game_id); ?></title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .teams-container { display: flex; justify-content: space-between; gap: 20px; }
    .team-box { width: 49%; border: 1px solid #ccc; border-radius: 8px; padding: 10px; }
    .team-header { font-weight: bold; text-align: center; margin-bottom: 10px; }
    .score-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: bold;
  margin-bottom: 5px;
}

.score-value {
  font-size: 24px;
  font-weight: bold;
  color: #333;
}

    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { border: 1px solid #ddd; padding: 4px; text-align: center; position: relative; }
    th { background-color: #f2f2f2; }
    .stat-cell:hover .stat-controls { display: inline-flex; }
    .stat-controls { display: none; gap: 4px; position: absolute; right: 4px; top: 2px; }
    .stat-controls button { font-size: 12px; padding: 1px 5px; }
    .jersey-input { width: 50px; text-align: center; }
    .in-game-checkbox { cursor: pointer; }
    tr:has(.in-game-checkbox:checked) { background-color: #e0ffe0; }
    .shared-score {
  text-align: center;
  font-size: 32px;
  font-weight: bold;
  margin-bottom: 30px;
}

.score-display {
  position: sticky;
  top: 0;
  z-index: 999;
  background: white;
  display: flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid #ccc;
  font-size: 24px;
  font-weight: bold;
  text-align: center;
}

.score-display .team-name {
  flex: 1 1 auto;
  max-width: 150px;
  padding: 0 8px;
  white-space: nowrap;
}

.score-display .score,
.score-display .separator {
  min-width: 30px;
  flex-shrink: 0;
  font-size: 32px;
}

  </style>
</head>
<body>

<div class="score-display">
  <span class="team-name"><?php echo htmlspecialchars($game['home_team_name']); ?></span>
  <span class="score" id="scoreA">0</span>
  <span class="separator">—</span>
  <span class="score" id="scoreB">0</span>
  <span class="team-name"><?php echo htmlspecialchars($game['away_team_name']); ?></span>
</div>




<div class="teams-container">
  <div class="team-box" id="teamA">
    

    <div class="score-line">
  <span>Timeouts: <span id="timeoutsA">0</span></span>
  <span>Team Fouls: <span id="foulsA">0</span></span>
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
      <tbody id="teamA-players"></tbody>
    </table>
  </div>
  <div class="team-box" id="teamB">
    
   <div class="score-line">
  <span>Timeouts: <span id="timeoutsB">0</span></span>
  <span>Team Fouls: <span id="foulsB">0</span></span>
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

<script>
const gameData = {
  gameId: <?php echo json_encode($game_id); ?>,
  teamA: {
    id: <?php echo json_encode($game['hometeam_id']); ?>,
    name: <?php echo json_encode($game['home_team_name']); ?>,
    players: <?php echo json_encode($home_players); ?>
  },
  teamB: {
    id: <?php echo json_encode($game['awayteam_id']); ?>,
    name: <?php echo json_encode($game['away_team_name']); ?>,
    players: <?php echo json_encode($away_players); ?>
  }
};

console.log('Game Data:', gameData);

const playerStats = {
  teamA: gameData.teamA.players.map(p => ({
    id: p.id,
    jersey: p.jersey_number ?? '--',
    name: `${p.first_name} ${p.last_name}`,
    isPlaying: p.is_playing ?? 0,
    displayOrder: p.display_order ?? 999999,
    stats: {
      '1PM': Number(p['1PM']) || 0,
      '2PM': Number(p['2PM']) || 0,
      '3PM': Number(p['3PM']) || 0,
      'REB': Number(p['REB']) || 0,
      'AST': Number(p['AST']) || 0,
      'BLK': Number(p['BLK']) || 0,
      'STL': Number(p['STL']) || 0,
      'TO': Number(p['TO']) || 0,
      'FOUL': Number(p['FOUL']) || 0

    }
  })),
  teamB: gameData.teamB.players.map(p => ({
    id: p.id,
    jersey: p.jersey_number ?? '--',
    name: `${p.first_name} ${p.last_name}`,
    isPlaying: p.is_playing ?? 0,
    displayOrder: p.display_order ?? 999999,
    stats: {
      '1PM': Number(p['1PM']) || 0,
      '2PM': Number(p['2PM']) || 0,
      '3PM': Number(p['3PM']) || 0,
      'REB': Number(p['REB']) || 0,
      'AST': Number(p['AST']) || 0,
      'BLK': Number(p['BLK']) || 0,
      'STL': Number(p['STL']) || 0,
      'TO': Number(p['TO']) || 0,
      'FOUL': Number(p['FOUL']) || 0
      
    }
  }))
};

// Track number of players in game
const inGameCounts = {
  teamA: playerStats.teamA.filter(p => p.isPlaying).length,
  teamB: playerStats.teamB.filter(p => p.isPlaying).length
};

function calculatePoints(stats) {
  return stats['1PM'] * 1 + stats['2PM'] * 2 + stats['3PM'] * 3;
}

function updateRunningScore(teamId) {
  const players = playerStats[teamId];
  const total = players.reduce((sum, p) => sum + calculatePoints(p.stats), 0);
  document.getElementById(teamId === 'teamA' ? 'scoreA' : 'scoreB').textContent = total;
}

function renderTeam(teamId) {
  const tbody = document.getElementById(`${teamId}-players`);
  tbody.innerHTML = '';

  playerStats[teamId].forEach((player, idx) => {
    const tr = document.createElement('tr');
    const stats = player.stats;
    const totalPts = calculatePoints(stats);

    tr.innerHTML = `
      <td><input type="checkbox" class="in-game-checkbox" ${player.isPlaying ? 'checked' : ''} onchange="togglePlayer('${teamId}', ${idx}, this.checked)"></td>
      <td><input type="text" class="jersey-input" value="${player.jersey}" onchange="updateJersey('${teamId}', ${idx}, this.value)"></td>
      <td>${player.name}</td>
      ${['1PM','2PM','3PM','FOUL','REB','AST','BLK','STL','TO'].map(stat => `
        <td class="stat-cell" onmouseover="showButtons(this)" onmouseleave="hideButtons(this)">
          ${stats[stat]}<span class="stat-controls">
            <button onclick="updateStat('${teamId}', ${idx}, '${stat}', 1)">+</button>
            <button onclick="updateStat('${teamId}', ${idx}, '${stat}', -1)">-</button>
          </span>
        </td>`).join('')}
      <td id="pts-${teamId}-${idx}">${totalPts}</td>
    `;
    tbody.appendChild(tr);
  });

  updateRunningScore(teamId);
  console.log(`Rendered ${teamId}:`, playerStats[teamId].map(p => ({ name: p.name, isPlaying: p.isPlaying, displayOrder: p.displayOrder, stats: p.stats })));
}

function updatePlayerOrder(teamId) {
  const players = playerStats[teamId];
  players.forEach((player, idx) => {
    player.displayOrder = idx;
  });

  console.log(`Updating order for ${teamId}:`, players.map(p => ({ id: p.id, name: p.name, displayOrder: p.displayOrder })));

  fetch('update_player_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      order: players.map(p => p.id)
    })
  }).then(response => response.json()).then(data => {
    if (!data.success) {
      console.error(`Failed to update order for ${teamId}:`, data.error);
    } else {
      console.log(`Order updated successfully for ${teamId}`);
    }
  }).catch(error => console.error(`Error updating order for ${teamId}:`, error));
}

function togglePlayer(teamId, playerIdx, isChecked) {
  const players = playerStats[teamId];
  const player = players[playerIdx];

  const inGamePlayers = players.filter(p => p.isPlaying);
  const totalInGame = inGamePlayers.length;

  if (isChecked) {
    if (totalInGame >= 5) {
      // Prevent selecting more than 5
      document.querySelector(`#${teamId}-players tr:nth-child(${playerIdx + 1}) .in-game-checkbox`).checked = false;
      alert(`Only 5 players can be in the game for ${gameData[teamId].name}.`);
      return;
    }

    player.isPlaying = 1;
  } else {
    player.isPlaying = 0;
  }

  // Re-sort: all checked (in-game) players go to the top
  const reordered = [
    ...players.filter(p => p.isPlaying),
    ...players.filter(p => !p.isPlaying)
  ];

  // Rebuild player list
  playerStats[teamId] = reordered;

  // Update DB
  fetch('update_player_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      player_id: player.id,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      is_playing: player.isPlaying
    })
  }).then(response => response.json()).then(data => {
    if (!data.success) {
      console.error(`Failed to update status for ${player.name}:`, data.error);
    }
  }).catch(error => console.error(`Error updating status for ${player.name}:`, error));

  updatePlayerOrder(teamId);
  renderTeam(teamId);
}




function updateStat(teamId, playerIdx, stat, delta) {
  const player = playerStats[teamId][playerIdx];
  const action = delta > 0 ? 'Add' : 'Remove';
  const confirmMsg = `${action} ${stat} ${delta > 0 ? 'to' : 'from'} ${player.name}?`;
  
  if (!window.confirm(confirmMsg)) {
    return;
  }

  player.stats[stat] = Math.max(0, player.stats[stat] + delta);

  fetch('update_stat.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      player_id: player.id,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      statistic_name: stat,
      value: delta
    })
  }).then(response => response.json()).then(data => {
    if (!data.success) {
      console.error(`Failed to update stat for ${player.name}:`, data.error);
    }
  }).catch(error => console.error(`Error updating stat for ${player.name}:`, error));

  renderTeam(teamId);
}

function showButtons(cell) {
  const btns = cell.querySelector('.stat-controls');
  if (btns) btns.style.display = 'inline-flex';
}
function hideButtons(cell) {
  const btns = cell.querySelector('.stat-controls');
  if (btns) btns.style.display = 'none';
}

renderTeam('teamA');
renderTeam('teamB');
</script>

</body>
</html>