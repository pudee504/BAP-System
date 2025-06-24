<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

$game_id = $_GET['game_id'] ?? '';
if (!$game_id) {
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
  die("Game ID $game_id not found.");
}
if (!array_key_exists('hometeam_id', $game) || !$game['hometeam_id'] || !array_key_exists('awayteam_id', $game) || !$game['awayteam_id']) {
  die("Invalid game: teams not assigned. Game data: " . var_export($game, true));
}

// Populate player_game with display_order
$stmt = $pdo->prepare("
  INSERT IGNORE INTO player_game (player_id, game_id, team_id, jersey_number, is_playing, display_order)
  SELECT pt.player_id, ?, pt.team_id, NULL, 0, pt.player_id
  FROM player_team pt
  WHERE pt.team_id IN (?, ?)
");
$stmt->execute([$game_id, $game['hometeam_id'], $game['awayteam_id']]);

// Fetch players for home team
$stmt = $pdo->prepare("
  SELECT p.id, p.first_name, p.last_name, pg.jersey_number, pg.is_playing, pg.display_order
  FROM player p
  JOIN player_team pt ON p.id = pt.player_id
  LEFT JOIN player_game pg ON p.id = pg.player_id AND pg.game_id = ? AND pg.team_id = ?
  WHERE pt.team_id = ?
  ORDER BY pg.display_order ASC
");
$stmt->execute([$game_id, $game['hometeam_id'], $game['hometeam_id']]);
$home_players = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch players for away team
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
    .score-line { display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 5px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { border: 1px solid #ddd; padding: 4px; text-align: center; position: relative; }
    th { background-color: #f2f2f2; }
    .stat-cell:hover .stat-controls { display: inline-flex; }
    .stat-controls { display: none; gap: 4px; position: absolute; right: 4px; top: 2px; }
    .stat-controls button { font-size: 12px; padding: 1px 5px; }
    .jersey-input { width: 50px; text-align: center; }
    .in-game-checkbox { cursor: pointer; }
    tr:has(.in-game-checkbox:checked) { background-color: #e0ffe0; }
  </style>
</head>
<body>

<h1>Game Statistics - <?php echo htmlspecialchars($game['home_team_name']); ?> vs <?php echo htmlspecialchars($game['away_team_name']); ?></h1>
<div class="teams-container">
  <div class="team-box" id="teamA">
    <div class="team-header"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
    <div class="score-line">
      <span>Running Score: <span id="scoreA">0</span></span>
      <span>Team Fouls: <span id="foulsA">0</span></span>
      <span>Timeouts: <span id="timeoutsA">0</span></span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width: 30px;">In</th>
          <th style="width: 50px;">#</th>
          <th>Name</th>
          <th>1PM</th><th>2PM</th><th>3PM</th>
          <th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th>
          <th>PTS</th>
        </tr>
      </thead>
      <tbody id="teamA-players"></tbody>
    </table>
  </div>
  <div class="team-box" id="teamB">
    <div class="team-header"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
    <div class="score-line">
      <span>Running Score: <span id="scoreB">0</span></span>
      <span>Team Fouls: <span id="foulsB">0</span></span>
      <span>Timeouts: <span id="timeoutsB">0</span></span>
    </div>
    <table>
      <thead>
        <tr>
          <th style="width: 30px;">In</th>
          <th style="width: 50px;">#</th>
          <th>Name</th>
          <th>1PM</th><th>2PM</th><th>3PM</th>
          <th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th>
          <th>PTS</th>
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
    stats: { '1PM': 0, '2PM': 0, '3PM': 0, REB: 0, AST: 0, BLK: 0, STL: 0, TO: 0 }
  })),
  teamB: gameData.teamB.players.map(p => ({
    id: p.id,
    jersey: p.jersey_number ?? '--',
    name: `${p.first_name} ${p.last_name}`,
    isPlaying: p.is_playing ?? 0,
    displayOrder: p.display_order ?? 999999,
    stats: { '1PM': 0, '2PM': 0, '3PM': 0, REB: 0, AST: 0, BLK: 0, STL: 0, TO: 0 }
  }))
};

// Track number of players in game
const inGameCounts = {
  teamA: playerStats.teamA.filter(p => p.isPlaying).length,
  teamB: playerStats.teamB.filter(p => p.isPlaying).length
};

// Track recently unchecked players
const uncheckedQueue = {
  teamA: [],
  teamB: []
};

function calculatePoints(stats) {
  return stats['1PM'] * 1 + stats['2PM'] * 2 + stats['3PM'] * 3;
}

function updateRunningScore(teamId) {
  const players = playerStats[teamId];
  const total = players.reduce((sum, p) => sum + calculatePoints(p.stats), 0);
  document.getElementById(`score${teamId.charAt(4).toUpperCase()}`).textContent = total;
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
      ${['1PM','2PM','3PM','REB','AST','BLK','STL','TO'].map(stat => `
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
  console.log(`Rendered ${teamId}:`, playerStats[teamId].map(p => ({ name: p.name, isPlaying: p.isPlaying, displayOrder: p.displayOrder })), `Unchecked Queue:`, uncheckedQueue[teamId]);
}

function updatePlayerOrder(teamId) {
  const players = playerStats[teamId];
  // Update displayOrder in playerStats
  players.forEach((player, idx) => {
    player.displayOrder = idx;
  });

  // Send order to server
  fetch('update_player_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      order: players.map(p => p.id)
    })
  }).catch(error => console.error('Error updating player order:', error));
}

function togglePlayer(teamId, playerIdx, isChecked) {
  const player = playerStats[teamId][playerIdx];
  const players = playerStats[teamId];
  const currentCount = inGameCounts[teamId];

  if (isChecked) {
    if (currentCount >= 5) {
      alert(`Cannot have more than 5 players in the game for ${gameData[teamId].name}!`);
      document.querySelector(`#${teamId}-players tr:nth-child(${playerIdx + 1}) .in-game-checkbox`).checked = false;
      return;
    }

    // Update state
    player.isPlaying = 1;
    inGameCounts[teamId]++;

    // Move checked player to bottom of in-game players (or top if <5 in-game)
    if (playerIdx >= 5) { // Only move if not already in top 5
      const [movedPlayer] = players.splice(playerIdx, 1);
      const inGameCount = players.filter(p => p.isPlaying).length;
      const insertIdx = Math.min(inGameCount - 1, 4); // Insert after last in-game player, max index 4
      players.splice(insertIdx, 0, movedPlayer);
    }

    // If there are unchecked players in the queue, move the oldest to the bottom
    if (uncheckedQueue[teamId].length > 0) {
      const uncheckedPlayerId = uncheckedQueue[teamId].shift();
      const uncheckedIdx = players.findIndex(p => p.id === uncheckedPlayerId);
      if (uncheckedIdx >= 0 && uncheckedIdx < 5) { // Only move if in top 5
        const [movedUnchecked] = players.splice(uncheckedIdx, 1);
        players.push(movedUnchecked);
      }
    }
  } else {
    // Add to unchecked queue instead of moving immediately
    player.isPlaying = 0;
    inGameCounts[teamId]--;
    uncheckedQueue[teamId].push(player.id);
  }

  // Send is_playing update to server
  fetch('update_player_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      player_id: player.id,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      is_playing: player.isPlaying
    })
  }).catch(error => console.error('Error updating player status:', error));

  // Update player order
  updatePlayerOrder(teamId);

  console.log(`Toggle ${teamId}:`, { player: player.name, isChecked, playerIdx, inGameCount: inGameCounts[teamId], uncheckedQueue: uncheckedQueue[teamId] });
  renderTeam(teamId);
}

function updateJersey(teamId, playerIdx, jersey) {
  const player = playerStats[teamId][playerIdx];
  player.jersey = jersey || '--';

  fetch('update_player_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      game_id: gameData.gameId,
      player_id: player.id,
      team_id: teamId === 'teamA' ? gameData.teamA.id : gameData.teamB.id,
      jersey_number: jersey || null
    })
  }).catch(error => console.error('Error updating jersey number:', error));

  renderTeam(teamId);
}

function updateStat(teamId, playerIdx, stat, delta) {
  const player = playerStats[teamId][playerIdx];
  const action = delta > 0 ? 'Add' : 'Remove';
  const confirmMsg = `${action} ${stat} ${delta > 0 ? 'to' : 'from'} ${player.name}?`;
  
  if (!window.confirm(confirmMsg)) {
    return; // Cancel if user doesn't confirm
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
  }).catch(error => console.error('Error updating stat:', error));

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