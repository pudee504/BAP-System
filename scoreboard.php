<?php
require_once 'db.php'; 
$game_id = $_GET['game_id'] ?? '';
if (!$game_id) die("Invalid game ID.");

$stmt = $pdo->prepare("SELECT t1.team_name AS home_team_name, t2.team_name AS away_team_name FROM game g LEFT JOIN team t1 ON g.hometeam_id = t1.id LEFT JOIN team t2 ON g.awayteam_id = t2.id WHERE g.id = ?");
$stmt->execute([$game_id]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$game) die("Game not found.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scoreboard - Game #<?php echo htmlspecialchars($game_id); ?></title>
    <style>
       /* orbitron-900 - latin */
        @font-face {
        font-display: swap;
        font-family: 'Orbitron';
        font-style: normal;
        font-weight: 900;
        src: url('./fonts/orbitron-v35-latin-900.woff2') format('woff2');
        }

        
        html, body {
            margin: 0; padding: 0; height: 100%; width: 100%; background-color: #000; color: #fff;
            font-family: 'Orbitron', sans-serif; font-weight: 900; overflow: hidden; text-transform: uppercase;
        }
        
        .team-name, .team-score, #gameClock, #quarterLabel, #shotClock, .stat-label, .stat-value {
             -webkit-text-stroke: 1px black; text-stroke: 1px black; paint-order: stroke fill;       
        }

        .scoreboard {
            display: grid; grid-template-columns: 1fr 1.2fr 1fr; align-items: center;
            height: 100%; width: 100%; padding: 2vh 2vw; box-sizing: border-box;
        }

        .team {
            display: flex; flex-direction: column; justify-content: space-between;
            height: 100%; text-align: center;
        }

        .team-name {
            font-size: 6vh; line-height: 1.2; padding: 2vh 0; min-height: 15vh; 
            display: flex; align-items: center; justify-content: center;
        }

        .team-score {
            font-size: 30vh; color: #fff; line-height: 1; flex-grow: 1;
            display: flex; align-items: center; justify-content: center;
        }

        .game-info {
            text-align: center; display: flex; flex-direction: column;
            justify-content: space-between; height: 90%;
            border-left: 4px solid #fff; border-right: 4px solid #fff;
        }

        

        #gameClock { font-size: 14vh; color: #fff; }
        #quarterLabel { font-size: 6vh; }
        #shotClock { font-size: 10vh; color: #fff; }
        .sub-info { display: flex; justify-content: space-evenly; padding-bottom: 2vh; }
        .stat-box { display: flex; flex-direction: column; align-items: center; }
        .stat-label { font-size: 4vh; }
        .stat-value { font-size: 6vh; }

        .bonus {
            color: #fff; font-size: 2.5vh; margin-top: 1vh; visibility: hidden; 
            -webkit-text-stroke: 1px red; text-stroke: 1px red;
        }
        .bonus.visible { visibility: visible; } 

        /* --- NEW STYLES FOR TOGGLE SWITCH --- */
        .controls {
            position: absolute;
            top: 15px;
            right: 15px; /* Moved to the right corner */
            z-index: 100;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 44px; /* Smaller size */
            height: 24px; /* Smaller size */
        }
        .switch input {
            opacity: 0; width: 0; height: 0;
        }
        .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute; content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #4CAF50; /* Green for "ON" */
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        .hidden { display: none !important; }
    </style>
</head>
<body>

    <div class="controls">
        <label class="switch">
            <input type="checkbox" id="toggleClocksInput" checked>
            <span class="slider"></span>
        </label>
    </div>

    <div class="scoreboard">
        <div class="team home">
            <div class="team-name" id="home_team_name"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
            <div class="team-score" id="scoreA">0</div>
            <div class="sub-info"><div class="stat-box"><span class="stat-label">TIMEOUTS</span><span class="stat-value" id="timeoutsA">0</span></div><div class="stat-box"><span class="stat-label">FOULS</span><span class="stat-value" id="foulsA">0</span><span class="bonus" id="bonus-teamA">BONUS</span></div></div>
        </div>
        <div class="game-info">
            <div id="gameClock">00:00.0</div><div id="quarterLabel">1ST QUARTER</div><div id="shotClock">24</div>
        </div>
        <div class="team away">
            <div class="team-name" id="away_team_name"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
            <div class="team-score" id="scoreB">0</div>
            <div class="sub-info"><div class="stat-box"><span class="stat-label">TIMEOUTS</span><span class="stat-value" id="timeoutsB">0</span></div><div class="stat-box"><span class="stat-label">FOULS</span><span class="stat-value" id="foulsB">0</span><span class="bonus" id="bonus-teamB">BONUS</span></div></div>
        </div>
    </div>
    
    <script>
        const gameId = <?php echo json_encode($game_id); ?>;

        // --- Timer variables ---
        let localTimerInterval = null;
        let isClockRunning = false;
        let gameClockMs = 0;
        let shotClockMs = 0;

        // --- State variables for inferring the "held" state ---
        let shotClockHeld = false;
        let lastServerUpdateTimestamp = 0;

        async function fetchAndUpdateState() {
            try {
                const response = await fetch(`get_timer_state.php?game_id=${gameId}&t=${new Date().getTime()}`);
                if (!response.ok) return;
                const state = await response.json();
                if (!state) return;

                // --- MODIFIED LOGIC ---
                // Keep track of the clock's state *before* this update.
                const wasClockRunning = isClockRunning; 

                const hasBeenUpdated = state.last_updated_at > lastServerUpdateTimestamp;
                const isFullResetValue = state.shot_clock === 24000 || state.shot_clock === 14000;

                // The two-click toggle should ONLY happen if the clock was already running.
                // This prevents a simple "Start" from incorrectly holding the shot clock.
                if (wasClockRunning && state.running && hasBeenUpdated && isFullResetValue) {
                    shotClockHeld = !shotClockHeld;
                }
                // If the clock stops, the hold is always cancelled.
                else if (!state.running) {
                    shotClockHeld = false;
                }
                // If the server was updated with a value that is NOT a full reset (e.g., manual adjustment), cancel the hold.
                else if (hasBeenUpdated && !isFullResetValue) {
                    shotClockHeld = false;
                }
                
                if (hasBeenUpdated) {
                    lastServerUpdateTimestamp = state.last_updated_at;
                }

                // --- Existing timer logic ---
                isClockRunning = state.running;
                if (state.running) {
                    const elapsedMs = state.current_server_time - state.last_updated_at;
                    gameClockMs = Math.max(0, state.game_clock - elapsedMs);
                    
                    if (shotClockHeld) {
                        shotClockMs = state.shot_clock;
                    } else {
                        shotClockMs = Math.max(0, state.shot_clock - elapsedMs);
                    }
                } else {
                    gameClockMs = state.game_clock;
                    shotClockMs = state.shot_clock;
                }
                
                // --- Update non-timer elements (no changes here) ---
                document.getElementById('scoreA').textContent = state.hometeam_score || 0;
                document.getElementById('scoreB').textContent = state.awayteam_score || 0;
                document.getElementById('quarterLabel').textContent = state.quarter_id <= 4 ? 
                    `${['1ST', '2ND', '3RD', '4TH'][state.quarter_id - 1]} QUARTER` : `OT ${state.quarter_id - 4}`;
                
                let timeoutPeriod;
                if (state.quarter_id <= 2) {
                    timeoutPeriod = 1; // 1st Half
                } else if (state.quarter_id <= 4) {
                    timeoutPeriod = 2; // 2nd Half
                } else {
                    timeoutPeriod = state.quarter_id; // OT periods (5, 6, etc.)
                }

                let defaultTimeouts;
                if (timeoutPeriod === 1) defaultTimeouts = 2;
                else if (timeoutPeriod === 2) defaultTimeouts = 3;
                else defaultTimeouts = 1; // OT

                document.getElementById('timeoutsA').textContent = state.timeouts.home[timeoutPeriod] ?? defaultTimeouts;
                document.getElementById('timeoutsB').textContent = state.timeouts.away[timeoutPeriod] ?? defaultTimeouts;
                
                const homeFouls = state.fouls.home[state.quarter_id] || 0;
                const awayFouls = state.fouls.away[state.quarter_id] || 0;
                document.getElementById('foulsA').textContent = homeFouls;
                document.getElementById('foulsB').textContent = awayFouls;

                document.getElementById('bonus-teamA').classList.toggle('visible', awayFouls >= 4);
// CORRECTED LOGIC: Team B is in bonus if Team A commits enough fouls
document.getElementById('bonus-teamB').classList.toggle('visible', homeFouls >= 4);
                
                updateDisplay();

            } catch (error) { console.error('Error fetching state:', error); }
        }

        // --- No changes to the functions below this line ---
        function runLocalTimer() {
            if (localTimerInterval) clearInterval(localTimerInterval);
            localTimerInterval = setInterval(() => {
                if (isClockRunning) {
                    gameClockMs = Math.max(0, gameClockMs - 100);
                    
                    if (!shotClockHeld) {
                        shotClockMs = Math.max(0, shotClockMs - 100);
                    }

                    updateDisplay();
                }
            }, 100);
        }

        function updateDisplay() {
            document.getElementById('gameClock').textContent = formatGameTime(gameClockMs);
            document.getElementById('shotClock').textContent = formatShotTime(shotClockMs);
        }

        function formatGameTime(ms) {
            if (ms <= 0) return "00:00.0";
            const totalSeconds = Math.floor(ms / 1000);
            const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            const tenths = Math.floor((ms % 1000) / 100).toString();
            return totalSeconds < 60 ? `${minutes}:${seconds}.${tenths}` : `${minutes}:${seconds}`;
        }

        function formatShotTime(ms) {
            if (ms <= 0) return "0.0";
            const seconds = Math.floor(ms / 1000);
            const tenths = Math.floor((ms % 1000) / 100);
            
            if (seconds >= 5 && tenths === 0) {
                 return `${seconds}`;
            }
            return `${seconds}.${tenths}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            fetchAndUpdateState();
            setInterval(fetchAndUpdateState, 100);
            runLocalTimer();

            // --- NEW: Simplified Toggle Switch Logic ---
            const toggleInput = document.getElementById('toggleClocksInput');
            const gameClockEl = document.getElementById('gameClock');
            const shotClockEl = document.getElementById('shotClock');

            toggleInput.addEventListener('change', () => {
                // When the switch is OFF (unchecked), add the 'hidden' class
                // When it's ON (checked), remove the 'hidden' class
                gameClockEl.classList.toggle('hidden', !toggleInput.checked);
                shotClockEl.classList.toggle('hidden', !toggleInput.checked);
            });
        });
    </script>
</body>
</html>