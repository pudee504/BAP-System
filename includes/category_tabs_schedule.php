<div class="tab-content <?= $active_tab === 'schedule' ? 'active' : '' ?>" id="schedule">
    <h2>Schedule</h2>

    <?php 
    // This block handles the logic for generating the schedule.
    if (!$scheduleGenerated): 
    ?>
        <?php 
        // CRITICAL CHANGE: We now check if the bracket is locked, not the old seeding system.
        // The '$bracketLocked' variable comes from 'category_details.php'.
        if ($bracketLocked): 
        ?>
            <?php 
            // Determine which generation script to call based on the tournament format.
            $action_url = '';
            if ($category['format_name'] === 'Single Elimination') {
                $action_url = 'single_elimination.php';
            } else if ($category['format_name'] === 'Double Elimination') {
                $action_url = 'double_elimination.php'; 
            }
            // You can add more format handlers here, e.g., 'generate_double_elimination.php'
            // if ($category['format_name'] === 'Double Elimination') $action_url = 'generate_double_elimination.php';
            ?>
            <form action="<?= $action_url ?>" method="POST" onsubmit="return confirm('This will generate the schedule based on the locked bracket. This action cannot be undone. Proceed?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit">Generate <?= htmlspecialchars($category['format_name']) ?> Schedule</button>
            </form>
        <?php else: ?>
            <button type="button" disabled>Generate Schedule</button>
            <p style="color: red; font-size: 0.9em; margin-top: 5px;">
                You must fill all team slots and lock the bracket in the 'Standings' tab before generating a schedule.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p style="color: green;"><strong>Schedule already generated.</strong></p>

        <?php if ($hasFinalGames): ?>
            <button disabled style="background-color: #6c757d;">Regenerate Schedule</button>
            <p style="color: red; font-size: 0.9em; margin-top: 5px;">
                Cannot regenerate schedule because at least one game has a 'Final' status.
            </p>
        <?php else: ?>
            <form action="clear_schedule.php" method="POST" onsubmit="return confirm('WARNING: This will delete all existing games and allow you to generate a new schedule. This action cannot be undone. Proceed?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit" style="background-color: #dc3545; color: white;">Regenerate Schedule</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Fetch and display the list of all games for this category.
    $scheduleStmt = $pdo->prepare("
        SELECT g.*, t1.team_name AS home_name, t2.team_name AS away_name
        FROM game g
        LEFT JOIN team t1 ON g.hometeam_id = t1.id
        LEFT JOIN team t2 ON g.awayteam_id = t2.id
        WHERE g.category_id = ?
        -- MODIFIED: This ORDER BY clause now matches the graphical bracket's logic,
        -- ensuring the Match # is consistent everywhere.
        ORDER BY 
            CASE g.bracket_type
                WHEN 'winner' THEN 1
                WHEN 'loser' THEN 2
                WHEN 'grand_final' THEN 3
            END ASC, 
            g.round ASC, 
            g.id ASC
    ");
    $scheduleStmt->execute([$category_id]);
    $games = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if ($games): ?>
        <table class="category-table" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Match #</th>
                    <th>Round</th>
                    <th>Match</th>
                    <th>Status</th>
                    <th>Game Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($games as $index => $game): ?>
                    <tr id="game-row-<?= $game['id'] ?>">
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($game['round_name'] ?: 'Round ' . $game['round']) ?></td>
                        <td class="match-cell">
                            <div class="match-grid">
                                <div class="team-name">
                                    <?= $game['hometeam_id'] ? htmlspecialchars($game['home_name']) : 'TBD' ?>
                                </div>
                                <div class="team-result">vs</div>
                                <div class="team-name">
                                    <?= $game['awayteam_id'] ? htmlspecialchars($game['away_name']) : 'TBD' ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?= ($game['game_status'] === 'Final' || $game['winnerteam_id']) ? 'Final' : 'Pending' ?>
                        </td>
                        <td id="game-date-<?= $game['id'] ?>">
                            <?= ($game['game_date'] && $game['game_date'] !== '0000-00-00 00:00:00') ? date("F j, Y g:i A", strtotime($game['game_date'])) : '<span style="color: red;">Not Set</span>' ?>
                        </td>
                        <td>
                            <button type="button" onclick="toggleDateForm(<?= $game['id'] ?>)">Set Date</button>
                            <form id="date-form-<?= $game['id'] ?>" action="update_game_date.php" method="POST" style="display: none; margin-top: 5px;">
                                <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                                <input type="datetime-local" name="game_date" required>
                                <button type="submit">Save</button>
                            </form>
                            <a href="manage_game.php?game_id=<?= $game['id'] ?>&category_id=<?= $category_id ?>" class="button">Manage Game</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($scheduleGenerated): ?>
        <p>Games have been generated but could not be displayed. Please check the database.</p>
    <?php else: ?>
        <p style="margin-top: 20px;">No games found. Please generate a schedule after locking the bracket.</p>
    <?php endif; ?>
</div>