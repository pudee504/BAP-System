<?php
session_start();
// FIX 1: Using "require_once" instead of "require" is critical. 
// It prevents the infinite loop error you were seeing by ensuring this file is only ever included one time.
require_once 'includes/category_info.php';

// Validate category ID from the URL query string.
$category_id = $_GET['category_id'] ?? '';
if (!$category_id) {
    // If no category_id is provided, stop execution.
    die("Invalid category ID.");
}

// FIX 2: This is the targeted fix for the missing league name error.
// Your original $category variable from category_info.php is preserved for other includes.
// We fetch ONLY the league name here to safely display it in the title.
$leagueNameStmt = $pdo->prepare("SELECT league_name FROM league WHERE id = ?");
$leagueNameStmt->execute([$category['league_id']]);
$league_name = $leagueNameStmt->fetchColumn();


// Determine the active tab from the URL, defaulting to 'teams'.
$active_tab = $_GET['tab'] ?? 'teams';
$valid_tabs = ['teams', 'schedule', 'standings'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'teams'; // Default to 'teams' if the tab in the URL is invalid.
}

// Fetch all category lock statuses.
$check = $pdo->prepare("SELECT schedule_generated, playoff_seeding_locked, groups_locked FROM category WHERE id = ?");
$check->execute([$category_id]);
$categoryInfo = $check->fetch(PDO::FETCH_ASSOC);

$scheduleGenerated = $categoryInfo['schedule_generated'] ?? false;

// Specific lock variables
$bracketLocked = $categoryInfo['playoff_seeding_locked'] ?? false;
$groupsLocked = $categoryInfo['groups_locked'] ?? false;

// A general variable to check if the category is locked in ANY way.
$isLocked = $bracketLocked || $groupsLocked;


// Check if any games have a final status, which might prevent regeneration.
$hasFinalGames = false;
if ($scheduleGenerated) {
    $finalCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM game 
        WHERE category_id = ? AND (game_status = 'Final' OR winnerteam_id IS NOT NULL)
    ");
    $finalCheck->execute([$category_id]);
    if ($finalCheck->fetchColumn() > 0) {
        $hasFinalGames = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($category['category_name']) ?> Details</title>
    
    <?php include 'includes/head_styles.php'; ?>
    <link rel="stylesheet" href="style.css"> 

    <script src="js/Sortable.min.js"></script>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    
    <div class="page-header">
        <h1>
            <a href="league_details.php?id=<?= $category['league_id'] ?>"><?= htmlspecialchars($league_name) ?></a>: 
            <?= htmlspecialchars($category['category_name']) ?>
        </h1>
    </div>

    <div class="league-info">
        <p><strong>Format:</strong> <?= htmlspecialchars($category['format_name']) ?></p>
        <p><strong>Teams:</strong> <?= htmlspecialchars($category['num_teams']) ?></p>
        <?php if (!empty($category['num_groups'])): ?>
            <p><strong>Groups:</strong> <?= htmlspecialchars($category['num_groups']) ?> (<?= htmlspecialchars($category['advance_per_group']) ?> advance per group)</p>
        <?php endif; ?>
    </div>

    <div class="tab-navigation">
        <?php include 'includes/category_tabs.php'; ?>
    </div>
    
    <div class="tab-container">
        <?php include 'includes/category_tabs_teams.php'; ?>
        <?php include 'includes/category_tabs_schedule.php'; ?>
        <?php include 'includes/category_tabs_standings.php'; ?>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            
            tab.classList.add('active');
            const tabId = tab.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
            
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        });
    });
});

function toggleDateForm(gameId) {
    const form = document.getElementById('date-form-' + gameId);
    if (form) {
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }
}
</script>

</body>
</html>

