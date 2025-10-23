<?php
session_start();
require 'db.php'; // Your database connection file

// --- Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['category_id']) || !isset($_POST['lock_status'])) {
    header('Location: index.php'); // Redirect if accessed improperly
    exit;
}

$category_id = $_POST['category_id'];
$new_lock_status = (int)$_POST['lock_status']; // 1 for lock, 0 for unlock
$redirect_url = "category_details.php?category_id={$category_id}&tab=standings";

try {
    if ($new_lock_status === 0) { 
        // --- UNLOCKING LOGIC ---
        
        // 1. Safety Check: Ensure no games are completed.
        $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
        $gamesPlayedStmt->execute([$category_id]);
        if ($gamesPlayedStmt->fetchColumn() > 0) {
            $_SESSION['swap_message'] = 'Error: Cannot unlock groups because one or more games have been completed.';
            header("Location: {$redirect_url}");
            exit;
        }

        // 2. Perform actions in a transaction for safety
        $pdo->beginTransaction();

        // 2a. Delete all games for this category
        $deleteStmt = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
        $deleteStmt->execute([$category_id]);

        // 2b. Clear the cluster_standing table for this category
        $clearStandings = $pdo->prepare("
            DELETE cs FROM cluster_standing cs
            JOIN cluster c ON cs.cluster_id = c.id
            WHERE c.category_id = ?
        ");
        $clearStandings->execute([$category_id]);

        // --- THIS WAS THE PROBLEM LINE: IT IS NOW REMOVED ---
        // $clearClusters = $pdo->prepare("UPDATE team SET cluster_id = NULL WHERE category_id = ?");
        // $clearClusters->execute([$category_id]);
        // --- END OF REMOVED CODE ---

        // 2c. Update category to unlock groups and reset schedule flag
        $updateStmt = $pdo->prepare("UPDATE category SET groups_locked = 0, schedule_generated = 0 WHERE id = ?");
        $updateStmt->execute([$category_id]);
        
        $pdo->commit();
        // Updated the message to be more accurate
        $_SESSION['swap_message'] = 'Groups have been unlocked. Schedule and standings have been cleared.';

    } else { 
        // --- LOCKING LOGIC ---
        
        $pdo->beginTransaction();

        // 1. Lock the category
        $stmt = $pdo->prepare("UPDATE category SET groups_locked = 1 WHERE id = ?");
        $stmt->execute([$category_id]);

        // 2. Get all clusters for this category
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $clusters = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($clusters)) {
            throw new Exception("No groups (clusters) have been created for this category.");
        }
        $num_groups = count($clusters);

        // 3. Get all teams for this category that DON'T have a cluster yet
        $teamsStmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? AND cluster_id IS NULL ORDER BY id ASC");
        $teamsStmt->execute([$category_id]);
        $teams = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($teams)) {
            // This happens if teams are already assigned, so we just re-initialize standings
        } else {
            // 4. Distribute teams into clusters (round-robin)
            $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
            foreach ($teams as $index => $team_id) {
                $cluster_id = $clusters[$index % $num_groups];
                $updateTeamStmt->execute([$cluster_id, $team_id]);
            }
        }
        
        // 5. Now, initialize the cluster_standing table
        
        // 5a. Clear any old standings
        $clearStmt = $pdo->prepare("
            DELETE cs FROM cluster_standing cs
            JOIN cluster c ON cs.cluster_id = c.id
            WHERE c.category_id = ?
        ");
        $clearStmt->execute([$category_id]);

        // 5b. Get ALL teams again, this time with their newly assigned cluster_id
        $allTeamsStmt = $pdo->prepare("SELECT id, cluster_id FROM team WHERE category_id = ? AND cluster_id IS NOT NULL");
        $allTeamsStmt->execute([$category_id]);
        $allTeams = $allTeamsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allTeams)) {
             throw new Exception("No teams found to initialize standings.");
        }

        // 5c. INSERT a new '0' row for each team
        $insertSql = "
            INSERT INTO cluster_standing 
                (cluster_id, team_id, matches_played, wins, losses, point_scored, points_allowed)
            VALUES 
                (?, ?, 0, 0, 0, 0, 0)
        ";
        $insertStmt = $pdo->prepare($insertSql);
        
        foreach ($allTeams as $team) {
            $insertStmt->execute([$team['cluster_id'], $team['id']]);
        }
        
        $pdo->commit();
        $_SESSION['swap_message'] = 'Groups have been successfully locked and standings table initialized.';
    }
} catch (Exception $e) {
    // If anything goes wrong, roll back the transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['swap_message'] = 'Error: ' . $e->getMessage();
}

// Redirect back to the standings page
header("Location: {$redirect_url}");
exit;
?>