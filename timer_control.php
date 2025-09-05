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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #1c1c1e; color: #fff; text-align: center; margin: 0; padding: 10px; }
        .container { max-width: 480px; margin: 0 auto; }
        .game-clock { font-size: 5em; font-weight: bold; font-family: "Courier New", monospace; letter-spacing: -3px; }
        .shot-clock { font-size: 3.5em; color: #ffc107; font-family: "Courier New", monospace; }
        .quarter { font-size: 1.5em; margin-bottom: 10px; color: #aaa; }
        button { font-size: 1.1em; padding: 12px 20px; margin: 5px; cursor: pointer; border-radius: 8px; border: none; min-width: 150px; background-color: #3a3a3c; color: #fff; transition: background-color 0.2s; }
        button:active { background-color: #555; }
        button:disabled { background-color: #2c2c2e; color: #666; cursor: not-allowed; }
        #toggleClockBtn { background-color: #34c759; font-weight: bold; width: 90%; padding: 15px; }
        #toggleClockBtn.running { background-color: #ff3b30; }
        .control-group { margin: 20px 0; border-top: 1px solid #3a3a3c; padding-top: 20px; }
        .control-group h4 { margin-top: 0; color: #aaa; }
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
            <button onclick="sendAction('resetShotClock', { isOffensive: false })">Change (24s)</button>
            <button onclick="sendAction('resetShotClock', { isOffensive: true })">Off. Reb (14s)</button>
        </div>
        <div class="control-group">
            <h4>Game Clock Adjust</h4>
            <button onclick="sendAction('adjustGameClock', { value: 60000 })">+1m</button>
            <button onclick="sendAction('adjustGameClock', { value: 1000 })">+1s</button>
            <button onclick="sendAction('adjustGameClock', { value: -1000 })">-1s</button>
            <button onclick="sendAction('adjustGameClock', { value: -60000 })">-1m</button>
        </div>
        <div class="control-group">
            <h4>Shot Clock Adjust</h4>
            <button onclick="sendAction('adjustShotClock', { value: 1000 })">+1s</button>
            <button onclick="sendAction('adjustShotClock', { value: -1000 })">-1s</button>
        </div>
        <div class="control-group">
            <h4>Game Flow</h4>
            <button id="nextQuarterBtn" onclick="sendAction('nextQuarter')">Next Quarter</button>
            <button id="finalizeGameBtn" onclick="finalizeGame()" style="display:none; background-color: #007bff; color: white;">Finalize Game</button>
        </div>
    </div>

    <script>
        const gameId = <?php echo json_encode($game_id); ?>;
        let localTimerInterval = null;
        let isClockRunning = false;
        let gameClockMs = 0;
        let shotClockMs = 0;

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

        async function sendAction(action, payload = {}) {
            try {
                // --- THIS IS THE FIX ---
                // We now include the current time from this device in the message to the server.
                // This makes the server's calculation start from the correct time.
                const body = { 
                    game_id: gameId, 
                    action: action, 
                    // Send the current time from this client
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
                    // Disable all controls on this page since the game is over
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
        // --- END OF NEW FUNCTION ---
        
        function runLocalTimer() {
            if (localTimerInterval) clearInterval(localTimerInterval);
            localTimerInterval = setInterval(() => {
                if (isClockRunning) {
                    gameClockMs = Math.max(0, gameClockMs - 100);
                    shotClockMs = Math.max(0, shotClockMs - 100);
                    updateDisplay();
                    if (gameClockMs === 0) {
                        isClockRunning = false;
                        fetchLatestTimerState();
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
