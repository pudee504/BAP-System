<?php
if (!function_exists('initialize_game_timer')) {
    /**
     * Inserts a default timer entry for a newly created game.
     *
     * @param PDO $pdo The database connection object.
     * @param int $game_id The ID of the newly created game.
     */
    function initialize_game_timer(PDO $pdo, int $game_id): void {
        // Default values for a new game timer
        $initial_game_clock = 10 * 60 * 1000; // 10 minutes in milliseconds
        $initial_shot_clock = 24 * 1000;      // 24 seconds in milliseconds
        $current_time_ms = round(microtime(true) * 1000);

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