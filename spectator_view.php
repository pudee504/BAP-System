<?php
// FILENAME: spectator_view.php
// DESCRIPTION: Public-facing, read-only view of a live game's state.
// Primarily displays scores, player stats, timeouts, and fouls fetched via JavaScript.

require_once 'db.php'; // Database connection

$game_id = $_GET['game_id'] ?? ''; // Get game ID from URL
if (!$game_id) { die("Invalid game ID."); }

// Fetch initial game details (team names) for the header.
$stmt = $pdo->prepare("
    SELECT g.*, t1.team_name AS home_team_name, t2.team_name AS away_team_name
    FROM game g
    LEFT JOIN team t1 ON g.hometeam_id = t1.id
    LEFT JOIN team t2 ON g.awayteam_id = t2.id
    WHERE g.id = ?
");
$stmt->execute([$game_id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) { die("Game not found."); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spectator View - <?php echo htmlspecialchars($game['home_team_name']); ?> vs <?php echo htmlspecialchars($game['away_team_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/game.css"> <style>
        /* Base styles */
        body { background-color: #f0f2f5; }
        
        /* Main container for the spectator view */
        .game-manager-container {
            max-width: 900px; margin: 1rem auto; border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden;
        }
        
        /* Sticky Scoreboard Header */
        .score-display {
            position: -webkit-sticky; position: sticky; /* Make it stick to the top */
            top: 0; z-index: 1000; background-color: var(--bap-blue); color: white;
            padding: 0.75rem 1rem; display: flex; flex-direction: column; /* Stack scoreline and quarter */
            align-items: center; gap: 0.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        /* Wrapper for the main score line (TeamA Score - Score TeamB) */
        .scoreboard-main { display: flex; justify-content: space-between; align-items: center; width: 100%; }
        .team-name { font-size: 1.2rem; font-weight: bold; }
        .score { font-size: 2.2rem; font-weight: bold; }
        .separator { font-size: 2rem; }

        /* Quarter display below the main score */
        .quarter-display {
            font-size: 0.85rem; font-weight: bold; text-transform: uppercase;
            background-color: rgba(0, 0, 0, 0.2); padding: 2px 10px; border-radius: 12px; display: inline-block;
        }

        /* Styling for team boxes and headers */
        .team-box { background-color: #ffffff; }
        .team-table-title, .team-stats-header {
            font-size: 1.1rem; font-weight: bold; padding: 0.75rem 1rem;
            background-color: #fafafa; border-bottom: 1px solid #e9e9e9;
        }
        .team-stats-header { font-size: 0.9rem; padding: 0.6rem 1rem; }
        
        /* Table styling for player stats */
        .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; min-width: 750px; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 0.75rem 0.5rem; text-align: center; white-space: nowrap; font-weight: bold; border-right: 1px solid #f0f0f0; }
        th:last-child, td:last-child { border-right: none; }

        /* Sticky header row */
        thead th { position: -webkit-sticky; position: sticky; top: 0; background-color: #e9ecef; z-index: 2; }

        /* Sticky first two columns (Jersey #, Name) */
        td:first-child, td:nth-child(2) { position: -webkit-sticky; position: sticky; z-index: 1; }
        
        /* Alternating row colors */
        .row-even td { background-color: #ffffff; }
        .row-odd td { background-color: #f9f9f9; }

        /* Ensure sticky headers/columns overlap correctly */
        th:first-child, th:nth-child(2) { z-index: 3; }
        th:first-child, td:first-child { left: 0; } /* Stick to left edge */
        th:nth-child(2), td:nth-child(2) { left: 45px; } /* Stick next to the first column */

        /* Fixed column widths */
        th:first-child, td:first-child { width: 45px !important; } /* Jersey */
        th:nth-child(2), td:nth-child(2) { width: 150px !important; text-align: left !important; } /* Name */
        th:nth-child(3), td:nth-child(3) { width: 50px !important; } /* 1PT */
        th:nth-child(4), td:nth-child(4) { width: 50px !important; } /* 2PT */
        th:nth-child(5), td:nth-child(5) { width: 50px !important; } /* 3PT */
        th:nth-child(6), td:nth-child(6) { width: 55px !important; } /* FOUL */
        th:nth-child(7), td:nth-child(7) { width: 50px !important; } /* REB */
        th:nth-child(8), td:nth-child(8) { width: 50px !important; } /* AST */
        th:nth-child(9), td:nth-child(9) { width: 50px !important; } /* BLK */
        th:nth-child(10), td:nth-child(10) { width: 50px !important; } /* STL */
        th:nth-child(11), td:nth-child(11) { width: 50px !important; } /* TO */
        th:nth-child(12), td:nth-child(12) { width: 55px !important; } /* PTS */

        /* Responsive adjustments for smaller screens */
        @media (max-width: 900px) {
            .game-manager-container { margin: 0; border-radius: 0; box-shadow: none; }
            .team-name { font-size: 1rem; }
            .score { font-size: 1.8rem; }
        }
    </style>
</head>
<body>

<div class="score-display">
    <div class="scoreboard-main">
        <div class="team-info home"><span class="team-name" id="home_team_name"><?php echo htmlspecialchars($game['home_team_name']); ?></span></div>
        <div class="scores">
            <span class="score" id="scoreA"><?php echo $game['hometeam_score'] ?? 0; ?></span>
            <span class="separator">—</span>
            <span class="score" id="scoreB"><?php echo $game['awayteam_score'] ?? 0; ?></span>
        </div>
        <div class="team-info away"><span class="team-name" id="away_team_name"><?php echo htmlspecialchars($game['away_team_name']); ?></span></div>
    </div>
    <div class="quarter-display" id="quarterDisplay">Q1</div> </div>

<div class="game-manager-container">
    <div class="team-box">
        <div class="team-table-title"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
        <div class="team-stats-header">
            <span>Timeouts: <span id="timeoutsA">0</span></span>
            <span>Team Fouls: <span id="foulsA">0</span> <strong id="bonus-teamA" class="bonus-indicator">Bonus</strong></span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Name</th><th>1PT</th><th>2PT</th><th>3PT</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead>
                <tbody id="team-home-players"></tbody> </table>
        </div>
    </div>
    <div class="team-box">
        <div class="team-table-title"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
        <div class="team-stats-header">
            <span>Timeouts: <span id="timeoutsB">0</span></span>
            <span>Team Fouls: <span id="foulsB">0</span> <strong id="bonus-teamB" class="bonus-indicator">Bonus</strong></span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>#</th><th>Name</th><th>1PT</th><th>2PT</th><th>3PT</th><th>FOUL</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th><th>TO</th><th>PTS</th></tr></thead>
                <tbody id="team-away-players"></tbody> </table>
        </div>
    </div>
</div>

<script>
    const gameId = <?php echo json_encode($game_id); ?>;
    const homeTeamId = <?php echo json_encode($game['hometeam_id']); ?>;

    /** Formats the quarter number into display text (Q1, OT1, etc.). */
    function formatQuarter(quarter) {
        if (quarter > 4) { return `OT${quarter - 4}`; }
        return `Q${quarter}`;
    }

    /** Renders the player stat rows into the appropriate team table. */
    function renderPlayers(players) {
        const homeTbody = document.getElementById('team-home-players');
        const awayTbody = document.getElementById('team-away-players');
        homeTbody.innerHTML = ''; // Clear previous rows
        awayTbody.innerHTML = ''; // Clear previous rows
        
        players.forEach((p, index) => {
            // Calculate total points for the player.
            const totalPts = (Number(p['1PM']) * 1) + (Number(p['2PM']) * 2) + (Number(p['3PM']) * 3);
            const rowClass = index % 2 === 0 ? 'row-even' : 'row-odd'; // Alternating row colors

            // Construct the HTML for the player row.
            const playerRow = `
                <tr class="${rowClass}">
                    <td>${p.jersey_number || '--'}</td>
                    <td class="name-cell">${p.last_name.toUpperCase()}, ${p.first_name.charAt(0).toUpperCase()}</td>
                    <td>${p['1PM']}</td>
                    <td>${p['2PM']}</td>
                    <td>${p['3PM']}</td>
                    <td>${p['FOUL']}</td>
                    <td>${p['REB']}</td>
                    <td>${p['AST']}</td>
                    <td>${p['BLK']}</td>
                    <td>${p['STL']}</td>
                    <td>${p['TO']}</td>
                    <td class="total-points">${totalPts}</td>
                </tr>
            `;
            // Append the row to the correct team's table body.
            if (p.team_id == homeTeamId) {
                homeTbody.innerHTML += playerRow;
            } else {
                awayTbody.innerHTML += playerRow;
            }
        });
    }

    /** Fetches the latest game state from the API and updates the UI elements. */
    async function fetchAndUpdate() {
        try {
            // Fetch data from the get_game_state.php endpoint.
            const response = await fetch(`get_game_state.php?game_id=${gameId}`);
            const data = await response.json();

            if (data.success) {
                // Update quarter display.
                document.getElementById('quarterDisplay').textContent = formatQuarter(data.current_quarter);
                // Update scores.
                document.getElementById('scoreA').textContent = data.scores.home;
                document.getElementById('scoreB').textContent = data.scores.away;
                
                // Update home team stats (timeouts, fouls, bonus).
                document.getElementById('timeoutsA').textContent = data.team_stats.home_timeouts;
                document.getElementById('foulsA').textContent = data.team_stats.home_fouls;
                document.getElementById('bonus-teamA').style.display = data.team_stats.away_fouls >= 5 ? 'inline' : 'none'; // Bonus if opponent has 5+ fouls

                // Update away team stats (timeouts, fouls, bonus).
                document.getElementById('timeoutsB').textContent = data.team_stats.away_timeouts;
                document.getElementById('foulsB').textContent = data.team_stats.away_fouls;
                document.getElementById('bonus-teamB').style.display = data.team_stats.home_fouls >= 5 ? 'inline' : 'none'; // Bonus if opponent has 5+ fouls
                
                // Re-render player tables with updated stats.
                renderPlayers(data.players);
            }
        } catch (error) {
            console.error('Failed to fetch game state:', error);
        }
    }
    
    // --- Initial Load & Polling ---
    document.addEventListener('DOMContentLoaded', () => {
        fetchAndUpdate(); // Fetch data immediately on load
        setInterval(fetchAndUpdate, 1000); // Poll for updates every 1 second
    });
</script>

</body>
</html>