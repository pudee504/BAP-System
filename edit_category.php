<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

$category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$league_id = filter_var($_GET['league_id'] ?? null, FILTER_VALIDATE_INT);
if (!$category_id || !$league_id) {
    log_action('EDIT_CATEGORY', 'FAILURE', 'Attempted to access edit page with an invalid ID.');
    die("Invalid request.");
}

// Fetch current category data
$stmt = $pdo->prepare("SELECT * FROM category WHERE id = ?");
$stmt->execute([$category_id]);
$category = $stmt->fetch();

if (!$category) {
    log_action('EDIT_CATEGORY', 'FAILURE', "Attempted to edit a non-existent category (ID: {$category_id}).");
    die("Category not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name'] ?? '');

    if ($category_name) {
        try {
            $update = $pdo->prepare("UPDATE category SET category_name = ? WHERE id = ?");
            $update->execute([$category_name, $category_id]);

            // --- DETAILED SUCCESS LOGGING ---
            if ($category['category_name'] !== $category_name) {
                $log_details = "Updated category in league ID {$league_id}: changed name from '{$category['category_name']}' to '{$category_name}' (Category ID: {$category_id}).";
                log_action('UPDATE_CATEGORY', 'SUCCESS', $log_details);
            } else {
                $log_details = "Submitted update for category '{$category['category_name']}' (ID: {$category_id}) with no changes.";
                log_action('UPDATE_CATEGORY', 'INFO', $log_details);
            }
        } catch (PDOException $e) {
            $log_details = "Database error updating category '{$category['category_name']}' (ID: {$category_id}). Error: " . $e->getMessage();
            log_action('UPDATE_CATEGORY', 'FAILURE', $log_details);
            die("A database error occurred.");
        }

        header("Location: league_details.php?id=$league_id");
        exit;
    } else {
        $error = "Category name is required.";
        // --- FAILURE LOGGING ---
        $log_details = "Failed to update category (ID: {$category_id}) due to a missing name.";
        log_action('UPDATE_CATEGORY', 'FAILURE', $log_details);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Category</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
<div class="form-container">
    <h1>Edit Category</h1>

    <?php if (!empty($error)): ?>
        <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="edit_category.php?id=<?= $category_id ?>&league_id=<?= $league_id ?>">
        <div class="form-group">
            <label for="category_name">Category Name</label>
            <input type="text" id="category_name" name="category_name" value="<?= htmlspecialchars($category['category_name']) ?>" required>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Category</button>
        </div>
    </form>
</div>
</body>
</html>
