<?php
// FILENAME: lock_bracket.php
// DESCRIPTION: Locks the bracket seeding (sets `playoff_seeding_locked` flag to 1) for a single-elimination category,
// but only after verifying that all team slots have been filled.

session_start();
require_once 'db.php'; 
require_once 'logger.php'; 
require_once 'includes/auth_functions.php';

// --- 1. VALIDATE INPUT ---
// Get category ID from the POST request.
$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    // Redirect with error if category ID is missing.
    header("Location: category_details.php?category_id=" . ($category_id ?? '') . "&tab=standings&error=missing_id");
    exit;
}

// --- Authorization Check ---
if (!isset($_SESSION['user_id']) || !has_league_permission($pdo, $_SESSION['user_id'], 'category', $category_id)) {
    $_SESSION['error'] = 'You do not have permission to lock this bracket.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for category {$category_id} on lock_bracket.php");
    header('Location: dashboard.php');
    exit;
}

try {
    // --- 2. VERIFY ALL TEAMS ARE PRESENT ---
    // Check if the current number of teams matches the required number for the category.
    $stmt = $pdo->prepare("
        SELECT cf.num_teams, COUNT(t.id) as team_count 
        FROM category_format cf 
        LEFT JOIN team t ON cf.category_id = t.category_id 
        WHERE cf.category_id = ? 
        GROUP BY cf.num_teams
    ");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redirect with error if the team count is insufficient.
    if (!$result || $result['team_count'] < $result['num_teams']) {
         header("Location: category_details.php?category_id=$category_id&tab=standings&error=not_full");
         exit;
    }

    // --- 3. PERFORM THE UPDATE ---
    // Set the `playoff_seeding_locked` flag to 1 for the category.
    $updateStmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 1 WHERE id = ?");
    $updateStmt->execute([$category_id]);

    // Log the successful lock action.
    log_action('LOCK_BRACKET', 'SUCCESS', "Bracket has been successfully locked for category ID: {$category_id}.");

    // --- 4. REDIRECT ON SUCCESS ---
    // Redirect back to the standings tab with a success indicator.
    header("Location: category_details.php?category_id=$category_id&tab=standings&success=locked");
    exit;

} catch (Exception $e) {
    // Handle database errors during the process.
    log_action('LOCK_BRACKET', 'FAILURE', "Failed to lock bracket for category ID {$category_id}. Error: " . $e->getMessage());
    header("Location: category_details.php?category_id=$category_id&tab=standings&error=db_error");
    exit;
}
?>