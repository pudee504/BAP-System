<?php
// Ensures the database connection is established.
require_once __DIR__ . '/../db.php';

// Sanitize the category_id from the URL to ensure it's an integer.
$category_id = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);

if (!$category_id) {
    // Stop execution if the category_id is missing or invalid.
    die("Invalid category ID.");
}

// Fetch core details about the category, its format, and its current state.
$stmt = $pdo->prepare("
    SELECT 
        c.category_name, 
        c.playoff_seeding_locked, 
        f.format_name, 
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
    // Stop execution if no category is found with the given ID.
    die("Category not found.");
}

// Determine if the category format is a bracket type for conditional rendering later.
$is_bracket_format = in_array(strtolower($category['format_name']), ['single elimination', 'double elimination']);

// Fetch all teams registered for this category, ordered by name.
$teamStmt = $pdo->prepare("SELECT * FROM team WHERE category_id = ? ORDER BY team_name ASC");
$teamStmt->execute([$category_id]);
$teams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$team_count = count($teams);

// Check if the number of registered teams has reached the maximum allowed for the category.
$all_slots_filled = ($team_count >= $category['num_teams']);

// Fetch the ordered bracket positions if it's a bracket-style tournament.
$bracket_positions = [];
if ($is_bracket_format) {
    $posStmt = $pdo->prepare("
    SELECT bp.position, bp.seed, bp.team_id, t.team_name /* <<< Added bp.seed */
    FROM bracket_positions bp
    LEFT JOIN team t ON bp.team_id = t.id
    WHERE bp.category_id = ?
    ORDER BY bp.position ASC
");
$posStmt->execute([$category_id]);
$bracket_positions = $posStmt->fetchAll(PDO::FETCH_ASSOC); 
}
?>
