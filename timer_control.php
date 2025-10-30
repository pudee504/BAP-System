<?php
// FILENAME: timer_control.php
// DESCRIPTION: Provides a web interface for controlling the game clock, shot clock, and quarter for a specific game.
// Interacts with the database via AJAX calls to `update_timer_action.php` and `get_timer_state.php`.

$game_id = $_GET['game_id'] ?? ''; // Get game ID from URL
if (!$game_id) { die("Invalid game ID."); } // Stop if no valid ID
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
            --bap-blue: #1f2593; --bap-orange: #fc9e3e; --bap-yellow: #FFC107;
            --bap-red: #D81B60; --text-dark: #212529; --text-light: #f8f9fa;
            --bg-light: #ffffff; --bg-main: #f4f7fc; --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background-color: var(--bg-main); color: var(--text-dark); padding: 1rem; }

        /* ==========================================================================
           2. MAIN CONTAINER & TYPOGRAPHY
           ========================================================================== */
        .container { max-width: 480px; margin: 0 auto; background: var(--bg-light); padding: 1.5rem; border-radius: 12px; box-shadow: var(--shadow); text-align: center; }
        h1 { color: var(--bap-blue); font-size: 1.5rem; margin-bottom: 1.5rem; }
        .quarter { font-size: 1.2rem; margin-bottom: 0.5rem; color: #6c757d; font-weight: 600; }
        .game-clock { font-size: 4.5em; font-weight: bold; font-family: "Courier New", monospace; color: var(--text-dark); letter-spacing: -2px; }
        .shot-clock { font-size: 3em; color: var(--bap-orange); font-family: "Courier New", monospace; font-weight: bold; margin-bottom: 1.5rem; }

        /* ==========================================================================
           3. BUTTONS & CONTROLS
           ========================================================================== */
        button { display: inline-block; padding: 0.75rem 1rem; border: none; border-radius: 50px; font-size: 1rem; font-weight: bold; cursor: pointer; transition: all 0.2s ease; text-align: center; margin: 0.25rem; }
        button:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        button:disabled { background-color: #e9ecef !important; color: #6c757d !important; cursor: not-allowed; transform: none; box-shadow: none; }
        /* Start/Pause button */
        #toggleClockBtn { width: 90%; padding: 1rem; font-size: 1.2rem; background-color: #28a745; color: var(--text-light); }
        #toggleClockBtn.running { background-color: var(--bap-red); } /* Red when running */
        /* Grouped buttons (2-column layout) */
        .control-group button { background-color: var(--bap-blue); color: var(--text-light); width: calc(50% - 1rem); }
        #finalizeGameBtn { background-color: var(--bap-orange); color: var(--text-dark); width: 90%; }
        #prevQuarterBtn { background-color: #6c757d; color: var(--text-light); } /* Gray for Previous Q */

        /* ==========================================================================
           4. LAYOUT
           ========================================================================== */
        .main-controls { margin-bottom: 1.5rem; }
        .control-group { margin: 1.5rem 0 0 0; border-top: 1px solid var(--border-color); padding-top: 1.5rem; }
        .control-group h4 { margin-top: 0; margin-bottom: 1rem; color: #6c757d; font-weight: 600; }
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
            <button id="prevQuarterBtn" onclick="sendAction('prevQuarter')" disabled>Previous Quarter</button>
            <button id="nextQuarterBtn" onclick="sendAction('nextQuarter')">Next Quarter</button>
            <button id="finalizeGameBtn" onclick="finalizeGame()" style="display:none;">Finalize Game</button>
        </div>
    </div>

    <script>
        const gameId = <?php echo json_encode($game_id); ?>; // Game ID from PHP
        // --- State Variables ---
        let localTimerInterval = null; // Interval ID for client-side clock decrement
        let isClockRunning = false;    // Tracks if clock is currently running
        let gameClockMs = 0;           // Current game clock value (ms)
        let shotClockMs = 0;           // Current shot clock value (ms)
        let currentQuarterId = 1;      // Current quarter number
        let shotClockHeld = false;     // Tracks the 2-click shot clock reset/hold state

        /** Formats milliseconds to MM:SS or MM:SS.T for game clock. */
        function formatGameTime(ms) {
            if (ms <= 0) return "00:00.0";
            const totalSeconds = Math.floor(ms / 1000);
            const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            const tenths = Math.floor((ms % 1000) / 100).toString();
            // Show tenths only when under 1 minute.
            return totalSeconds < 60 ? `${minutes}:${seconds}.${tenths}` : `${minutes}:${seconds}`;
        }

        /** Formats milliseconds to SS or SS.T for shot clock. */
        function formatShotTime(ms) {
            if (ms <= 0) return "0.0";
            const seconds = Math.floor(ms / 1000);
            const tenths = Math.floor((ms % 1000) / 100);
            // Show only whole seconds if >= 5s and tenths are 0.
            return `${seconds}.${tenths}`;
        }
        
        /** Updates the clock displays in the HTML. */
        function updateDisplay() {
            document.getElementById('gameClock').textContent = formatGameTime(gameClockMs);
            document.getElementById('shotClock').textContent = formatShotTime(shotClockMs);
        }

        /** Updates the entire UI based on state received from the server. */
        function updateUI(state) {
            // Update local state variables.
            currentQuarterId = state.quarter_id;
            gameClockMs = state.game_clock;
            shotClockMs = state.shot_clock;
            isClockRunning = state.running; // Most important update for local timer logic
            updateDisplay(); // Update clock visuals immediately

            // Update quarter label text.
            document.getElementById('quarterLabel').textContent = state.quarter_id <= 4 ? 
                ['1st', '2nd', '3rd', '4th'][state.quarter_id - 1] + ' Quarter' : `Overtime ${state.quarter_id - 4}`;
            
            // Update Start/Pause button text and style.
            const toggleBtn = document.getElementById('toggleClockBtn');
            toggleBtn.textContent = state.running ? 'Pause' : 'Start';
            toggleBtn.classList.toggle('running', state.running);

            // Cancel shot clock hold if the clock is stopped externally.
            if (!isClockRunning) {
                shotClockHeld = false;
            }
            
            // --- Update Game Flow Button States ---
            const prevBtn = document.getElementById('prevQuarterBtn');
            const nextBtn = document.getElementById('nextQuarterBtn');
            const finalizeBtn = document.getElementById('finalizeGameBtn');
            
            const isTied = (state.hometeam_score === state.awayteam_score); // Check for tie score
            
            // Enable "Previous Quarter" only if not Q1 and clock is stopped.
            prevBtn.disabled = (state.quarter_id <= 1 || state.running); 

            // Logic for "Next Quarter" / "Start Overtime" / "Finalize Game" visibility.
            if (state.game_clock <= 0 && !state.running) { // If clock is 0 and stopped
                if (state.quarter_id >= 4 && !isTied) { // If Q4+ ended and NOT tied
                    finalizeBtn.style.display = 'inline-block'; // Show Finalize
                    nextBtn.disabled = true; // Disable Next Q/OT
                } else { // If Q1-3 ended, OR Q4+ ended tied
                    finalizeBtn.style.display = 'none'; // Hide Finalize
                    nextBtn.disabled = false; // Enable Next Q/OT
                }
                // Change Next button text for overtime.
                nextBtn.textContent = (state.quarter_id >= 4 && isTied) ? 'Start Overtime' : 'Next Quarter';
            } else { // If clock is running or not yet 0
                nextBtn.disabled = true; // Disable Next Q/OT
                finalizeBtn.style.display = 'none'; // Hide Finalize
            }
            
            // Always hide Finalize if clock is running.
            if(state.running){
                 finalizeBtn.style.display = 'none';
            }
        }

        /** Handles the 2-click logic for shot clock reset/hold. */
        function handlePossessionReset(isOffensive) {
            // If clock is stopped, just send the reset action directly.
            if (!isClockRunning) {
                shotClockHeld = false; // Ensure hold is cancelled
                sendAction('resetShotClock', { isOffensive });
                return;
            }

            // If clock is running, toggle the hold state and send the reset action.
            if (!shotClockHeld) { // First click: Hold and Reset
                shotClockHeld = true;
                sendAction('resetShotClock', { isOffensive });
            } else { // Second click: Release Hold and Reset again
                shotClockHeld = false;
                sendAction('resetShotClock', { isOffensive });
            }
        }

        /** Sends an action command to the server via AJAX. */
        async function sendAction(action, payload = {}) {
            // Prevent changing quarters while clock is running.
            if ((action === 'nextQuarter' || action === 'prevQuarter') && isClockRunning) {
                alert("Please pause the clock before changing quarters.");
                return;
            }
            
            // Cancel shot clock hold for actions other than reset.
            if (action !== 'resetShotClock') {
                shotClockHeld = false;
            }
            
            try {
                // Prepare data payload for the server.
                const body = { 
                    game_id: gameId, 
                    action: action, 
                    game_clock: gameClockMs, // Send current client-side clock time
                    shot_clock: shotClockMs,
                    current_quarter: currentQuarterId, 
                    ...payload // Include any action-specific data (e.g., adjustment value)
                };

                // Send the POST request to the update endpoint.
                const response = await fetch('update_timer_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await response.json();
                
                if (result.success) {
                    // If successful, update the UI with the new state returned by the server.
                    updateUI(result.newState);
                    // Crucially, call runLocalTimer AFTER updating state to start/stop the interval correctly.
                    runLocalTimer(); 
                } else {
                    alert('Error: ' + result.error); // Show error message from server.
                }
            } catch (error) {
                console.error('Failed to send action:', error);
            }
        }
        
        /** Fetches the latest timer state, primarily used for polling and initial load. */
        async function fetchLatestTimerState() {
             try {
                 // Fetch state from get_timer_state.php (includes cache busting).
                 const response = await fetch(`get_timer_state.php?game_id=${gameId}&t=${new Date().getTime()}`); 
                 const state = await response.json();
                 
                 // Update UI only if state is received AND clock is stopped locally OR clock stopped on server.
                 // This prevents overriding the smooth local timer if the clock is running.
                 if (state && (!isClockRunning || !state.running)) { 
                     updateUI(state);
                     // Call runLocalTimer to stop the interval if clock was paused externally.
                     runLocalTimer();
                 } 
                 // If clock is running, just update non-critical UI elements like quarter label/buttons.
                 else if (state) {
                     currentQuarterId = state.quarter_id;
                     document.getElementById('quarterLabel').textContent = state.quarter_id <= 4 ? 
                         ['1st', '2nd', '3rd', '4th'][state.quarter_id - 1] + ' Quarter' : `Overtime ${state.quarter_id - 4}`;
                     const prevBtn = document.getElementById('prevQuarterBtn'); 
                     prevBtn.disabled = (state.quarter_id <= 1 || state.running); 
                 }
             } catch (error) {
                 console.error('Failed to fetch timer state:', error);
             }
        }

        /** Sends the 'finalize' action to the server. */
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
                    // Disable all buttons, update text, stop local timer.
                    document.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    document.getElementById('toggleClockBtn').textContent = 'Game Over';
                    document.getElementById('toggleClockBtn').classList.remove('running');
                    if(localTimerInterval) clearInterval(localTimerInterval); 
                } else {
                    alert('Error finalizing game: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Failed to send finalize request:', error);
                alert('A network error occurred while trying to finalize the game.');
            }
        }
        
        /** Manages the local setInterval for visually decrementing clocks. */
        function runLocalTimer() {
            // Always clear any existing interval first.
            if (localTimerInterval) clearInterval(localTimerInterval);
            
            // Only start a new interval if the clock should be running.
            if (isClockRunning) { 
                localTimerInterval = setInterval(() => {
                    if (isClockRunning) { // Double-check inside interval
                        gameClockMs = Math.max(0, gameClockMs - 100); // Decrement game clock
                        if (!shotClockHeld) { // Decrement shot clock unless held
                            shotClockMs = Math.max(0, shotClockMs - 100);
                        }
                        updateDisplay(); // Update visuals

                        // If game clock hits 0 locally:
                        if (gameClockMs === 0) {
                            isClockRunning = false; // Stop local running state
                            clearInterval(localTimerInterval); // Stop interval
                            sendAction('toggle'); // Send stop action to server to synchronize
                        }
                    } else {
                         clearInterval(localTimerInterval); // Stop if state changed externally
                    }
                }, 100); // Run every 100ms
             }
        }

        // --- Initial Load ---
        document.addEventListener('DOMContentLoaded', () => {
            // Fetch initial state THEN start local timer/polling.
            fetchLatestTimerState().then(() => {
                runLocalTimer(); 
            }); 
            
            // Poll for external changes (e.g., if paused elsewhere).
            setInterval(fetchLatestTimerState, 5000); // Check every 5 seconds
        });
    </script>
</body>
</html>