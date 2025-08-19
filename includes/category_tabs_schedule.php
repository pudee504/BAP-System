<div class="tab-content <?= $active_tab === 'schedule' ? 'active' : '' ?>" id="schedule">
    <h2>Schedule</h2>

    <?php
    // --- Determine the state of the tournament ---
    $is_round_robin = ($category['format_name'] === 'Round Robin');
    $all_games_completed = false;
    $playoffs_exist = false;

    if ($is_round_robin && $scheduleGenerated) {
        $group_games_exist_stmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND stage = 'Group Stage'");
        $group_games_exist_stmt->execute([$category_id]);

        if ($group_games_exist_stmt->fetchColumn() > 0) {
            $upcoming_stmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND stage = 'Group Stage' AND game_status = 'Upcoming'");
            $upcoming_stmt->execute([$category_id]);
            if ($upcoming_stmt->fetchColumn() == 0) {
                $all_games_completed = true;
            }
        }
        $playoff_stmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND stage = 'Playoff'");
        $playoff_stmt->execute([$category_id]);
        if ($playoff_stmt->fetchColumn() > 0) {
            $playoffs_exist = true;
        }
    }
    ?>

    <?php if ($category['format_name'] === 'Single Elimination'): ?>
        <?php if (!$scheduleGenerated): ?>
            <form action="single_elimination.php" method="POST" onsubmit="return confirm('This will generate a full single elimination bracket. Proceed?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit">Generate Single Elimination Schedule</button>
            </form>
        <?php else: ?>
            <p style="color: green;"><strong>Schedule already generated.</strong></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($category['format_name'] === 'Double Elimination'): ?>
        <?php if (!$scheduleGenerated): ?>
            <form action="double_elimination.php" method="POST" onsubmit="return confirm('This will generate a full double elimination bracket. Proceed?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit">Generate Double Elimination Schedule</button>
            </form>
        <?php else: ?>
            <p style="color: green;"><strong>Schedule already generated.</strong></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($category['format_name'] === 'Round Robin'): ?>
        <?php if (!$scheduleGenerated): ?>
            <form action="round_robin.php" method="POST" onsubmit="return confirm('This will generate a full round robin bracket. Proceed?')">
                <input type="hidden" name="category_id" value="<?= $category_id ?>">
                <button type="submit">Generate Round Robin Schedule</button>
            </form>
        <?php else: ?>
            <p style="color: green;"><strong>Group Stage schedule generated.</strong></p>
        <?php endif; ?>
    <?php endif; ?>


    <?php
    // --- YOUR ORIGINAL CODE TO FETCH GAMES (Modified for Round Robin) ---
    $main_schedule_sql = "
      SELECT g.*, t1.team_name AS home_name, t2.team_name AS away_name
      FROM game g
      LEFT JOIN team t1 ON g.hometeam_id = t1.id
      LEFT JOIN team t2 ON g.awayteam_id = t2.id
      WHERE g.category_id = ?
    ";
    
    // For Round Robin, this first table ONLY shows the Group Stage
    if ($is_round_robin) {
        $main_schedule_sql .= " AND g.stage = 'Group Stage'";
    }

    $main_schedule_sql .= " ORDER BY round ASC, id ASC";

    $schedule = $pdo->prepare($main_schedule_sql);
    $schedule->execute([$category_id]);
    $games = $schedule->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if ($games): ?>
        <?php if($is_round_robin): ?>
            <h3>Group Stage</h3>
        <?php endif; ?>
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
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($game['round_name'] ?: 'Round ' . $game['round']) ?></td>
                    <td class="match-cell">
                        <div class="match-grid">
                            <div class="team-name">
                                <?= $game['hometeam_id'] ? '<a href="team_details.php?id=' . $game['hometeam_id'] . '">' . htmlspecialchars($game['home_name']) . '</a>' : 'TBD' ?>
                            </div>
                            <div class="team-result <?= $game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status']) ? ($game['hometeam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                <?php if ($game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status'])): ?>
                                    <?= ($game['hometeam_id'] == $game['winnerteam_id']) ? 'W' : 'L' ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                            <div class="team-name">
                                <?= $game['awayteam_id'] ? '<a href="team_details.php?id=' . $game['awayteam_id'] . '">' . htmlspecialchars($game['away_name']) . '</a>' : 'TBD' ?>
                            </div>
                            <div class="team-result <?= $game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status']) ? ($game['awayteam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                <?php if ($game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status'])): ?>
                                    <?= ($game['awayteam_id'] == $game['winnerteam_id']) ? 'W' : 'L' ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                        if ($game['game_status'] === 'Completed' || ($game['winnerteam_id'] && !$game['game_status'])) {
                            echo 'Completed';
                        } elseif ($game['game_status'] === 'Cancelled') {
                            echo 'Cancelled';
                        } else {
                            echo 'Upcoming';
                        }
                        ?>
                    </td>
                    <td id="game-date-<?= $game['id'] ?>">
                        <?php if ($game['game_date'] && $game['game_date'] !== '0000-00-00 00:00:00'): ?>
                            <?= date("F j, Y, g:i A", strtotime($game['game_date'])) ?>
                        <?php else: ?>
                            <span style="color:red;">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" onclick="toggleDateForm(<?= $game['id'] ?>)">Set Date</button>
                        <form id="date-form-<?= $game['id'] ?>" action="update_game_date.php" method="POST" style="display: none; margin-top: 5px;">
                            <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                            <input type="hidden" name="category_id" value="<?= $category_id ?>">
                            <input type="datetime-local" name="game_date" required>
                            <button type="submit">Save</button>
                        </form>
                        <form action="manage_game.php" method="GET" style="display:inline;">
                            <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                            <input type="hidden" name="category_id" value="<?= $category_id ?>">
                            <button type="submit">Manage Game</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No games found for this category. Please generate a schedule.</p>
    <?php endif; ?>


    <?php if ($is_round_robin): ?>
        <?php if ($all_games_completed && !$playoffs_exist): ?>
            <div class="playoff-generation-box">
                <h3>Group Stage Complete</h3>
                <p>All group stage games have been finalized. You can now proceed to the playoff stage.</p>
                <form action="generate_playoffs.php" method="POST" onsubmit="return confirm('Are you sure? This will create the playoff bracket.');">
                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                    <label for="playoff_format"><strong>Select Playoff Format:</strong></label>
                    <select name="playoff_format" id="playoff_format" required>
                        <option value="single_elimination">Single Elimination</option>
                        <option value="double_elimination">Double Elimination</option>
                    </select>
                    <button type="submit">Proceed to Playoffs</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($playoffs_exist): ?>
            <h3 style="margin-top: 40px; border-top: 2px solid #333; padding-top: 20px;">Playoffs</h3>
            <?php
            // Fetch only the playoff games for the second table
            $playoff_schedule_stmt = $pdo->prepare("SELECT g.*, t1.team_name AS home_name, t2.team_name AS away_name FROM game g LEFT JOIN team t1 ON g.hometeam_id = t1.id LEFT JOIN team t2 ON g.awayteam_id = t2.id WHERE g.category_id = ? AND g.stage = 'Playoff' ORDER BY round ASC, id ASC");
            $playoff_schedule_stmt->execute([$category_id]);
            $playoff_games = $playoff_schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
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
                    <?php foreach ($playoff_games as $index => $game): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($game['round_name'] ?: 'Round ' . $game['round']) ?></td>
                            <td class="match-cell">
                                <div class="match-grid">
                                    <div class="team-name">
                                        <?= $game['hometeam_id'] ? '<a href="team_details.php?id=' . $game['hometeam_id'] . '">' . htmlspecialchars($game['home_name']) . '</a>' : 'TBD' ?>
                                    </div>
                                    <div class="team-result <?= $game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status']) ? ($game['hometeam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                        <?php if ($game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status'])): ?>
                                            <?= ($game['hometeam_id'] == $game['winnerteam_id']) ? 'W' : 'L' ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                    <div class="team-name">
                                        <?= $game['awayteam_id'] ? '<a href="team_details.php?id=' . $game['awayteam_id'] . '">' . htmlspecialchars($game['away_name']) . '</a>' : 'TBD' ?>
                                    </div>
                                    <div class="team-result <?= $game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status']) ? ($game['awayteam_id'] == $game['winnerteam_id'] ? 'win' : 'loss') : '' ?>">
                                        <?php if ($game['winnerteam_id'] && ($game['game_status'] === 'Completed' || !$game['game_status'])): ?>
                                            <?= ($game['awayteam_id'] == $game['winnerteam_id']) ? 'W' : 'L' ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php
                                if ($game['game_status'] === 'Completed' || ($game['winnerteam_id'] && !$game['game_status'])) {
                                    echo 'Completed';
                                } elseif ($game['game_status'] === 'Cancelled') {
                                    echo 'Cancelled';
                                } else {
                                    echo 'Upcoming';
                                }
                                ?>
                            </td>
                            <td id="game-date-<?= $game['id'] ?>">
                                <?php if ($game['game_date'] && $game['game_date'] !== '0000-00-00 00:00:00'): ?>
                                    <?= date("F j, Y, g:i A", strtotime($game['game_date'])) ?>
                                <?php else: ?>
                                    <span style="color:red;">Not Set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" onclick="toggleDateForm(<?= $game['id'] ?>)">Set Date</button>
                                <form id="date-form-<?= $game['id'] ?>" action="update_game_date.php" method="POST" style="display: none; margin-top: 5px;">
                                    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                                    <input type="datetime-local" name="game_date" required>
                                    <button type="submit">Save</button>
                                </form>
                                <form action="manage_game.php" method="GET" style="display:inline;">
                                    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                                    <input type="hidden" name="category_id" value="<?= $category_id ?>">
                                    <button type="submit">Manage Game</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
    .playoff-generation-box { border: 2px solid #28a745; padding: 20px; margin: 20px 0; background-color: #f0fff4; text-align: center; }
    .playoff-generation-box button { padding: 10px 20px; font-size: 1em; background-color: #28a745; color: white; border: none; cursor: pointer; }
</style>