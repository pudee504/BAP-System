<?php
// FILENAME: schedule_helpers.php
// DESCRIPTION: Contains helper functions related to game scheduling and setup.

if (!function_exists('initialize_game_timer')) {
    /**
     * Creates a default entry in the `game_timer` table for a new game.
     * Sets initial clock times and quarter.
     */
    function initialize_game_timer(PDO $pdo, int $game_id): void {
        // Default: 10 minutes game clock, 24 seconds shot clock, Quarter 1, timer stopped.
        $initial_game_clock = 10 * 60 * 1000; // milliseconds
        $initial_shot_clock = 24 * 1000;      // milliseconds
        $current_time_ms = round(microtime(true) * 1000); // Current server time

        $timer_stmt = $pdo->prepare("
            INSERT INTO game_timer
                (game_id, game_clock, shot_clock, quarter_id, running, last_updated_at)
            VALUES
                (?, ?, ?, 1, 0, ?)
        ");
        $timer_stmt->execute([
            $game_id,
            $initial_game_clock,
            $initial_shot_clock,
            $current_time_ms
        ]);
    }
}
?>