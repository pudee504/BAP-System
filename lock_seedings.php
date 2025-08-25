// --- Example logic for lock_bracket.php ---
<?php
require_once 'db.php'; // Adjust path as needed

$category_id = $_POST['category_id'] ?? null;

if ($category_id) {
    $pdo->beginTransaction();
    try {
        // 1. Get all current positions for the category, ordered correctly.
        $stmt = $pdo->prepare(
            "SELECT id FROM bracket_positions WHERE category_id = ? ORDER BY position ASC"
        );
        $stmt->execute([$category_id]);
        $position_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Prepare the UPDATE statement.
        $updateStmt = $pdo->prepare(
            "UPDATE bracket_positions SET seed = ? WHERE id = ?"
        );

        // 3. Loop through them and assign a new, sequential seed.
        $current_seed = 1;
        foreach ($position_rows as $row) {
            $updateStmt->execute([$current_seed, $row['id']]);
            $current_seed++;
        }
        
        // 4. Update the category to mark it as locked.
        $lockStmt = $pdo->prepare(
            "UPDATE category SET playoff_seeding_locked = 1 WHERE id = ?"
        );
        $lockStmt->execute([$category_id]);

        $pdo->commit();
        // Redirect back to the details page
        header("Location: ../category_details.php?category_id=$category_id&tab=standings&success=locked");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error locking bracket: " . $e->getMessage());
    }
}
?>