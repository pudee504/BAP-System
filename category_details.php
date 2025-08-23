<?php
session_start();
require 'includes/category_info.php';

// Validate category ID from the URL query string.
$category_id = $_GET['category_id'] ?? '';
if (!$category_id) {
    // If no category_id is provided, stop execution.
    die("Invalid category ID.");
}

// Determine the active tab from the URL, defaulting to 'teams'.
$active_tab = $_GET['tab'] ?? 'teams';
$valid_tabs = ['teams', 'schedule', 'standings'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'teams'; // Default to 'teams' if the tab in the URL is invalid.
}

// Fetch category status to check if the schedule has been generated or the bracket is locked.
$check = $pdo->prepare("SELECT schedule_generated, playoff_seeding_locked FROM category WHERE id = ?");
$check->execute([$category_id]);
$categoryInfo = $check->fetch(PDO::FETCH_ASSOC);
$scheduleGenerated = $categoryInfo['schedule_generated'] ?? false;
// This variable checks if the bracket positions have been locked by the user.
$bracketLocked = $categoryInfo['playoff_seeding_locked'] ?? false;

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
    <title>Category Details</title>
    <?php include 'includes/head_styles.php'; ?>
    <!-- Include SortableJS library for drag-and-drop functionality -->
    <script src="js/Sortable.min.js"></script>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <h1>Category: <?= htmlspecialchars($category['category_name']) ?></h1>
    <p><strong>Format:</strong> <?= htmlspecialchars($category['format_name']) ?></p>
    <p><strong>Number of Teams:</strong> <?= $category['num_teams'] ?></p>
    
    <?php if ($category['num_groups']): ?>
        <p><strong>Groups:</strong> <?= $category['num_groups'] ?> (<?= $category['advance_per_group'] ?> advance per group)</p>
    <?php endif; ?>

    <!-- Navigation tabs for different sections -->
    <?php include 'includes/category_tabs.php'; ?>

    <!-- Container for the content of the active tab -->
    <div class="tab-container">
        <?php include 'includes/category_tabs_teams.php'; ?>
        <?php include 'includes/category_tabs_schedule.php'; ?>
        <?php include 'includes/category_tabs_standings.php'; ?>
    </div>
</div>

<script>
// JavaScript to handle tab switching functionality.
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', (e) => {
        e.preventDefault();
        
        // Remove 'active' class from all tabs and tab contents.
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
        
        // Add 'active' class to the clicked tab and its corresponding content.
        tab.classList.add('active');
        const tabId = tab.getAttribute('data-tab');
        document.getElementById(tabId).classList.add('active');
        
        // Update the URL with the new tab ID without reloading the page.
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
    });
});

// Toggles the visibility of the form to set a game's date.
function toggleDateForm(gameId) {
    const form = document.getElementById('date-form-' + gameId);
    if (form) {
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }
}


</script>

</body>
</html>
