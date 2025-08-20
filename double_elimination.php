<?php
require_once 'db.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

// Start a transaction for an all-or-nothing operation
$pdo->beginTransaction();

try {
    // Check if schedule is already generated
    $check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ? FOR UPDATE");
    $check->execute([$category_id]);
    if ($check->fetchColumn()) {
        $pdo->rollBack();
        header("Location: category_details.php?id=$category_id&tab=schedule&error=already_generated");
        exit;
    }

    // Get teams ordered by seed
    $stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY seed ASC, id ASC");
    $stmt->execute([$category_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $total_teams = count($teams);

    // This script currently supports 4 or 8 teams
    if (!in_array($total_teams, [4, 8])) {
        $pdo->rollBack();
        die("Only 4 or 8 teams are supported for this double-elimination script.");
    }

    // Clear any old games for this category
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // Prepare a reusable insert statement
    $insertGameStmt = $pdo->prepare(
        "INSERT INTO game (category_id, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, 'Upcoming')"
    );

    // =================================================================
    //  BRACKET LOGIC FOR 4 TEAMS
    // =================================================================
    if ($total_teams == 4) {
        // --- STEP 1: Create all 6 games and get their IDs ---
        $insertGameStmt->execute([$category_id, "Upper Semifinal 1", $teams[0], $teams[1]]);
        $ub_semi1_id = $pdo->lastInsertId();

        $insertGameStmt->execute([$category_id, "Upper Semifinal 2", $teams[2], $teams[3]]);
        $ub_semi2_id = $pdo->lastInsertId();
        
        $insertGameStmt->execute([$category_id, "Lower Semifinal", null, null]);
        $lb_semi_id = $pdo->lastInsertId();

        $insertGameStmt->execute([$category_id, "Upper Final", null, null]);
        $ub_final_id = $pdo->lastInsertId();

        $insertGameStmt->execute([$category_id, "Lower Final", null, null]);
        $lb_final_id = $pdo->lastInsertId();

        $insertGameStmt->execute([$category_id, "Grand Final", null, null]);
        $grand_final_id = $pdo->lastInsertId();

        // --- STEP 2: Link the bracket using the IDs ---
        $updateStmt = $pdo->prepare(
            "UPDATE game SET 
                winner_advances_to_game_id = :win_game, winner_advances_to_slot = :win_slot,
                loser_advances_to_game_id = :lose_game, loser_advances_to_slot = :lose_slot
             WHERE id = :game_id"
        );

        // Link Upper Semifinal 1
        $updateStmt->execute(['win_game' => $ub_final_id, 'win_slot' => 'home', 'lose_game' => $lb_semi_id, 'lose_slot' => 'home', 'game_id' => $ub_semi1_id]);
        
        // Link Upper Semifinal 2
        $updateStmt->execute(['win_game' => $ub_final_id, 'win_slot' => 'away', 'lose_game' => $lb_semi_id, 'lose_slot' => 'away', 'game_id' => $ub_semi2_id]);
        
        // Link Lower Semifinal
        $updateStmt->execute(['win_game' => $lb_final_id, 'win_slot' => 'away', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_semi_id]);
        
        // Link Upper Final
        $updateStmt->execute(['win_game' => $grand_final_id, 'win_slot' => 'home', 'lose_game' => $lb_final_id, 'lose_slot' => 'home', 'game_id' => $ub_final_id]);
        
        // Link Lower Final
        $updateStmt->execute(['win_game' => $grand_final_id, 'win_slot' => 'away', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_final_id]);
    }
    // =================================================================
    //  BRACKET LOGIC FOR 8 TEAMS
    // =================================================================
    elseif ($total_teams == 8) {
        // --- STEP 1: Create all 14 games and get their IDs ---
        // Upper Bracket
        $insertGameStmt->execute([$category_id, "Upper Quarterfinal 1", $teams[0], $teams[1]]); $ub_qf1_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Upper Quarterfinal 2", $teams[2], $teams[3]]); $ub_qf2_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Upper Quarterfinal 3", $teams[4], $teams[5]]); $ub_qf3_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Upper Quarterfinal 4", $teams[6], $teams[7]]); $ub_qf4_id = $pdo->lastInsertId();
        
        $insertGameStmt->execute([$category_id, "Upper Semifinal 1", null, null]); $ub_sf1_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Upper Semifinal 2", null, null]); $ub_sf2_id = $pdo->lastInsertId();
        
        $insertGameStmt->execute([$category_id, "Upper Final", null, null]); $ub_final_id = $pdo->lastInsertId();

        // Lower Bracket
        $insertGameStmt->execute([$category_id, "Lower Round 1, Game 1", null, null]); $lb_r1_g1_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Lower Round 1, Game 2", null, null]); $lb_r1_g2_id = $pdo->lastInsertId();

        $insertGameStmt->execute([$category_id, "Lower Round 2, Game 1", null, null]); $lb_r2_g1_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Lower Round 2, Game 2", null, null]); $lb_r2_g2_id = $pdo->lastInsertId();
        
        $insertGameStmt->execute([$category_id, "Lower Semifinal", null, null]); $lb_semi_id = $pdo->lastInsertId();
        $insertGameStmt->execute([$category_id, "Lower Final", null, null]); $lb_final_id = $pdo->lastInsertId();

        // Grand Final
        $insertGameStmt->execute([$category_id, "Grand Final", null, null]); $grand_final_id = $pdo->lastInsertId();

        // --- STEP 2: Link the bracket using the IDs ---
        $updateStmt = $pdo->prepare(
            "UPDATE game SET 
                winner_advances_to_game_id = :win_game, winner_advances_to_slot = :win_slot,
                loser_advances_to_game_id = :lose_game, loser_advances_to_slot = :lose_slot
             WHERE id = :game_id"
        );

        // Link Upper Quarterfinals
        $updateStmt->execute(['win_game' => $ub_sf1_id, 'win_slot' => 'home', 'lose_game' => $lb_r1_g1_id, 'lose_slot' => 'home', 'game_id' => $ub_qf1_id]);
        $updateStmt->execute(['win_game' => $ub_sf1_id, 'win_slot' => 'away', 'lose_game' => $lb_r1_g1_id, 'lose_slot' => 'away', 'game_id' => $ub_qf2_id]);
        $updateStmt->execute(['win_game' => $ub_sf2_id, 'win_slot' => 'home', 'lose_game' => $lb_r1_g2_id, 'lose_slot' => 'home', 'game_id' => $ub_qf3_id]);
        $updateStmt->execute(['win_game' => $ub_sf2_id, 'win_slot' => 'away', 'lose_game' => $lb_r1_g2_id, 'lose_slot' => 'away', 'game_id' => $ub_qf4_id]);
        
        // Link Upper Semifinals
        $updateStmt->execute(['win_game' => $ub_final_id, 'win_slot' => 'home', 'lose_game' => $lb_r2_g1_id, 'lose_slot' => 'away', 'game_id' => $ub_sf1_id]);
        $updateStmt->execute(['win_game' => $ub_final_id, 'win_slot' => 'away', 'lose_game' => $lb_r2_g2_id, 'lose_slot' => 'away', 'game_id' => $ub_sf2_id]);

        // Link Upper Final
        $updateStmt->execute(['win_game' => $grand_final_id, 'win_slot' => 'home', 'lose_game' => $lb_final_id, 'lose_slot' => 'home', 'game_id' => $ub_final_id]);
        
        // Link Lower Bracket Round 1
        $updateStmt->execute(['win_game' => $lb_r2_g1_id, 'win_slot' => 'home', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_r1_g1_id]);
        $updateStmt->execute(['win_game' => $lb_r2_g2_id, 'win_slot' => 'home', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_r1_g2_id]);
        
        // Link Lower Bracket Round 2
        $updateStmt->execute(['win_game' => $lb_semi_id, 'win_slot' => 'home', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_r2_g1_id]);
        $updateStmt->execute(['win_game' => $lb_semi_id, 'win_slot' => 'away', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_r2_g2_id]);
        
        // Link Lower Semifinal
        $updateStmt->execute(['win_game' => $lb_final_id, 'win_slot' => 'away', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_semi_id]);

        // Link Lower Final
        $updateStmt->execute(['win_game' => $grand_final_id, 'win_slot' => 'away', 'lose_game' => null, 'lose_slot' => null, 'game_id' => $lb_final_id]);
    }
    
    // Mark schedule as generated
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    
    // If we get here, everything is successful. Commit the changes.
    $pdo->commit();

    // Redirect back to the details page
    // TO:
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    // If any error occurred, roll back all database changes
    $pdo->rollBack();
    die("An error occurred while generating the double elimination schedule: " . $e->getMessage());
}