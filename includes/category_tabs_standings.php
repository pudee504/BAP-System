<?php
// We need the category format to decide what to render
// CORRECTED QUERY: Joins category -> category_format -> format
$formatStmt = $pdo->prepare("
    SELECT f.format_name
    FROM category c
    JOIN category_format cf ON c.id = cf.category_id
    JOIN format f ON cf.format_id = f.id
    WHERE c.id = ?
");
$formatStmt->execute([$category_id]);
$format = $formatStmt->fetchColumn();

// Fetch all games for the category with team names
$gamesStmt = $pdo->prepare("
    SELECT
        g.id AS game_id,
        g.round_name,
        g.hometeam_id,
        g.awayteam_id,
        g.winnerteam_id,
        ht.team_name AS hometeam_name,
        awt.team_name AS awayteam_name
    FROM game g
    LEFT JOIN team ht ON g.hometeam_id = ht.id
    LEFT JOIN team awt ON g.awayteam_id = awt.id
    WHERE g.category_id = ?
    ORDER BY g.id ASC
");
$gamesStmt->execute([$category_id]);
$all_games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

// Group games by their round_name
$rounds = [];
foreach ($all_games as $game) {
    $rounds[$game['round_name']][] = $game;
}
?>

<div class="tab-content <?= $active_tab === 'standings' ? 'active' : '' ?>" id="standings">
    <?php if (!$scheduleGenerated || empty($all_games)): ?>
        <p>The schedule has not been generated yet. Please generate the schedule in the "Schedule" tab to see the standings bracket.</p>
    <?php else: ?>
        <link rel="stylesheet" href="includes/bracket_renderer.css">

        <?php
        // This is a helper function to render a single match with W/L logic
        function render_match($game) {
            $home_result = '-';
            $away_result = '-';

            // Check if the game has a winner
            if (!empty($game['winnerteam_id'])) {
                if ($game['winnerteam_id'] == $game['hometeam_id']) {
                    $home_result = 'W';
                    $away_result = 'L';
                } elseif ($game['winnerteam_id'] == $game['awayteam_id']) {
                    $home_result = 'L';
                    $away_result = 'W';
                }
            }
            ?>
            <div class="bracket-match">
                <div class="bracket-teams">
                    <div class="bracket-team <?= $game['winnerteam_id'] == $game['hometeam_id'] ? 'winner' : '' ?>">
                        <span class="team-name <?= !$game['hometeam_name'] ? 'placeholder' : '' ?>"><?= htmlspecialchars($game['hometeam_name'] ?? 'TBD') ?></span>
                        <span class="team-score"><?= $home_result ?></span>
                    </div>
                    <div class="bracket-team <?= $game['winnerteam_id'] == $game['awayteam_id'] ? 'winner' : '' ?>">
                        <span class="team-name <?= !$game['awayteam_name'] ? 'placeholder' : '' ?>"><?= htmlspecialchars($game['awayteam_name'] ?? 'TBD') ?></span>
                        <span class="team-score"><?= $away_result ?></span>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>

        <?php if ($format == 'Double Elimination'): ?>
            <h3>Upper Bracket</h3>
            <div class="bracket-container">
                <?php
                $upper_rounds = array_filter($rounds, function($key) {
                    return strpos($key, 'Upper') !== false || strpos($key, 'Grand Final') !== false;
                }, ARRAY_FILTER_USE_KEY);

                foreach ($upper_rounds as $round_name => $games): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                        <?php foreach ($games as $game) { render_match($game); } ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 style="margin-top: 40px;">Lower Bracket</h3>
            <div class="bracket-container">
                <?php
                $lower_rounds = array_filter($rounds, function($key) {
                    return strpos($key, 'Lower') !== false;
                }, ARRAY_FILTER_USE_KEY);

                 foreach ($lower_rounds as $round_name => $games): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                        <?php foreach ($games as $game) { render_match($game); } ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: // Handles Single Elimination ?>
            <h3>Tournament Bracket</h3>
            <div class="bracket-container">
                <?php foreach ($rounds as $round_name => $games): ?>
                    <div class="bracket-round">
                        <h4 class="bracket-round-title"><?= htmlspecialchars($round_name) ?></h4>
                        <?php foreach ($games as $game) { render_match($game); } ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
