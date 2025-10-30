<?php
// ============================================
// File: includes/category_tabs_schedule.php
// Purpose: Displays and manages the "Schedule" tab for a category,
// including generating, viewing, updating, and regenerating game schedules.
// ============================================
?>
<div class="tab-content <?= $active_tab === 'schedule' ? 'active' : '' ?>" id="schedule">
    <div class="section-header">
        <h2>Schedule</h2>
    </div>

    <div class="schedule-actions">
        <?php if (!$scheduleGenerated): ?>
            <?php if ($isLocked): ?>
                <?php 
                // Determine which generator file to use based on format type.
                $action_url = '';
                if ($category['format_name'] === 'Single Elimination') {
                    $action_url = 'single_elimination.php';
                } else if ($category['format_name'] === 'Double Elimination') {
                    $action_url = 'double_elimination.php'; 
                } else if ($category['format_name'] === 'Round Robin') {
                    $action_url = 'round_robin.php';
                }
                ?>
                <!-- Schedule generation form -->
                <form action="<?= $action_url ?>" method="POST" onsubmit="return confirm('This will generate the schedule based on the locked settings. This action cannot be undone. Proceed?')">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    <button type="submit" class="btn btn-primary">Generate <?= htmlspecialchars($category['format_name']) ?> Schedule</button>
                </form>
            <?php else: ?>
                <!-- Display message if the bracket/groups are not locked yet -->
                <?php
                $lock_type_message = (strtolower($category['format_name']) === 'round robin') 
                    ? "lock the groups" 
                    : "lock the bracket";
                ?>
                <button type="button" class="btn" disabled>Generate Schedule</button>
                <p>You must fill all team slots and <?= $lock_type_message ?> in the 'Standings' tab before generating a schedule.</p>
            <?php endif; ?>
        <?php else: ?>
            <!-- Display success message when schedule is already generated -->
            <p class="success-message" style="margin-bottom: 1rem;"><strong>Schedule has been generated.</strong></p>

            <?php if ($hasFinalGames): ?>
                <!-- Cannot regenerate schedule if any game is final -->
                <button class="btn" disabled>Regenerate Schedule</button>
                <p>Cannot regenerate schedule because at least one game has a 'Final' status.</p>
            <?php else: ?>
                <!-- Allow regeneration of schedule (clears all games) -->
                <form action="clear_schedule.php" method="POST" onsubmit="return confirm('WARNING: This will delete all existing games and allow you to generate a new schedule. This action cannot be undone. Proceed?')">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    <button type="submit" class="btn btn-danger">Regenerate Schedule</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php
    // Fetch all scheduled games with team names for display.
    $scheduleStmt = $pdo->prepare("
        SELECT g.*, t1.team_name AS home_name, t2.team_name AS away_name
        FROM game g
        LEFT JOIN team t1 ON g.hometeam_id = t1.id
        LEFT JOIN team t2 ON g.awayteam_id = t2.id
        WHERE g.category_id = ?
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
        <!-- Display game schedule table -->
        <div class="table-wrapper">
            <table class="category-table">
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
                            
                            <!-- Match teams display -->
                            <td class="match-cell">
                                <div class="match-grid">
                                    <div class="team-name">
                                        <?= $game['hometeam_id'] ? '<a href="team_details.php?team_id=' . $game['hometeam_id'] . '">' . htmlspecialchars($game['home_name']) . '</a>' : 'TBD' ?>
                                    </div>
                                    <div class="team-result <?= $game['winnerteam_id'] ? ($game['hometeam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                        <?= $game['winnerteam_id'] ? (($game['hometeam_id'] == $game['winnerteam_id']) ? 'W' : 'L') : '-' ?>
                                    </div>
                                    <div class="team-name">
                                        <?= $game['awayteam_id'] ? '<a href="team_details.php?team_id=' . $game['awayteam_id'] . '">' . htmlspecialchars($game['away_name']) . '</a>' : 'TBD' ?>
                                    </div>
                                    <div class="team-result <?= $game['winnerteam_id'] ? ($game['awayteam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                        <?= $game['winnerteam_id'] ? (($game['awayteam_id'] == $game['winnerteam_id']) ? 'W' : 'L') : '-' ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td>
                                <?= ($game['game_status'] === 'Final' || $game['winnerteam_id']) ? 'Final' : 'Pending' ?>
                            </td>

                            <!-- Display or edit game date -->
                            <td id="game-date-<?= $game['id'] ?>">
                                <?= ($game['game_date'] && $game['game_date'] !== '0000-00-00 00:00:00') 
                                    ? date("F j, Y g:i A", strtotime($game['game_date'])) 
                                    : '<span style="color: red;">Not Set</span>' ?>
                            </td>

                            <!-- Action buttons -->
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleDateForm(<?= $game['id'] ?>)">Set Date</button>
                                <div id="date-form-<?= $game['id'] ?>" class="set-date-form">
                                    <form action="update_game_date.php" method="POST">
                                        <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                        <input type="hidden" name="category_id" value="<?= $category_id ?>">
                                        <input type="datetime-local" name="game_date" required>
                                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                    </form>
                                </div>
                                <a href="manage_game.php?game_id=<?= $game['id'] ?>&category_id=<?= $category_id ?>" class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">Manage Game</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($scheduleGenerated): ?>
        <p class="info-message">Games have been generated but could not be displayed. Please check the database.</p>
    <?php else: ?>
        <p class="info-message">No games found. Please generate a schedule after locking the groups/bracket.</p>
    <?php endif; ?>
</div>
