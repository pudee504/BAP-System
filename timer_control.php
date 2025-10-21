<?php
$game_id = $_GET['game_id'] ?? '';
if (!$game_id) { die("Invalid game ID."); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Timer Control</title>
    <style>
        /* ==========================================================================
           1. THEME VARIABLES & GENERAL RESET
           ========================================================================== */
        :root {
            --bap-blue: #1f2593;
            --bap-orange: #fc9e3e;
            --bap-yellow: #FFC107;
            --bap-red: #D81B60;
            --text-dark: #212529;
            --text-light: #f8f9fa;
            --bg-light: #ffffff;
            --bg-main: #f4f7fc;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-dark);
            margin: 0;
            padding: 1rem;
        }

        /* ==========================================================================
           2. MAIN CONTAINER & TYPOGRAPHY
           ========================================================================== */
        .container {
            max-width: 480px;
            margin: 0 auto;
            background: var(--bg-light);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        h1 {
            color: var(--bap-blue);
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .quarter {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: #6c757d;
            font-weight: 600;
        }

        .game-clock {
            font-size: 4.5em;
            font-weight: bold;
            font-family: "Courier New", monospace;
            color: var(--text-dark);
            letter-spacing: -2px;
        }

        .shot-clock {
            font-size: 3em;
            color: var(--bap-orange);
            font-family: "Courier New", monospace;
            font-weight: bold;
            margin-bottom: 1.5rem;
        }

        /* ==========================================================================
           3. BUTTONS & CONTROLS
           ========================================================================== */
        button {
            display: inline-block;
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            margin: 0.25rem;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        button:disabled {
            background-color: #e9ecef !important; /* Use important to override other styles */
            color: #6c757d !important;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        #toggleClockBtn {
            width: 90%;
            padding: 1rem;
            font-size: 1.2rem;
            background-color: #28a745; /* A clear green for "Start" */
            color: var(--text-light);
        }

        #toggleClockBtn.running {
            background-color: var(--bap-red);
        }
        
        .control-group button {
             background-color: var(--bap-blue);
             color: var(--text-light);
             width: calc(50% - 1rem); /* Creates a 2-column layout for buttons */
        }

        #finalizeGameBtn {
            background-color: var(--bap-orange);
            color: var(--text-dark);
            width: 90%;
        }

        /* ==========================================================================
           4. LAYOUT
           ========================================================================== */
        .main-controls {
            margin-bottom: 1.5rem;
        }

        .control-group {
            margin: 1.5rem 0 0 0;
            border-top: 1px solid var(--border-color);
            padding-top: 1.5rem;
        }

        .control-group h4 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: #6c757d;
            font-weight: 600;
        }

    </style>
</head>
<body>
    <div class="container">
        <h1>Game #<?php echo htmlspecialchars($game_id); ?> Control</h1>
        <div class="timer-panel">
            <div class="quarter" id="quarterLabel">Loading...</div>
            <div class="game-clock" id="gameClock">--:--</div>
            <div class="shot-clock" id="shotClock">--</div>
        </div>
        <div class="main-controls">
             <button id="toggleClockBtn" onclick="sendAction('toggle')">Start</button>
        </div>
        <div class="control-group">
            <h4>Possession</h4>
            <button onclick="handlePossessionReset(false)">Change Possesion (24s)</button>
            <button onclick="handlePossessionReset(true)">Same Posession (14s)</button>
        </div>
        <div class="control-group">
            <h4>Game Clock Adjust</h4>
            <button onclick="sendAction('adjustGameClock', { value: 60000 })">+1m</button>
            <button onclick="sendAction('adjustGameClock', { value: 1000 })">+1s</button>
            <button onclick="sendAction('adjustGameClock', { value: -60000 })">-1m</button>
            <button onclick="sendAction('adjustGameClock', { value: -1000 })">-1s</button>
            
        </div>
        <div class="control-group">
            <h4>Shot Clock Adjust</h4>
            <button onclick="sendAction('adjustShotClock', { value: 1000 })">+1s</button>
            <button onclick="sendAction('adjustShotClock', { value: -1000 })">-1s</button>
        </div>
        <div class="control-group">
            <h4>Game Flow</h4>
            <button id="nextQuarterBtn" onclick="sendAction('nextQuarter')">Next Quarter</button>
            <button id="finalizeGameBtn" onclick="finalizeGame()" style="display:none;">Finalize Game</button>
        </div>
    </div>

    <script>
        const gameId = <?php echo json_encode($game_id); ?>;
        let localTimerInterval = null;
        let isClockRunning = false;
        let gameClockMs = 0;
        let shotClockMs = 0;

        // NEW: State variable to track if the shot clock is "held" after a reset
        let shotClockHeld = false;

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
            return `${seconds}.${tenths}`;
        }
        
        function updateDisplay() {
            document.getElementById('gameClock').textContent = formatGameTime(gameClockMs);
            document.getElementById('shotClock').textContent = formatShotTime(shotClockMs);
        }

        function updateUI(state) {
            gameClockMs = state.game_clock;
            shotClockMs = state.shot_clock;
            updateDisplay();

            document.getElementById('quarterLabel').textContent = state.quarter_id <= 4 ? 
                ['1st', '2nd', '3rd', '4th'][state.quarter_id - 1] + ' Quarter' : `Overtime ${state.quarter_id - 4}`;
            
            const toggleBtn = document.getElementById('toggleClockBtn');
            toggleBtn.textContent = state.running ? 'Pause' : 'Start';
            toggleBtn.classList.toggle('running', state.running);
            isClockRunning = state.running;

            // MODIFIED: If the server reports the clock is stopped, we must cancel any local hold.
            if (!isClockRunning) {
                shotClockHeld = false;
            }
            
            const nextBtn = document.getElementById('nextQuarterBtn');
            const finalizeBtn = document.getElementById('finalizeGameBtn');
            const isTied = state.hometeam_score === state.awayteam_score;
            
            if (state.game_clock <= 0) {
                if (state.quarter_id >= 4 && !isTied) {
                    finalizeBtn.style.display = 'inline-block';
                    nextBtn.disabled = true;
                } else {
                    finalizeBtn.style.display = 'none';
                    nextBtn.disabled = false;
                }
                nextBtn.textContent = (state.quarter_id >= 4 && isTied) ? 'Start Overtime' : 'Next Quarter';
            } else {
                nextBtn.disabled = true;
                finalizeBtn.style.display = 'none';
            }
        }

        // NEW: This function implements the two-click logic for possession changes.
        function handlePossessionReset(isOffensive) {
            // If the game clock isn't running, the two-click system isn't needed. Reset immediately.
            if (!isClockRunning) {
                shotClockHeld = false; 
                sendAction('resetShotClock', { isOffensive });
                return;
            }

            // If the clock IS running, this implements the "hold" logic.
            if (!shotClockHeld) {
                // FIRST CLICK: Set the hold flag and send the reset command.
                // The local timer will now be prevented from running the shot clock down.
                shotClockHeld = true;
                sendAction('resetShotClock', { isOffensive });
            } else {
                // SECOND CLICK (or an override click on the other button):
                // Release the hold and send the reset command again. This ensures the
                // shot clock starts from its full value (24/14).
                shotClockHeld = false;
                sendAction('resetShotClock', { isOffensive });
            }
        }

        async function sendAction(action, payload = {}) {
            // Any action that isn't a possession reset should cancel the hold state.
            if (action !== 'resetShotClock') {
                shotClockHeld = false;
            }
            
            try {
                const body = { 
                    game_id: gameId, 
                    action: action, 
                    game_clock: gameClockMs, 
                    shot_clock: shotClockMs,
                    ...payload 
                };

                const response = await fetch('update_timer_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await response.json();
                if (result.success) {
                    updateUI(result.newState);
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Failed to send action:', error);
            }
        }
        
        async function fetchLatestTimerState() {
             try {
                const response = await fetch(`get_timer_state.php?game_id=${gameId}`);
                const state = await response.json();
                if (state && !isClockRunning) {
                    updateUI(state);
                }
             } catch (error) {
                console.error('Failed to fetch timer state:', error);
             }
        }

        async function finalizeGame() {
            if (!confirm("Are you sure you want to finalize this game? This will set the winner based on the current score and end the game.")) {
                return;
            }

            try {
                const response = await fetch('finalize_game.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: gameId })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Game has been finalized successfully! The winner will now be displayed on the management page.');
                    document.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    document.getElementById('toggleClockBtn').textContent = 'Game Over';
                    document.getElementById('toggleClockBtn').classList.remove('running');
                } else {
                    alert('Error finalizing game: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Failed to send finalize request:', error);
                alert('A network error occurred while trying to finalize the game.');
            }
        }
        
        function runLocalTimer() {
            if (localTimerInterval) clearInterval(localTimerInterval);
            localTimerInterval = setInterval(() => {
                if (isClockRunning) {
                    gameClockMs = Math.max(0, gameClockMs - 100);
                    
                    // MODIFIED: Only decrement the shot clock if it is NOT being held.
                    if (!shotClockHeld) {
                        shotClockMs = Math.max(0, shotClockMs - 100);
                    }
                    
                    updateDisplay();
                    if (gameClockMs === 0 && isClockRunning) {
    // Stop the local clock and, most importantly, tell the server.
    isClockRunning = false; 
    sendAction('toggle'); // This tells the server to stop the clock and syncs the time to 0.
}
                }
            }, 100);
        }

        document.addEventListener('DOMContentLoaded', () => {
            fetchLatestTimerState();
            runLocalTimer();
            setInterval(fetchLatestTimerState, 5000);
        });
    </script>
</body>
</html>