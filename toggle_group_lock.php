<?php
// FILENAME: toggle_group_lock.php
// DESCRIPTION: Locks or unlocks group assignments for a Round Robin category.
// Locking assigns teams to groups (if not already done) and initializes standings.
// Unlocking clears the schedule and standings IF no games have been completed.

session_start();
require 'db.php'; // Database connection

// --- 1. Validation ---
// Ensure it's a POST request with necessary data.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['category_id']) || !isset($_POST['lock_status'])) {
    header('Location: index.php'); // Redirect if accessed improperly
    exit;
}

$category_id = $_POST['category_id'];
$new_lock_status = (int)$_POST['lock_status']; // 1 for lock, 0 for unlock
$redirect_url = "category_details.php?category_id={$category_id}&tab=standings"; // Redirect back here

try {
    if ($new_lock_status === 0) {
        // --- UNLOCKING LOGIC ---
        
        // 1. Safety Check: Prevent unlock if games are finished.
        $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
        $gamesPlayedStmt->execute([$category_id]);
        if ($gamesPlayedStmt->fetchColumn() > 0) {
            $_SESSION['swap_message'] = 'Error: Cannot unlock groups because one or more games have been completed.';
            header("Location: {$redirect_url}");
            exit;
        }

        // 2. Perform unlock actions within a transaction.
        $pdo->beginTransaction();

        // 2a. Delete all games for this category.
        $deleteStmt = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
        $deleteStmt->execute([$category_id]);

        // 2b. Clear associated standings data.
        $clearStandings = $pdo->prepare("
            DELETE cs FROM cluster_standing cs
            JOIN cluster c ON cs.cluster_id = c.id
            WHERE c.category_id = ?
        ");
        $clearStandings->execute([$category_id]);
        // NOTE: We keep team cluster assignments (team.cluster_id) intact upon unlock.

        // 2c. Update category flags: unlock groups, reset schedule generated status.
        $updateStmt = $pdo->prepare("UPDATE category SET groups_locked = 0, schedule_generated = 0 WHERE id = ?");
        $updateStmt->execute([$category_id]);
        
        $pdo->commit();
        $_SESSION['swap_message'] = 'Groups have been unlocked. Schedule and standings have been cleared.';
        // TODO: Add logging for unlock success.

    } else {
        // --- LOCKING LOGIC ---
        
        $pdo->beginTransaction();

        // 1. Lock the category (`groups_locked = 1`).
        $stmt = $pdo->prepare("UPDATE category SET groups_locked = 1 WHERE id = ?");
        $stmt->execute([$category_id]);

        // 2. Get clusters (groups) for this category.
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $clusters = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($clusters)) {
            throw new Exception("No groups (clusters) have been created for this category.");
        }
        $num_groups = count($clusters);

        // 3. Get teams that haven't been assigned to a cluster yet.
        $teamsStmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? AND cluster_id IS NULL ORDER BY id ASC");
        $teamsStmt->execute([$category_id]);
        $teams_to_assign = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. Distribute unassigned teams into clusters (round-robin assignment).
        if (!empty($teams_to_assign)) {
            $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
            foreach ($teams_to_assign as $index => $team_id) {
                $cluster_id = $clusters[$index % $num_groups]; // Cycle through clusters
                $updateTeamStmt->execute([$cluster_id, $team_id]);
            }
        }
        
        // 5. Initialize the `cluster_standing` table for ALL teams in the category.
        
        // 5a. Clear any potentially old/stale standings first.
        $clearStmt = $pdo->prepare("
            DELETE cs FROM cluster_standing cs
            JOIN cluster c ON cs.cluster_id = c.id
            WHERE c.category_id = ?
        ");
        $clearStmt->execute([$category_id]);

        // 5b. Get all teams (now guaranteed to have a cluster_id).
        $allTeamsStmt = $pdo->prepare("SELECT id, cluster_id FROM team WHERE category_id = ? AND cluster_id IS NOT NULL");
        $allTeamsStmt->execute([$category_id]);
        $allTeams = $allTeamsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allTeams)) {
             throw new Exception("No teams found to initialize standings.");
        }

        // 5c. Insert a starting row (all zeros) for each team into `cluster_standing`.
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
        // TODO: Add logging for lock success.
    }
} catch (Exception $e) {
    // Roll back transaction on any error.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['swap_message'] = 'Error: ' . $e->getMessage();
     // TODO: Add logging for failure, include $e->getMessage().
}

// Redirect back to the standings page.
header("Location: {$redirect_url}");
exit;
?>