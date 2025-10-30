<?php
// FILENAME: scoreboard.php
// DESCRIPTION: Public-facing scoreboard display for a single game.
// Fetches game state via JavaScript and updates in real-time.

require_once 'db.php';
$game_id = $_GET['game_id'] ?? '';
if (!$game_id) die("Invalid game ID.");

// Fetch team names for initial display.
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
        /* Orbitron font for digital clock style */
        @font-face {
            font-display: swap; font-family: 'Orbitron'; font-style: normal; font-weight: 900;
            src: url('./fonts/orbitron-v35-latin-900.woff2') format('woff2');
        }

        /* Basic layout and typography */
        html, body {
            margin: 0; padding: 0; height: 100%; width: 100%; background-color: #000; color: #fff;
            font-family: 'Orbitron', sans-serif; font-weight: 900; overflow: hidden; text-transform: uppercase;
        }
        
        /* Add stroke for better readability on various backgrounds */
        .team-name, .team-score, #gameClock, #quarterLabel, #shotClock, .stat-label, .stat-value {
             -webkit-text-stroke: 1px black; text-stroke: 1px black; paint-order: stroke fill;       
        }

        /* Main scoreboard grid layout */
        .scoreboard {
            display: grid; grid-template-columns: 1fr 1.2fr 1fr; /* Team | Center | Team */
            align-items: center; height: 100%; width: 100%; padding: 2vh 2vw; box-sizing: border-box;
        }

        /* Team sections (left and right) */
        .team {
            display: flex; flex-direction: column; justify-content: space-between;
            height: 100%; text-align: center;
        }
        .team-name { font-size: 6vh; line-height: 1.2; padding: 2vh 0; min-height: 15vh; display: flex; align-items: center; justify-content: center; }
        .team-score { font-size: 30vh; line-height: 1; flex-grow: 1; display: flex; align-items: center; justify-content: center; }

        /* Center game info section */
        .game-info {
            text-align: center; display: flex; flex-direction: column;
            justify-content: space-between; height: 90%;
            border-left: 4px solid #fff; border-right: 4px solid #fff; /* Vertical separators */
        }
        #gameClock { font-size: 14vh; }
        #quarterLabel { font-size: 6vh; }
        #shotClock { font-size: 10vh; }

        /* Sub-info below team scores (Timeouts, Fouls) */
        .sub-info { display: flex; justify-content: space-evenly; padding-bottom: 2vh; }
        .stat-box { display: flex; flex-direction: column; align-items: center; }
        .stat-label { font-size: 4vh; }
        .stat-value { font-size: 6vh; }
        .bonus { /* Bonus indicator */
            color: #fff; font-size: 2.5vh; margin-top: 1vh; visibility: hidden; /* Hidden by default */
            -webkit-text-stroke: 1px red; text-stroke: 1px red;
        }
        .bonus.visible { visibility: visible; } /* Class to show bonus */

        /* Styles for the clock visibility toggle switch */
        .controls { position: absolute; top: 15px; right: 15px; z-index: 100; }
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #4CAF50; } /* Green when ON */
        input:checked + .slider:before { transform: translateX(20px); }
        .hidden { display: none !important; } /* Class to hide clocks */
    </style>
</head>
<body>

    <div class="controls">
        <label class="switch">
            <input type="checkbox" id="toggleClocksInput" checked> <span class="slider"></span>
        </label>
    </div>

    <div class="scoreboard">
        <div class="team home">
            <div class="team-name" id="home_team_name"><?php echo htmlspecialchars($game['home_team_name']); ?></div>
            <div class="team-score" id="scoreA">0</div>
            <div class="sub-info">
                <div class="stat-box"><span class="stat-label">TIMEOUTS</span><span class="stat-value" id="timeoutsA">0</span></div>
                <div class="stat-box"><span class="stat-label">FOULS</span><span class="stat-value" id="foulsA">0</span><span class="bonus" id="bonus-teamA">BONUS</span></div>
            </div>
        </div>
        <div class="game-info">
            <div id="gameClock">00:00.0</div>
            <div id="quarterLabel">1ST QUARTER</div>
            <div id="shotClock">24</div>
        </div>
        <div class="team away">
            <div class="team-name" id="away_team_name"><?php echo htmlspecialchars($game['away_team_name']); ?></div>
            <div class="team-score" id="scoreB">0</div>
            <div class="sub-info">
                <div class="stat-box"><span class="stat-label">TIMEOUTS</span><span class="stat-value" id="timeoutsB">0</span></div>
                <div class="stat-box"><span class="stat-label">FOULS</span><span class="stat-value" id="foulsB">0</span><span class="bonus" id="bonus-teamB">BONUS</span></div>
            </div>
        </div>
    </div>
    
    <script>
        const gameId = <?php echo json_encode($game_id); ?>;

        // --- Timer variables ---
        let localTimerInterval = null; // Interval for client-side clock decrement
        let isClockRunning = false;    // Tracks if clock is running based on server state
        let gameClockMs = 0;           // Current game clock time (milliseconds)
        let shotClockMs = 0;           // Current shot clock time (milliseconds)

        // --- Shot Clock Hold Logic ---
        let shotClockHeld = false;            // Tracks if the shot clock is manually held (2-click reset)
        let lastServerUpdateTimestamp = 0;  // Tracks the timestamp of the last server update

        /** Fetches the latest game state from the server and updates the UI. */
        async function fetchAndUpdateState() {
            try {
                // Fetch state from get_timer_state.php (includes cache busting).
                const response = await fetch(`get_timer_state.php?game_id=${gameId}&t=${new Date().getTime()}`);
                if (!response.ok) return; // Exit if fetch failed
                const state = await response.json();
                if (!state) return; // Exit if no state data

                // --- Shot Clock Hold Inference ---
                const wasClockRunning = isClockRunning; // Store previous running state
                const hasBeenUpdated = state.last_updated_at > lastServerUpdateTimestamp; // Did server data change?
                const isFullResetValue = state.shot_clock === 24000 || state.shot_clock === 14000; // Is it a standard reset value?

                // Toggle hold state ONLY if clock was running, is still running, server updated, and it's a full reset value.
                if (wasClockRunning && state.running && hasBeenUpdated && isFullResetValue) {
                    shotClockHeld = !shotClockHeld;
                }
                // Cancel hold if clock stops.
                else if (!state.running) {
                    shotClockHeld = false;
                }
                // Cancel hold if server updated with a non-reset value (manual adjust).
                else if (hasBeenUpdated && !isFullResetValue) {
                    shotClockHeld = false;
                }
                
                // Update last seen server timestamp if it changed.
                if (hasBeenUpdated) {
                    lastServerUpdateTimestamp = state.last_updated_at;
                }
                // --- End Shot Clock Hold Logic ---

                // --- Update Local Clock Variables ---
                isClockRunning = state.running;
                if (state.running) {
                    // Calculate elapsed time since last server update.
                    const elapsedMs = state.current_server_time - state.last_updated_at;
                    // Update local game clock based on server value minus elapsed time.
                    gameClockMs = Math.max(0, state.game_clock - elapsedMs);
                    
                    // Update local shot clock (unless held).
                    if (shotClockHeld) {
                        shotClockMs = state.shot_clock; // Keep server value if held
                    } else {
                        shotClockMs = Math.max(0, state.shot_clock - elapsedMs); // Decrement if not held
                    }
                } else {
                    // If server says clock is stopped, use exact server values.
                    gameClockMs = state.game_clock;
                    shotClockMs = state.shot_clock;
                }
                
                // --- Update UI Elements ---
                // Scores
                document.getElementById('scoreA').textContent = state.hometeam_score || 0;
                document.getElementById('scoreB').textContent = state.awayteam_score || 0;
                // Quarter Label
                document.getElementById('quarterLabel').textContent = state.quarter_id <= 4 ? 
                    `${['1ST', '2ND', '3RD', '4TH'][state.quarter_id - 1]} QUARTER` : `OT ${state.quarter_id - 4}`;
                
                // Timeouts (using defaults if no record exists for the period)
                let timeoutPeriod;
                if (state.quarter_id <= 2) timeoutPeriod = 1;      // 1st Half
                else if (state.quarter_id <= 4) timeoutPeriod = 2; // 2nd Half
                else timeoutPeriod = state.quarter_id;            // OT periods

                let defaultTimeouts = (timeoutPeriod === 1) ? 2 : (timeoutPeriod === 2 ? 3 : 1);

                document.getElementById('timeoutsA').textContent = state.timeouts.home[timeoutPeriod] ?? defaultTimeouts;
                document.getElementById('timeoutsB').textContent = state.timeouts.away[timeoutPeriod] ?? defaultTimeouts;
                
                // Fouls
                const homeFouls = state.fouls.home[state.quarter_id] || 0;
                const awayFouls = state.fouls.away[state.quarter_id] || 0;
                document.getElementById('foulsA').textContent = homeFouls;
                document.getElementById('foulsB').textContent = awayFouls;

                // Bonus Indicators (Team A is in bonus if Team B has 5+ fouls, etc.)
                document.getElementById('bonus-teamA').classList.toggle('visible', awayFouls >= 5);
                document.getElementById('bonus-teamB').classList.toggle('visible', homeFouls >= 5); // Corrected logic
                
                // Update clock displays immediately with potentially calculated values.
                updateDisplay();

            } catch (error) { console.error('Error fetching state:', error); }
        }

        /** Manages the local setInterval for visually decrementing clocks. */
        function runLocalTimer() {
            if (localTimerInterval) clearInterval(localTimerInterval); // Clear previous interval
            localTimerInterval = setInterval(() => {
                if (isClockRunning) { // Only run if the state is 'running'
                    gameClockMs = Math.max(0, gameClockMs - 100); // Decrement game clock
                    if (!shotClockHeld) { // Decrement shot clock unless held
                        shotClockMs = Math.max(0, shotClockMs - 100);
                    }
                    updateDisplay(); // Update visual clocks
                }
            }, 100); // Run every 100ms for smooth tenths display
        }

        /** Updates the game and shot clock elements in the HTML. */
        function updateDisplay() {
            document.getElementById('gameClock').textContent = formatGameTime(gameClockMs);
            document.getElementById('shotClock').textContent = formatShotTime(shotClockMs);
        }

        /** Formats milliseconds to MM:SS or MM:SS.T format for the game clock. */
        function formatGameTime(ms) {
            if (ms <= 0) return "00:00.0";
            const totalSeconds = Math.floor(ms / 1000);
            const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            const tenths = Math.floor((ms % 1000) / 100).toString();
            // Show tenths only when under 1 minute.
            return totalSeconds < 60 ? `${minutes}:${seconds}.${tenths}` : `${minutes}:${seconds}`;
        }

        /** Formats milliseconds to SS or SS.T format for the shot clock. */
        function formatShotTime(ms) {
            if (ms <= 0) return "0.0";
            const seconds = Math.floor(ms / 1000);
            const tenths = Math.floor((ms % 1000) / 100);
            // Show only whole seconds if >= 5 seconds and tenths are 0.
            if (seconds >= 5 && tenths === 0) {
                 return `${seconds}`;
            }
            // Otherwise, show tenths.
            return `${seconds}.${tenths}`;
        }

        // --- Initial Setup & Event Listeners ---
        document.addEventListener('DOMContentLoaded', () => {
            fetchAndUpdateState(); // Fetch initial state immediately
            setInterval(fetchAndUpdateState, 1000); // Poll server every 1 second
            runLocalTimer(); // Start the local visual timer

            // Clock Visibility Toggle Switch Logic
            const toggleInput = document.getElementById('toggleClocksInput');
            const gameClockEl = document.getElementById('gameClock');
            const shotClockEl = document.getElementById('shotClock');

            toggleInput.addEventListener('change', () => {
                // Add/remove 'hidden' class based on checkbox state.
                gameClockEl.classList.toggle('hidden', !toggleInput.checked);
                shotClockEl.classList.toggle('hidden', !toggleInput.checked);
            });
        });
    </script>
</body>
</html>