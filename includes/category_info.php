<?php
// ============================================================
// File: includes/category_info.php
// Purpose: Fetches category details, format, and team data for
// use in category_details.php and related includes.
// ============================================================

require_once __DIR__ . '/../db.php'; // Ensure database connection

// Sanitize and validate category_id from URL
$category_id = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);
if (!$category_id) {
    die("Invalid category ID.");
}

// Fetch category details including league, format, and structure
$stmt = $pdo->prepare("
    SELECT
        c.league_id,
        c.category_name,
        c.playoff_seeding_locked,
        f.format_name,
        f.format_name AS tournament_format, 
        cf.format_id,
        cf.num_teams,
        cf.num_groups,
        cf.advance_per_group,
        c.schedule_generated
    FROM category c
    JOIN category_format cf ON c.id = cf.category_id
    JOIN format f ON cf.format_id = f.id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    die("Category not found.");
}

// Identify if the category format uses a bracket system
$is_bracket_format = in_array(
    strtolower($category['tournament_format']),
    ['single elimination', 'double elimination']
);

// Fetch participating teams based on format type
if ($is_bracket_format) {
    // Bracket-based formats: order teams by bracket position
    $teamStmt = $pdo->prepare("
        SELECT t.* 
        FROM team t
        JOIN bracket_positions bp ON t.id = bp.team_id
        WHERE t.category_id = ?
        ORDER BY bp.position ASC
    ");
} else {
    // Non-bracket formats: fetch all teams normally
    $teamStmt = $pdo->prepare("
        SELECT * FROM team
        WHERE category_id = ?
        ORDER BY id ASC
    ");
}

$teamStmt->execute([$category_id]);
$teams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$team_count = count($teams);

// Determine if all team slots are filled
$all_slots_filled = ($team_count >= $category['num_teams']);

// Fetch ordered bracket positions for bracket formats
$bracket_positions = [];
if ($is_bracket_format) {
    $posStmt = $pdo->prepare("
        SELECT bp.position, bp.seed, bp.team_id, t.team_name
        FROM bracket_positions bp
        LEFT JOIN team t ON bp.team_id = t.id
        WHERE bp.category_id = ?
        ORDER BY bp.position ASC
    ");
    $posStmt->execute([$category_id]);
    $bracket_positions = $posStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
