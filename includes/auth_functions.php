<?php
// includes/auth_functions.php

/**
 * Checks if a user has management permissions for a league or its sub-entities.
 *
 * This function verifies permissions by first checking if the user is an Admin,
 * who has universal access. If not, it finds the parent league for the given
 * entity (e.g., a category or team) and then checks if the user is an assigned
 * manager for that specific league.
 *
 * @param PDO $pdo The database connection object.
 * @param int $user_id The ID of the user whose permissions are being checked.
 * @param string $entity_type The type of entity ('league', 'category', 'team', 'player').
 * @param int $entity_id The ID of the entity.
 * @return bool Returns true if the user has permission, false otherwise.
 */
function has_league_permission($pdo, $user_id, $entity_type, $entity_id) {
    // 1. First, check the user's role. Admins have permission for everything.
    $stmt_role = $pdo->prepare("SELECT r.role_name FROM users u JOIN role r ON u.role_id = r.id WHERE u.id = ?");
    $stmt_role->execute([$user_id]);
    $role = $stmt_role->fetchColumn();
    if ($role === 'Admin') {
        return true;
    }

    // 2. If the user is not an Admin, find the league_id associated with the given entity.
    $league_id = null;
    switch ($entity_type) {
        case 'league':
            $league_id = $entity_id;
            break;
        case 'category':
            $stmt = $pdo->prepare("SELECT league_id FROM category WHERE id = ?");
            $stmt->execute([$entity_id]);
            $league_id = $stmt->fetchColumn();
            break;
        case 'team':
            $stmt = $pdo->prepare("SELECT c.league_id FROM team t JOIN category c ON t.category_id = c.id WHERE t.id = ?");
            $stmt->execute([$entity_id]);
            $league_id = $stmt->fetchColumn();
            break;
        case 'player':
             $stmt = $pdo->prepare("
                SELECT c.league_id
                FROM player p
                JOIN team t ON p.team_id = t.id
                JOIN category c ON t.category_id = c.id
                WHERE p.id = ?
             ");
             $stmt->execute([$entity_id]);
             $league_id = $stmt->fetchColumn();
             break;
        case 'game':
            $stmt = $pdo->prepare("
                SELECT c.league_id
                FROM game g
                JOIN category c ON g.category_id = c.id
                WHERE g.id = ?
            ");
            $stmt->execute([$entity_id]);
            $league_id = $stmt->fetchColumn();
            break;
    }

    if (!$league_id) {
        // If no league is associated with the entity (e.g., invalid ID), deny permission.
        return false;
    }

    // 3. Check if the user is assigned as a manager to that specific league.
    $stmt_permission = $pdo->prepare("
        SELECT COUNT(*)
        FROM league_manager_assignment
        WHERE user_id = ? AND league_id = ?
    ");
    $stmt_permission->execute([$user_id, $league_id]);

    // If the count is greater than 0, the user has permission.
    return $stmt_permission->fetchColumn() > 0;
}
