<?php
// ============================================================
// File: category_details.php
// Purpose: Displays the details of a selected category including
// teams, schedule, and standings with tab navigation and state handling.
// ============================================================

session_start();
require_once 'includes/category_info.php'; // Prevents multiple inclusion
require_once 'logger.php'; 

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// Validate category ID from URL
$category_id = $_GET['category_id'] ?? '';
if (!$category_id) {
    die("Invalid category ID.");
}

// --- AUTHORIZATION CHECK ---
require_once 'includes/auth_functions.php';
$category_id_auth = (int) ($_GET['category_id'] ?? 0);
if (!has_league_permission($pdo, $_SESSION['user_id'], 'category', $category_id_auth)) {
    $_SESSION['error'] = 'You do not have permission to view this category.';
    header('Location: dashboard.php');
    exit;
}

// Fetch league name for title and breadcrumb
$leagueNameStmt = $pdo->prepare("SELECT league_name FROM league WHERE id = ?");
$leagueNameStmt->execute([$category['league_id']]);
$league_name = $leagueNameStmt->fetchColumn();

// Determine active tab from URL (default: 'teams')
$active_tab = $_GET['tab'] ?? 'teams';
$valid_tabs = ['teams', 'schedule', 'standings'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'teams';
}

// Fetch lock statuses for schedule, seeding, and groups
$check = $pdo->prepare("
    SELECT schedule_generated, playoff_seeding_locked, groups_locked 
    FROM category 
    WHERE id = ?
");
$check->execute([$category_id]);
$categoryInfo = $check->fetch(PDO::FETCH_ASSOC);

$scheduleGenerated = $categoryInfo['schedule_generated'] ?? false;
$bracketLocked = $categoryInfo['playoff_seeding_locked'] ?? false;
$groupsLocked = $categoryInfo['groups_locked'] ?? false;
$isLocked = $bracketLocked || $groupsLocked;

// Check if any games are finalized (prevents schedule regeneration)
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
    <link rel="stylesheet" href="css/style.css">
    <script src="js/Sortable.min.js"></script>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    
    <div class="page-header">
        <h1>
            <a href="league_details.php?id=<?= $category['league_id'] ?>">
                <?= htmlspecialchars($league_name) ?>
            </a>: 
            <?= htmlspecialchars($category['category_name']) ?>
        </h1>
    </div>

    <div class="league-info">
        <p><strong>Format:</strong> <?= htmlspecialchars($category['format_name']) ?></p>
        <p><strong>Teams:</strong> <?= htmlspecialchars($category['num_teams']) ?></p>
        <?php if (!empty($category['num_groups'])): ?>
            <p><strong>Groups:</strong> 
                <?= htmlspecialchars($category['num_groups']) ?> 
                (<?= htmlspecialchars($category['advance_per_group']) ?> advance per group)
            </p>
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
// Handle tab switching and update URL
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

// Toggle game date form visibility
function toggleDateForm(gameId) {
    const form = document.getElementById('date-form-' + gameId);
    if (form) {
        form.style.display = (form.style.display === 'none' || form.style.display === '') 
            ? 'block' : 'none';
    }
}
</script>

</body>
</html>
