<?php

session_start();
require_once 'db.php';
require_once 'logger.php';
require_once 'includes/double_elim_logic.php';
require_once 'schedule_helpers.php'; // Helper for naming and timer initialization

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Error: Missing category_id.");
}

$pdo->beginTransaction();
try {
    // --- 1. VALIDATE CATEGORY AND LOCK STATUS ---
    $catStmt = $pdo->prepare("SELECT category_name, playoff_seeding_locked FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);

    // Must have a valid and locked bracket before generation
    if (!$category || !$category['playoff_seeding_locked']) {
        die("Cannot generate schedule: The bracket is not locked.");
    }

    // Remove any previous games under this category to start fresh
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // --- 2. FETCH TEAMS FROM BRACKET POSITIONS ---
    $teams_query = $pdo->prepare("
        SELECT bp.seed, t.id AS team_id, t.team_name, bp.position 
        FROM bracket_positions bp 
        JOIN team t ON bp.team_id = t.id 
        WHERE bp.category_id = ? 
        ORDER BY bp.position ASC
    ");
    $teams_query->execute([$category_id]);
    $results = $teams_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize teams by bracket position
    $teams_by_position = [];
    foreach ($results as $row) {
        $teams_by_position[$row['position']] = [
            'id' => $row['team_id'],
            'name' => $row['team_name'],
            'seed' => $row['seed'],
            'pos' => $row['position']
        ];
    }
    
    // Generate all match data using helper logic
    $bracket_data = generate_double_elimination_matches($teams_by_position);
    if (!$bracket_data) {
        throw new Exception("Could not generate bracket data for " . count($teams_by_position) . " teams.");
    }
    
    $all_matches = $bracket_data['all_matches'];
    $bracket_size = 2 ** ceil(log(count($teams_by_position), 2));
    
    // --- 3. INSERT GAMES INTO DATABASE ---
    $temp_id_to_db_id = [];
    $insertGameStmt = $pdo->prepare("
        INSERT INTO game (category_id, round, round_name, bracket_type, hometeam_id, awayteam_id, game_status)
        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
    ");

    foreach ($all_matches as $temp_id => $match) {
        // Use helper to assign correct round name
        $round_name = ($match['bracket_type'] === 'grand_final') 
            ? $match['round_name']
            : getRoundName($bracket_size, $match['bracket_type'], $match['round']);
        
        // Skip placeholder teams (TBD)
        $hometeam_id = !isset($match['home']['is_placeholder']) ? ($match['home']['id'] ?? null) : null;
        $awayteam_id = !isset($match['away']['is_placeholder']) ? ($match['away']['id'] ?? null) : null;
        
        // Insert game into DB
        $insertGameStmt->execute([$category_id, $match['round'], $round_name, $match['bracket_type'], $hometeam_id, $awayteam_id]);
        
        // Get new game ID and store mapping
        $new_game_id = $pdo->lastInsertId();

        // Initialize timers for each new game
        initialize_game_timer($pdo, $new_game_id);

        $temp_id_to_db_id[$temp_id] = $new_game_id;
    }

    // --- 4. LINK WINNERS AND LOSERS TO NEXT MATCHES ---
    $updateWinnerStmt = $pdo->prepare("UPDATE game SET winner_advances_to_game_id = ?, winner_advances_to_slot = ? WHERE id = ?");
    $updateLoserStmt = $pdo->prepare("UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = ? WHERE id = ?");

    foreach ($all_matches as $temp_id => $match) {
        // Find where winner goes
        $winner_feeds_info = find_feeder_destination($all_matches, $temp_id, 'winner');
        if ($winner_feeds_info) {
            $source_db_id = $temp_id_to_db_id[$temp_id];
            $dest_db_id = $temp_id_to_db_id[$winner_feeds_info['dest_id']];
            $updateWinnerStmt->execute([$dest_db_id, $winner_feeds_info['slot'], $source_db_id]);
        }

        // Find where loser goes
        $loser_feeds_info = find_feeder_destination($all_matches, $temp_id, 'loser');
        if ($loser_feeds_info) {
            $source_db_id = $temp_id_to_db_id[$temp_id];
            $dest_db_id = $temp_id_to_db_id[$loser_feeds_info['dest_id']];
            $updateLoserStmt->execute([$dest_db_id, $loser_feeds_info['slot'], $source_db_id]);
        }
    }

    // --- 5. MARK SCHEDULE AS GENERATED ---
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    $pdo->commit();
    
    // Log success and redirect to schedule tab
    log_action('GENERATE_SCHEDULE', 'SUCCESS', "Generated consistent Double Elim schedule for category '{$category['category_name']}'.");
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    // Rollback and log failure on any error
    $pdo->rollBack();
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred while generating the schedule: " . $e->getMessage());
}

// --- Helper: Find where a match winner/loser advances to ---
function find_feeder_destination($all_matches, $source_id, $source_slot_type) {
    foreach ($all_matches as $dest_id => $match) {
        // Check home slot linkage
        if (isset($match['home']['source_match_id']) && $match['home']['source_match_id'] == $source_id && $match['home']['source_slot'] == $source_slot_type) {
            return ['dest_id' => $dest_id, 'slot' => 'home'];
        }
        // Check away slot linkage
        if (isset($match['away']['source_match_id']) && $match['away']['source_match_id'] == $source_id && $match['away']['source_slot'] == $source_slot_type) {
            return ['dest_id' => $dest_id, 'slot' => 'away'];
        }
    }
    return null;
}
?>
